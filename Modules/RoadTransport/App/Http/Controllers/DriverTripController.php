<?php

namespace Modules\RoadTransport\App\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Modules\RoadTransport\App\Models\ExpenseCategory;
use Modules\RoadTransport\App\Models\RoadBill;
use Modules\RoadTransport\App\Models\RoadRoute;
use Modules\RoadTransport\App\Models\RoadTrip;
use Modules\RoadTransport\App\Models\RoadVehicle;
use Modules\RoadTransport\App\Models\TripExpense;
use Modules\RoadTransport\App\Services\DriverOtpService;
use Modules\RoadTransport\App\Services\TripExpenseLimitService;
use RuntimeException;

class DriverTripController extends Controller
{
    public function requestOtp(Request $request, DriverOtpService $otp)
    {
        $data = $request->validate(['bill_number' => ['required', 'string', 'max:50'], 'bill_serial' => ['nullable', 'string', 'max:50']]);
        try {
            $otp->request($data['bill_number'], $data['bill_serial'] ?? null, (string) $request->ip());
            return response()->json(['message' => 'کد تأیید به شماره موبایل راننده ثبت‌شده در بارنامه ارسال شد.', 'expires_in' => 300]);
        } catch (RuntimeException $e) {
            throw ValidationException::withMessages(['bill_number' => [$e->getMessage()]]);
        }
    }

    public function verifyOtp(Request $request, DriverOtpService $otp)
    {
        $data = $request->validate(['bill_number' => ['required', 'string', 'max:50'], 'otp' => ['required', 'digits:5']]);
        try {
            return response()->json(['token' => $otp->verify($data['bill_number'], $data['otp']), 'token_type' => 'Bearer']);
        } catch (RuntimeException $e) {
            throw ValidationException::withMessages(['otp' => [$e->getMessage()]]);
        }
    }

