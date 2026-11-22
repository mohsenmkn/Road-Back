<?php

namespace Modules\RoadTransport\App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\RoadTransport\App\Models\RoadRoute;
use Modules\RoadTransport\App\Models\RoadTrip;
use Modules\RoadTransport\App\Models\TripExpense;

class TripExpenseLimitService
{
    public function create(RoadTrip $trip, array $data): TripExpense
    {
        return DB::transaction(function () use ($trip, $data) {
            $lockedTrip = RoadTrip::query()->whereKey($trip->id)->lockForUpdate()->firstOrFail();
            $route = $lockedTrip->route_id
                ? RoadRoute::query()->whereKey($lockedTrip->route_id)->lockForUpdate()->first()
                : null;

            if (!$route || !$route->max_expense_rials) {
                throw ValidationException::withMessages([
                    'amount_rials' => ['سقف کل هزینه برای این مسیر هنوز توسط مدیر سامانه تعیین نشده است.'],
                ]);
            }

            $spent = (int) TripExpense::query()->where('trip_id', $lockedTrip->id)->sum('amount_rials');
            $amount = (int) $data['amount_rials'];
            if ($spent + $amount > $route->max_expense_rials) {
                $remaining = max(0, $route->max_expense_rials - $spent);
                throw ValidationException::withMessages([
                    'amount_rials' => [sprintf(
                        'این هزینه از سقف کل مسیر بیشتر می‌شود. سقف مسیر %s ریال و مانده مجاز %s ریال است.',
                        number_format($route->max_expense_rials),
                        number_format($remaining),
                    )],
                ]);
            }

            $data['trip_id'] = $lockedTrip->id;
            return TripExpense::create($data);
        });
    }
}
