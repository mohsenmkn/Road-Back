<?php

namespace Modules\RoadTransport\App\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Modules\RoadTransport\App\Models\ExpenseCategory;
use Modules\RoadTransport\App\Models\TripExpense;
use Modules\RoadTransport\App\Models\RoadTrip;
use Modules\RoadTransport\App\Models\RoadRoute;
use Modules\RoadTransport\App\Models\RoadVehicle;
use Modules\RoadTransport\App\Services\TripExpenseLimitService;

class TripExpenseController extends Controller
{
    public function categories()
    {
        return ExpenseCategory::where('is_active', true)->orderBy('name')->get();
    }

    public function trips()
    {
        return RoadTrip::query()->withCount('expenses')->withSum('expenses', 'amount_rials')->latest('started_at')->limit(200)->get([
            'id', 'bill_number', 'bill_serial', 'bill_issued_at', 'driver_name', 'driver_mobile', 'vehicle_plate', 'vehicle_code', 'origin', 'destination', 'started_at', 'ends_at', 'finished_at', 'status', 'odometer_photo_path',
        ]);
    }

    public function odometerPhoto(RoadTrip $trip)
    {
        abort_unless($trip->odometer_photo_path && Storage::disk('local')->exists($trip->odometer_photo_path), 404);
        return Storage::disk('local')->response($trip->odometer_photo_path);
    }

    public function store(Request $request, TripExpenseLimitService $expenseLimit)
    {
        $data = $request->validate([
            'trip_id' => ['required', 'integer', 'exists:road_trips,id'],
            'category_id' => ['required', 'integer', 'exists:road_expense_categories,id'],
            'amount_rials' => ['required', 'integer', 'min:1'],
            'spent_at' => ['required', 'date'],
            'description' => ['nullable', 'string', 'max:2000'],
        ]);

        $category = ExpenseCategory::whereKey($data['category_id'])->where('is_active', true)->firstOrFail();
        if ($data['amount_rials'] > $category->max_amount_rials) {
            throw ValidationException::withMessages(['amount_rials' => 'مبلغ از سقف مجاز روزانه این هزینه بیشتر است.']);
        }

        $trip = RoadTrip::findOrFail($data['trip_id']);
        if (!RoadVehicle::isRegistered($trip->vehicle_plate, $trip->vehicle_code ? (int) $trip->vehicle_code : null)) {
            throw ValidationException::withMessages(['trip_id' => 'ثبت هزینه فقط برای خودروهای شرکت گهرترابر مجاز است.']);
        }

        $spentAt = Carbon::parse($data['spent_at']);
        $route = $trip->route_id ? RoadRoute::find($trip->route_id) : null;
        $deadline = $trip->bill_issued_at && $route
            ? $trip->bill_issued_at->copy()->addHours((int) $route->allowed_hours)
            : null;
        if ($deadline && $trip->ends_at && $trip->ends_at->lt($deadline)) $deadline = $trip->ends_at;
        $now = Carbon::now();
        if ($trip->status !== 'started' || !$trip->started_at || !$trip->bill_issued_at || !$deadline
            || $spentAt->lt($trip->started_at) || $spentAt->lt($trip->bill_issued_at) || $spentAt->gt($deadline)
            || $now->lt($trip->started_at) || $now->gt($deadline)) {
            throw ValidationException::withMessages(['trip_id' => 'هزینه فقط از تاریخ صدور بارنامه و در مهلت مجاز مسیر قابل ثبت است.']);
        }

        $data['registered_by'] = $request->user()->id;
        $expense = $expenseLimit->create($trip, $data);

        return response()->json(['data' => $expense->load('category')], 201);
    }

    public function index(Request $request)
    {
        $query = TripExpense::with(['category', 'trip'])->latest('spent_at');
        if ($request->filled('trip_id')) $query->where('trip_id', $request->integer('trip_id'));
        $perPage = min(max($request->integer('per_page', 50), 1), 100);
        return $query->paginate($perPage);
    }
}