    public function start(Request $request, DriverOtpService $otp)
    {
        $session = $this->session($request, $otp);
        $data = $request->validate(['odometer_photo' => ['required', 'image', 'mimes:jpg,jpeg,png,webp', 'max:8192']]);
        try {
            $billRecord = !empty($session->road_bill_id)
                ? RoadBill::query()
                    ->whereKey($session->road_bill_id)
                    ->where('bill_number', $session->bill_number)
                    ->where('driver_mobile', $session->driver_mobile)
                    ->where('bill_canceled', false)
                    ->first()
                : RoadBill::query()
                    ->where('bill_number', $session->bill_number)
                    ->where('bill_serial', $session->bill_serial ?? '')
                    ->where('driver_mobile', $session->driver_mobile)
                    ->where('bill_canceled', false)
                    ->first();
            if (!$billRecord) throw new RuntimeException('بارنامه همگام‌شده برای این نشست راننده پیدا نشد.');
            $bill = $billRecord->bill_snapshot;
        } catch (RuntimeException $e) {
            throw ValidationException::withMessages(['bill_number' => [$e->getMessage()]]);
        }
        if (DriverOtpService::normalizeMobile((string) ($bill['driverMobileNr'] ?? '')) !== DriverOtpService::normalizeMobile($session->driver_mobile)) {
            throw ValidationException::withMessages(['bill_number' => ['رانندهٔ فعلی با اطلاعات بارنامه تطبیق ندارد.']]);
        }

        if (!RoadVehicle::isRegistered($bill['plauqeNr'] ?? $billRecord->vehicle_plate, $billRecord->vehicle_code ? (int) $billRecord->vehicle_code : null)) {
            throw ValidationException::withMessages(['bill_number' => ['این بارنامه از بارنامه‌های شرکت گهرترابر نمی‌باشد.']]);
        }

        $origin = trim((string) ($bill['senderCityName'] ?? ''));
        $destination = trim((string) ($bill['receiverCityName'] ?? ''));
        $route = RoadRoute::where('origin', $origin)->where('destination', $destination)->where('is_active', true)->first();
        if (!$route) throw ValidationException::withMessages(['route' => ['برای این مسیر زمان مجاز تعیین نشده است؛ با مدیر جاده‌ای تماس بگیرید.']]);
        if (!$route->max_expense_rials) throw ValidationException::withMessages(['route' => ['سقف کل هزینه این مسیر هنوز توسط مدیر سامانه تعیین نشده است؛ امکان شروع سفر وجود ندارد.']]);
        if (!empty($bill['billCanceled'])) throw ValidationException::withMessages(['bill_number' => ['بارنامه لغو شده و شروع سفر ممکن نیست.']]);

        $issuedAt = $billRecord->bill_issued_at;
        if (!$issuedAt && !empty($bill['issueDateTime'])) {
            try {
                $issuedAt = Carbon::parse($bill['issueDateTime']);
                $billRecord->bill_issued_at = $issuedAt;
                $billRecord->save();
            } catch (\Throwable $e) { $issuedAt = null; }
        }
        if (!$issuedAt) {
            throw ValidationException::withMessages(['bill_number' => ['تاریخ صدور بارنامه در اطلاعات محلی موجود نیست؛ بارنامه را دوباره همگام‌سازی کنید.']]);
        }
        $deadline = $issuedAt->copy()->addHours((int) $route->allowed_hours);
        $startedAt = now();
        if ($startedAt->lt($issuedAt)) {
            throw ValidationException::withMessages(['bill_number' => ['شروع سفر پیش از تاریخ صدور بارنامه مجاز نیست.']]);
        }
        if ($startedAt->gt($deadline)) {
            throw ValidationException::withMessages(['bill_number' => ['مهلت مجاز شروع سفر بر اساس تاریخ صدور بارنامه به پایان رسیده است.']]);
        }
        $previousQuery = RoadTrip::where('bill_number', $session->bill_number)->where('driver_mobile', $session->driver_mobile);
        $session->bill_serial === null ? $previousQuery->whereNull('bill_serial') : $previousQuery->where('bill_serial', $session->bill_serial);
        $previousTrip = $previousQuery->latest('id')->first();
        if ($previousTrip) throw ValidationException::withMessages(['bill_number' => ['برای این بارنامه قبلاً شروع سفر ثبت شده است.']]);

        $photoPath = $request->file('odometer_photo')->store('road-trips/odometer', 'local');
        $trip = RoadTrip::create([
            'bill_number' => $session->bill_number,
            'bill_serial' => $session->bill_serial,
            'bill_company_code' => $bill['companyCode'] ?? config('services.bita_bill.company_code'),
            'driver_mobile' => $session->driver_mobile,
            'driver_name' => $bill['driverName'] ?? null,
            'vehicle_plate' => $bill['plauqeNr'] ?? null,
            'vehicle_code' => $billRecord->vehicle_code ?: RoadVehicle::codeForPlate($bill['plauqeNr'] ?? null),
            'origin' => $origin,
            'destination' => $destination,
            'route_id' => $route->id,
            'bill_issued_at' => $issuedAt,
            'started_at' => $startedAt,
            'odometer_photo_path' => $photoPath,
            'ends_at' => $deadline,
            'status' => 'started',
            'bill_snapshot' => $bill,
        ]);

        DB::table('road_driver_otps')->where('id', $session->id)->update(['session_expires_at' => $trip->ends_at->copy()->addHour(), 'updated_at' => now()]);
        return response()->json(['data' => $this->safeTrip($trip), 'message' => 'شروع سفر ثبت شد.']);
    }

    public function current(Request $request, DriverOtpService $otp)
    {
        $session = $this->session($request, $otp);
        $tripQuery = RoadTrip::where('bill_number', $session->bill_number)->where('driver_mobile', $session->driver_mobile)
            ->where('status', 'started');
        $session->bill_serial === null ? $tripQuery->whereNull('bill_serial') : $tripQuery->where('bill_serial', $session->bill_serial);
        $trip = $tripQuery->latest('id')->first();
        return response()->json(['data' => $trip ? $this->safeTrip($trip) : null]);
    }

    public function categories(DriverOtpService $otp, Request $request)
    {
        $this->session($request, $otp);
        return ExpenseCategory::where('is_active', true)->orderBy('name')->get();
    }

