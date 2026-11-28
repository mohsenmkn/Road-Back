<?php

namespace Modules\RoadTransport\App\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Modules\RoadTransport\App\Models\RoadVehicle;
use Modules\RoadTransport\App\Models\RoadManualIncome;
use Modules\RoadTransport\App\Models\RoadManualExpense;
use Modules\RoadTransport\App\Services\BillFinancialService;
use Modules\RoadTransport\App\Services\RoadEquipmentCostService;
use Morilog\Jalali\Jalalian;

class BillFinancialController extends Controller
{
    public function storeManualIncome(Request $request)
    {
        $data = $request->validate([
            'vehicle_code' => ['required', 'integer', Rule::exists('road_vehicles', 'vehicle_code')],
            'company_name' => ['required', 'string', 'max:150'],
            'from_at' => ['required', 'date'],
            'to_at' => ['required', 'date', 'after:from_at'],
            'working_hours' => ['required', 'numeric', 'gt:0', 'max:99999.99'],
            'revenue_rials' => ['required', 'integer', 'min:0', 'max:999999999999'],
        ]);
        $data['entered_by'] = $request->user()->id;
        $income = RoadManualIncome::create($data);
        return response()->json(['data' => $income], 201);
    }

    public function storeManualExpense(Request $request)
    {
        $data = $request->validate([
            'vehicle_code' => ['required', 'integer', Rule::exists('road_vehicles', 'vehicle_code')],
            'subject' => ['required', 'string', 'max:150'],
            'spent_at' => ['required', 'date'],
            'amount_rials' => ['required', 'integer', 'min:1', 'max:999999999999'],
            'description' => ['nullable', 'string', 'max:2000'],
        ]);
        $data['entered_by'] = $request->user()->id;
        $expense = RoadManualExpense::create($data);

        return response()->json(['data' => $expense], 201);
    }

    public function vehicles()
    {
        return RoadVehicle::query()->orderBy('vehicle_code')->get(['vehicle_code', 'plate']);
    }

    public function monthly(Request $request, BillFinancialService $financials, RoadEquipmentCostService $equipmentCosts)
    {
        $now = Jalalian::now(new \DateTimeZone('Asia/Tehran'));
        $data = $request->validate([
            'vehicle_code' => ['sometimes', 'required', 'integer', Rule::exists('road_vehicles', 'vehicle_code')],
            'year' => ['sometimes', 'required', 'integer', 'min:1300', 'max:1500'],
            'month' => ['sometimes', 'required', 'integer', 'min:1', 'max:12'],
        ]);
        $year = (int) ($data['year'] ?? $now->getYear());
        $month = (int) ($data['month'] ?? $now->getMonth());

        $financials->backfillRegisteredBills();
        if (!isset($data['vehicle_code'])) {
            return response()->json(['data' => $financials->fleetReport($year, $month, $equipmentCosts)]);
        }

        return response()->json(['data' => $financials->report((int) $data['vehicle_code'], $year, $month, $equipmentCosts)]);
    }
}
