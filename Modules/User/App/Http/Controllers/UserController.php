<?php

namespace Modules\User\App\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Modules\Auth\App\Models\User;
use Modules\Auth\App\Resources\UserResource;

class UserController extends Controller
{
    private const OPTIONAL_USER_COLUMNS = [
        'email', 'national_code', 'personnel_code', 'is_active', 'employee_type', 'party_id',
    ];

    public function index(Request $request)
    {
        $query = User::query()->with('roles')->orderByDesc('id');

        $this->applySearch($query, $request->string('search')->toString());

        if ($request->filled('employee_type') && Schema::hasColumn('users', 'employee_type')) {
            $query->where('employee_type', $request->input('employee_type'));
        }

        if (($request->boolean('active_only') || $request->has('is_active')) && Schema::hasColumn('users', 'is_active')) {
            $query->where('is_active', $request->boolean('active_only') ? true : $request->boolean('is_active'));
        }

        if ($request->filled('role')) {
            $role = $request->string('role')->toString();
            $query->whereHas('roles', fn (Builder $roles) => $roles->where('name', $role));
        }

        $perPage = min(max($request->integer('per_page', 15), 1), 100);
        $users = $query->paginate($perPage);
        $this->attachOrganizationalUnits(collect($users->items()));

        return response()->json([
            'success' => true,
            'data' => collect($users->items())->map(fn (User $user) => (new UserResource($user))->resolve($request))->values(),
            'meta' => [
                'current_page' => $users->currentPage(),
                'last_page' => $users->lastPage(),
                'per_page' => $users->perPage(),
                'total' => $users->total(),
            ],
        ]);
    }

    public function show(Request $request, User $user)
    {
        $user->load('roles');
        $this->attachOrganizationalUnits(collect([$user]));

        return response()->json([
            'success' => true,
            'data' => (new UserResource($user))->resolve($request),
        ]);
    }

    public function selectable(Request $request)
    {
        $query = User::query()->with('roles')->orderBy('name');
        $this->applySearch($query, $request->string('search')->toString());

        if (Schema::hasColumn('users', 'is_active')) {
            $query->where('is_active', true);
        }

        if ($request->filled('exclude_group') && Schema::hasTable('group_user')) {
            $groupId = $request->integer('exclude_group');
            $query->whereNotIn('users.id', DB::table('group_user')->where('group_id', $groupId)->select('user_id'));
        }

        $unitFilterAvailable = Schema::hasTable('contract_unit_signers')
            && Schema::hasTable('organizational_units')
            && Schema::hasColumn('contract_unit_signers', 'user_id')
            && Schema::hasColumn('contract_unit_signers', 'unit_id');

        if ($request->filled('unit_id') && $unitFilterAvailable) {
            $query->whereIn('users.id', DB::table('contract_unit_signers')
                ->where('unit_id', $request->integer('unit_id'))
                ->select('user_id'));
        }

        if ($request->filled('position_search') && $unitFilterAvailable && Schema::hasColumn('contract_unit_signers', 'unit_role')) {
            $position = $request->string('position_search')->toString();
            $query->whereIn('users.id', DB::table('contract_unit_signers')
                ->where('unit_role', 'like', "%{$position}%")
                ->select('user_id'));
        }

        $users = $query->limit(min(max($request->integer('per_page', 200), 1), 500))->get();
        $this->attachOrganizationalUnits($users);

        return response()->json([
            'success' => true,
            'data' => $users->map(function (User $user) use ($request) {
                $data = (new UserResource($user))->resolve($request);
                $position = $user->getAttribute('position_title');
                $data['post_title'] = $position;
                $data['job_title'] = $position;
                return $data;
            })->values(),
        ]);
    }

    public function positions()
    {
        if (!Schema::hasTable('contract_unit_signers') || !Schema::hasColumn('contract_unit_signers', 'unit_role')) {
            return response()->json(['success' => true, 'data' => []]);
        }

        $positions = DB::table('contract_unit_signers')
            ->whereNotNull('unit_role')
            ->where('unit_role', '<>', '')
            ->distinct()
            ->orderBy('unit_role')
            ->pluck('unit_role')
            ->values();

        return response()->json(['success' => true, 'data' => $positions]);
    }

