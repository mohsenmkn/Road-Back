<?php

namespace Modules\RoadTransport\App\Services;

use Illuminate\Support\Facades\DB;
use Modules\RoadTransport\App\Models\RoadBill;
use Modules\RoadTransport\App\Models\RoadBillFinancial;
use Modules\RoadTransport\App\Models\RoadVehicle;
use Modules\RoadTransport\App\Models\RoadManualIncome;
use Morilog\Jalali\Jalalian;

class BillFinancialService
{
    public function syncBill(RoadBill $bill): ?RoadBillFinancial
    {
        if (!$bill->vehicle_code || !$bill->bill_issued_at || !RoadVehicle::isRegistered($bill->vehicle_plate, (int) $bill->vehicle_code)) {
            return null;
        }

        $snapshot = is_array($bill->bill_snapshot) ? $bill->bill_snapshot : [];
        return RoadBillFinancial::updateOrCreate(
            ['road_bill_id' => $bill->id],
            [
                'vehicle_code' => (int) $bill->vehicle_code,
                'vehicle_plate' => $bill->vehicle_plate,
                'bill_number' => $bill->bill_number,
                'bill_serial' => (string) ($bill->bill_serial ?? ''),
                'bill_issued_at' => $bill->bill_issued_at,
                'revenue_rials' => $this->amount($snapshot['fare'] ?? 0),
                'expense_rials' => null,
                'financial_details' => [],
                'synced_at' => $bill->synced_at ?? now(),
            ]
        );
    }

    public function report(int $vehicleCode, int $year, int $month, RoadEquipmentCostService $equipmentCosts): array
    {
        $from = (new Jalalian($year, $month, 1, 0, 0, 0, new \DateTimeZone('Asia/Tehran')))->toCarbon();
        $nextMonth = $month === 12
            ? new Jalalian($year + 1, 1, 1, 0, 0, 0, new \DateTimeZone('Asia/Tehran'))
            : new Jalalian($year, $month + 1, 1, 0, 0, 0, new \DateTimeZone('Asia/Tehran'));
        $to = $nextMonth->toCarbon();

        $rows = RoadBillFinancial::query()
            ->where('vehicle_code', $vehicleCode)
            ->where('bill_issued_at', '>=', $from)
            ->where('bill_issued_at', '<', $to)
            ->orderBy('bill_issued_at')->orderBy('bill_number')->get();

        $manualIncomes = RoadManualIncome::query()
            ->where('vehicle_code', $vehicleCode)
            ->where('from_at', '>=', $from)
            ->where('from_at', '<', $to)
            ->orderBy('from_at')->orderBy('id')->get();
        $driver = DB::table('road_vehicle_drivers')->where('vehicle_code', $vehicleCode)->first();
        $driverCosts = $driver
            ? $equipmentCosts->syncMonthlyDriverCosts(
                $vehicleCode,
                (int) $driver->personnel_code,
                $from->toDateString(),
                $to->copy()->subDay()->toDateString()
            )
            : ['status' => 'driver_not_mapped', 'total_expense' => null, 'total_income' => null, 'details' => [], 'by_account' => []];
        $driverCostsAvailable = $driverCosts['status'] === 'available';
        $driverExpenseRials = $driverCostsAvailable ? (int) round($driverCosts['total_expense']) : null;
        $driverIncomeRials = $driverCostsAvailable ? (int) round($driverCosts['total_income']) : null;
        $equipment = $equipmentCosts->syncMonthlyCosts($vehicleCode, $from->toDateString(), $to->copy()->subDay()->toDateString());
        $equipmentAvailable = $equipment['status'] === 'available';
        $equipmentCostRials = $equipmentAvailable ? (int) round($equipment['total_expense']) : null;
        $equipmentIncomeRials = $equipmentAvailable ? (int) round($equipment['total_income']) : null;
        $totalRevenueRials = (int) $rows->sum('revenue_rials') + (int) $manualIncomes->sum('revenue_rials') + ($equipmentIncomeRials ?? 0) + ($driverIncomeRials ?? 0);
        $expensesComplete = $driverCostsAvailable && $equipmentCostRials !== null;
        $totalExpenseRials = ($driverExpenseRials ?? 0) + ($equipmentCostRials ?? 0);
        $billLocations = RoadBill::query()->whereIn('id', $rows->pluck('road_bill_id'))
            ->get(['id', 'driver_name', 'origin', 'destination'])->keyBy('id');

        return [
            'year' => $year,
            'month' => $month,
            'vehicle_code' => $vehicleCode,
            'from' => $from->toDateString(),
            'to_exclusive' => $to->toDateString(),
            'totals' => [
                'revenue_rials' => $totalRevenueRials,
                'driver_costs_rials' => $driverExpenseRials,
                'driver_income_rials' => $driverIncomeRials,
                'equipment_income_rials' => $equipmentIncomeRials,
                'equipment_costs_rials' => $equipmentCostRials,
                'expense_rials' => $totalExpenseRials,
                'expense_complete' => $expensesComplete,
                'net_rials' => $expensesComplete ? ($totalRevenueRials - $totalExpenseRials) : null,
                'bill_count' => $rows->count(),
                'manual_income_count' => $manualIncomes->count(),
            ],
            'driver' => $driver ? [
                'personnel_code' => (int) $driver->personnel_code,
                'employee_name' => $driver->employee_name,
            ] : null,
            'driver_cost_status' => $driverCosts['status'],
            'driver_costs' => $driverCosts['details'],
            'driver_costs_by_account' => $driverCosts['by_account'],
            'equipment_status' => $equipment['status'],
            'equipment' => $equipment['equipment'],
            'equipment_costs' => $equipment['details'],
            'equipment_type' => $equipment['equipment_type'] ?? null,
            'equipment_candidates' => $equipment['equipment_candidates'] ?? [],
            'equipment_types' => $equipment['equipment_types'] ?? [],
            'bills' => $rows->map(fn (RoadBillFinancial $row) => [
                'id' => $row->id,
                'bill_number' => $row->bill_number,
                'bill_serial' => $row->bill_serial,
                'vehicle_plate' => $row->vehicle_plate,
                'driver_name' => $billLocations->get($row->road_bill_id)?->driver_name,
                'origin' => $billLocations->get($row->road_bill_id)?->origin,
                'destination' => $billLocations->get($row->road_bill_id)?->destination,
                'bill_issued_at' => $row->bill_issued_at,
                'revenue_rials' => $row->revenue_rials,
                'expense_rials' => null,
                'net_rials' => null,
                'financial_details' => $row->financial_details,
            ])->values(),
            'manual_incomes' => $manualIncomes->map(fn (RoadManualIncome $row) => [
                'id' => $row->id,
                'company_name' => $row->company_name,
                'from_at' => $row->from_at,
                'to_at' => $row->to_at,
                'working_hours' => (float) $row->working_hours,
                'revenue_rials' => $row->revenue_rials,
            ])->values(),
        ];
    }