    public function expenses(Request $request, DriverOtpService $otp)
    {
        $session = $this->session($request, $otp);
        $tripQuery = RoadTrip::where('bill_number', $session->bill_number)
            ->where('driver_mobile', $session->driver_mobile)
            ->where('status', 'started');
        $session->bill_serial === null
            ? $tripQuery->whereNull('bill_serial')
            : $tripQuery->where('bill_serial', $session->bill_serial);
        $trip = $tripQuery->latest('id')->first();

        if (!$trip) return response()->json(['data' => []]);

        $expenses = TripExpense::with('category:id,name')
            ->where('trip_id', $trip->id)
            ->orderByDesc('spent_at')
            ->get()
            ->map(fn (TripExpense $expense) => [
                'id' => $expense->id,
                'category' => $expense->category?->name,
                'amount_rials' => $expense->amount_rials,
                'spent_at' => $expense->spent_at,
                'description' => $expense->description,
            ]);

        return response()->json(['data' => $expenses]);
    }

    public function storeExpense(Request $request, DriverOtpService $otp, TripExpenseLimitService $expenseLimit)
    {
        $session = $this->session($request, $otp);
        $data = $request->validate([
            'category_id' => ['required', 'integer', 'exists:road_expense_categories,id'],
            'amount_rials' => ['required', 'integer', 'min:1'],
            'description' => ['nullable', 'string', 'max:2000'],
        ]);
        $now = now();
        $tripQuery = RoadTrip::where('bill_number', $session->bill_number)->where('driver_mobile', $session->driver_mobile)
            ->where('status', 'started')->where('started_at', '<=', $now);
        $session->bill_serial === null ? $tripQuery->whereNull('bill_serial') : $tripQuery->where('bill_serial', $session->bill_serial);
        $trip = $tripQuery->latest('id')->first();
        if (!$trip) throw ValidationException::withMessages(['trip' => ['سفر فعالی برای ثبت هزینه وجود ندارد یا مهلت سفر تمام شده است.']]);
        $route = $trip->route_id ? RoadRoute::find($trip->route_id) : null;
        $deadline = $trip->bill_issued_at && $route
            ? $trip->bill_issued_at->copy()->addHours((int) $route->allowed_hours)
            : null;
        if ($deadline && $trip->ends_at && $trip->ends_at->lt($deadline)) $deadline = $trip->ends_at;
        if (!$deadline || $now->lt($trip->bill_issued_at) || $now->gt($deadline)) {
            throw ValidationException::withMessages(['trip' => ['مهلت ثبت هزینه بر اساس تاریخ صدور بارنامه به پایان رسیده است.']]);
        }
        if (!RoadVehicle::isRegistered($trip->vehicle_plate, $trip->vehicle_code ? (int) $trip->vehicle_code : null)) {
            throw ValidationException::withMessages(['trip' => ['ثبت هزینه فقط برای خودروهای شرکت گهرترابر مجاز است.']]);
        }
        $category = ExpenseCategory::whereKey($data['category_id'])->where('is_active', true)->firstOrFail();
        if ($data['amount_rials'] > $category->max_amount_rials) throw ValidationException::withMessages(['amount_rials' => ['مبلغ از سقف مجاز این هزینه بیشتر است.']]);

        $expense = $expenseLimit->create($trip, [
            'trip_id' => $trip->id,
            'category_id' => $category->id,
            'driver_mobile' => $session->driver_mobile,
            'amount_rials' => $data['amount_rials'],
            'spent_at' => $now,
            'description' => $data['description'] ?? null,
        ]);
        return response()->json(['data' => $expense->load('category'), 'message' => 'هزینه ثبت شد.'], 201);
    }

    private function session(Request $request, DriverOtpService $otp): object
    {
        $token = $request->bearerToken();
        if (!$token) throw ValidationException::withMessages(['token' => ['نشست راننده معتبر نیست.']]);
        try { return $otp->session($token); }
        catch (RuntimeException $e) { throw ValidationException::withMessages(['token' => [$e->getMessage()]]); }
    }

    private function safeTrip(RoadTrip $trip): array
    {
        return $trip->only(['id', 'bill_number', 'bill_issued_at', 'driver_name', 'vehicle_plate', 'vehicle_code', 'origin', 'destination', 'started_at', 'ends_at', 'status']);
    }
}