    public function store(Request $request)
    {
        $validated = $this->validateUser($request);
        $this->validateContractorParty($validated);

        $user = DB::transaction(function () use ($validated) {
            $attributes = $this->supportedUserAttributes([
                'name' => $validated['name'],
                'mobile' => $validated['mobile'],
                'email' => $validated['email'] ?? null,
                'password' => $validated['password'],
                'national_code' => $validated['national_code'] ?? null,
                'personnel_code' => $validated['personnel_code'] ?? null,
                'employee_type' => $validated['employee_type'] ?? 'personnel',
                'party_id' => $validated['party_id'] ?? null,
                'is_active' => ($validated['employee_type'] ?? 'personnel') === 'contractor'
                    ? false
                    : ($validated['is_active'] ?? true),
            ]);

            $user = User::create($attributes);
            $user->syncRoles($validated['roles'] ?? []);
            $this->syncPartyLink(null, $user->party_id, $user->id);

            return $user;
        });

        return response()->json([
            'success' => true,
            'message' => 'کاربر با موفقیت ایجاد شد.',
            'data' => new UserResource($user->load('roles')),
        ], 201);
    }

    public function update(Request $request, User $user)
    {
        $validated = $this->validateUser($request, $user);
        $this->validateContractorParty($validated, $user);

        DB::transaction(function () use ($validated, $user) {
            $newPartyId = $validated['party_id'] ?? null;
            $this->syncPartyLink($user->party_id, $newPartyId, $user->id);

            $attributes = [
                'name' => $validated['name'],
                'mobile' => $validated['mobile'],
                'email' => $validated['email'] ?? null,
                'national_code' => $validated['national_code'] ?? null,
                'personnel_code' => $validated['personnel_code'] ?? null,
                'employee_type' => $validated['employee_type'] ?? 'personnel',
                'party_id' => $newPartyId,
                'is_active' => ($validated['employee_type'] ?? 'personnel') === 'contractor'
                    ? false
                    : ($validated['is_active'] ?? true),
            ];

            if (!empty($validated['password'])) {
                $attributes['password'] = $validated['password'];
            }

            $user->update($this->supportedUserAttributes($attributes));

            if (array_key_exists('roles', $validated)) {
                $user->syncRoles($validated['roles'] ?? []);
            }
        });

        return response()->json([
            'success' => true,
            'message' => 'کاربر با موفقیت بروزرسانی شد.',
            'data' => new UserResource($user->fresh()->load('roles')),
        ]);
    }

    public function destroy(User $user)
    {
        DB::transaction(function () use ($user) {
            $this->syncPartyLink($user->party_id, null, $user->id);
            $user->delete();
        });

        return response()->json([
            'success' => true,
            'message' => 'کاربر با موفقیت حذف شد.',
        ]);
    }

    private function validateUser(Request $request, ?User $user = null): array
    {
        $id = $user?->getKey();
        $rules = [
            'name' => ['required', 'string', 'max:255'],
            'mobile' => ['required', 'string', 'max:20', Rule::unique('users', 'mobile')->ignore($id)],
            'password' => [$user ? 'nullable' : 'required', 'string', 'min:6'],
            'roles' => ['sometimes', 'array'],
            'roles.*' => ['string', 'exists:roles,name'],
        ];

        if (Schema::hasColumn('users', 'email')) {
            $rules['email'] = ['nullable', 'email', 'max:255', Rule::unique('users', 'email')->ignore($id)];
        }
        if (Schema::hasColumn('users', 'national_code')) {
            $rules['national_code'] = ['nullable', 'string', 'max:10', Rule::unique('users', 'national_code')->ignore($id)];
        }
        if (Schema::hasColumn('users', 'personnel_code')) {
            $rules['personnel_code'] = ['nullable', 'string', 'max:20', Rule::unique('users', 'personnel_code')->ignore($id)];
        }
        if (Schema::hasColumn('users', 'employee_type')) {
            $rules['employee_type'] = ['sometimes', 'required', Rule::in(['personnel', 'contractor'])];
        }
        if (Schema::hasColumn('users', 'party_id')) {
            $rules['party_id'] = ['nullable', 'integer'];
            if (Schema::hasTable('parties')) {
                $rules['party_id'][] = 'exists:parties,id';
            }
        }
        if (Schema::hasColumn('users', 'is_active')) {
            $rules['is_active'] = ['sometimes', 'boolean'];
        }

        return $request->validate($rules);
    }