    public function fleetReport(int $year, int $month, RoadEquipmentCostService $equipmentCosts): array
    {
        $vehicles = \Modules\RoadTransport\App\Models\RoadVehicle::query()
            ->orderBy('vehicle_code')
            ->get(['vehicle_code', 'plate']);
        $reports = $vehicles->map(fn ($vehicle) => $this->report(
            (int) $vehicle->vehicle_code,
            $year,
            $month,
            $equipmentCosts
        ));

        $sum = fn (string $key): int => (int) $reports->sum(fn ($item) => (int) ($item['totals'][$key] ?? 0));
        $incompleteCosts = $reports->filter(fn ($item) => !$item['totals']['expense_complete'])->count();
        $revenue = $sum('revenue_rials');
        $expenses = $sum('expense_rials');

        return [
            'year' => $year,
            'month' => $month,
            'vehicle_count' => $vehicles->count(),
            'totals' => [
                'revenue_rials' => $revenue,
                'driver_costs_rials' => $sum('driver_costs_rials'),
                'driver_income_rials' => $sum('driver_income_rials'),
                'equipment_income_rials' => $sum('equipment_income_rials'),
                'equipment_costs_rials' => $sum('equipment_costs_rials'),
                'expense_rials' => $expenses,
                'expense_complete' => $incompleteCosts === 0,
                'net_rials' => $incompleteCosts === 0 ? $revenue - $expenses : null,
                'bill_count' => $sum('bill_count'),
                'incomplete_cost_vehicles' => $incompleteCosts,
            ],
            'vehicles' => $reports->map(fn ($item) => [
                'vehicle_code' => $item['vehicle_code'],
                'plate' => $vehicles->firstWhere('vehicle_code', $item['vehicle_code'])?->plate,
                'driver' => $item['driver']['employee_name'] ?? null,
                'personnel_code' => $item['driver']['personnel_code'] ?? null,
                'revenue_rials' => $item['totals']['revenue_rials'],
                'driver_costs_rials' => $item['totals']['driver_costs_rials'],
                'driver_income_rials' => $item['totals']['driver_income_rials'],
                'equipment_income_rials' => $item['totals']['equipment_income_rials'],
                'equipment_costs_rials' => $item['totals']['equipment_costs_rials'],
                'expense_rials' => $item['totals']['expense_rials'],
                'expense_complete' => $item['totals']['expense_complete'],
                'net_rials' => $item['totals']['net_rials'],
                'bill_count' => $item['totals']['bill_count'],
                'driver_cost_status' => $item['driver_cost_status'],
                'equipment_status' => $item['equipment_status'],
            ])->values(),
        ];
    }

    public function backfillRegisteredBills(): void
    {
        RoadBill::query()->whereNotNull('vehicle_code')->whereNotNull('bill_issued_at')
            ->whereNotExists(fn ($query) => $query->selectRaw('1')->from('road_bill_financials')->whereColumn('road_bill_financials.road_bill_id', 'road_bills.id'))
            ->orderBy('id')->chunkById(250, function ($bills) {
                foreach ($bills as $bill) $this->syncBill($bill);
            });
    }

    private function amount(mixed $value): int
    {
        if (is_string($value)) {
            $value = strtr($value, ['۰'=>'0','۱'=>'1','۲'=>'2','۳'=>'3','۴'=>'4','۵'=>'5','۶'=>'6','۷'=>'7','۸'=>'8','۹'=>'9','٠'=>'0','١'=>'1','٢'=>'2','٣'=>'3','٤'=>'4','٥'=>'5','٦'=>'6','٧'=>'7','٨'=>'8','٩'=>'9']);
        }
        return is_numeric($value) ? max(0, (int) round((float) $value)) : 0;
    }

}