    private function validateContractorParty(array $validated, ?User $user = null): void
    {
        if (($validated['employee_type'] ?? $user?->employee_type) !== 'contractor') {
            return;
        }

        if (!Schema::hasColumn('users', 'party_id') || !Schema::hasTable('parties')) {
            throw ValidationException::withMessages([
                'party_id' => ['اتصال شرکت پیمانکاری در ساختار فعلی پایگاه داده در دسترس نیست.'],
            ]);
        }

        $partyId = $validated['party_id'] ?? $user?->party_id;
        if (!$partyId) {
            throw ValidationException::withMessages([
                'party_id' => ['برای کاربر پیمانکار انتخاب شرکت الزامی است.'],
            ]);
        }

        if (Schema::hasColumn('parties', 'role') && !DB::table('parties')->where('id', $partyId)->where('role', 'contractor')->exists()) {
            throw ValidationException::withMessages([
                'party_id' => ['شرکت انتخاب‌شده معتبر نیست یا نقش پیمانکار ندارد.'],
            ]);
        }

        if (Schema::hasColumn('parties', 'user_id')) {
            $partyUserId = DB::table('parties')->where('id', $partyId)->value('user_id');
            if ($partyUserId && (int) $partyUserId !== (int) $user?->id) {
                throw ValidationException::withMessages([
                    'party_id' => ['این شرکت قبلاً به کاربر دیگری متصل شده است.'],
                ]);
            }
        }
    }

    private function syncPartyLink($oldPartyId, $newPartyId, int $userId): void
    {
        if (!Schema::hasTable('parties') || !Schema::hasColumn('parties', 'user_id')) {
            return;
        }

        if ($oldPartyId && (string) $oldPartyId !== (string) $newPartyId) {
            DB::table('parties')->where('id', $oldPartyId)->where('user_id', $userId)->update(['user_id' => null]);
        }

        if ($newPartyId) {
            DB::table('parties')->where('id', $newPartyId)->whereNull('user_id')->update(['user_id' => $userId]);
        }
    }

    private function supportedUserAttributes(array $attributes): array
    {
        return collect($attributes)->reject(function ($value, $column) {
            return in_array($column, self::OPTIONAL_USER_COLUMNS, true)
                && !Schema::hasColumn('users', $column);
        })->all();
    }

    private function applySearch(Builder $query, string $search): void
    {
        $search = trim($search);
        if ($search === '') {
            return;
        }

        $searchableColumns = array_values(array_filter(
            ['name', 'mobile', 'national_code', 'personnel_code'],
            fn (string $column) => Schema::hasColumn('users', $column),
        ));

        $query->where(function (Builder $builder) use ($searchableColumns, $search) {
            foreach ($searchableColumns as $index => $column) {
                $method = $index === 0 ? 'where' : 'orWhere';
                $builder->{$method}($column, 'like', "%{$search}%");
            }
        });
    }

    private function attachOrganizationalUnits(Collection $users): void
    {
        foreach ($users as $user) {
            $user->setAttribute('organizational_unit_data', null);
            $user->setAttribute('position_title', null);
        }

        if ($users->isEmpty()
            || !Schema::hasTable('contract_unit_signers')
            || !Schema::hasTable('organizational_units')
            || !Schema::hasColumn('contract_unit_signers', 'user_id')
            || !Schema::hasColumn('contract_unit_signers', 'unit_id')) {
            return;
        }

        $rows = DB::table('contract_unit_signers')
            ->join('organizational_units', 'organizational_units.id', '=', 'contract_unit_signers.unit_id')
            ->whereIn('contract_unit_signers.user_id', $users->pluck('id'))
            ->when(Schema::hasColumn('contract_unit_signers', 'unit_role'), fn ($query) => $query->orderBy('contract_unit_signers.unit_role'))
            ->get([
                'contract_unit_signers.user_id',
                'contract_unit_signers.unit_id',
                'organizational_units.name',
                'organizational_units.code',
                ... (Schema::hasColumn('contract_unit_signers', 'unit_role') ? ['contract_unit_signers.unit_role'] : []),
            ])
            ->unique('user_id')
            ->keyBy('user_id');

        foreach ($users as $user) {
            $row = $rows->get($user->id);
            if (!$row) {
                continue;
            }

            $user->setAttribute('organizational_unit_data', [
                'id' => $row->unit_id,
                'name' => $row->name,
                'code' => $row->code,
            ]);
            $user->setAttribute('position_title', $row->unit_role ?? null);
        }
    }
}
