<?php

namespace Modules\RoadTransport\App\Services;

use Illuminate\Support\Facades\DB;
use Modules\RoadTransport\App\Models\RoadBill;
use Modules\RoadTransport\App\Models\RoadBillFinancial;
use Modules\RoadTransport\App\Models\RoadVehicle;
use Modules\RoadTransport\App\Models\RoadManualIncome;
use Modules\RoadTransport\App\Models\RoadIndirectCostCoefficient;
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
        $costCoefficients = RoadIndirectCostCoefficient::query()->orderBy('id')->get();
        $driver = DB::table('road_vehicle_drivers')->where('vehicle_code', $vehicleCode)->first();
        $payslip = $driver ? $this->getPayslipSummary((int) $driver->personnel_code, ($year * 100) + $month) : null;
        $salaryAvailable = $payslip !== null && $payslip->NetPay !== null;
        $grossSalaryRials = $salaryAvailable
            ? (int) round((float) $payslip->TotalBenefits)
            : null;
        $coefficientCosts = $salaryAvailable
            ? $costCoefficients->map(fn (RoadIndirectCostCoefficient $coefficient) => [
                'id' => $coefficient->id,
                'title' => $coefficient->title,
                'factor' => $coefficient->factor,
                'notes' => $coefficient->notes,
                'net_pay_rials' => (int) round((float) $payslip->NetPay),
                'amount_rials' => (int) round((float) $payslip->NetPay * (float) $coefficient->factor),
            ])->values()
            : collect();
        $salaryRials = $salaryAvailable
            ? ($costCoefficients->isNotEmpty()
                ? (int) $coefficientCosts->sum('amount_rials')
                : $grossSalaryRials)
            : null;
        $equipment = $equipmentCosts->syncMonthlyCosts($vehicleCode, $from->toDateString(), $to->copy()->subDay()->toDateString());
        $equipmentAvailable = $equipment['status'] === 'available';
        $equipmentCostRials = $equipmentAvailable ? (int) round($equipment['total_expense']) : null;
        $equipmentIncomeRials = $equipmentAvailable ? (int) round($equipment['total_income']) : null;
        $otherIndirectExpenseRials = 0;
        $totalRevenueRials = (int) $rows->sum('revenue_rials') + (int) $manualIncomes->sum('revenue_rials') + ($equipmentIncomeRials ?? 0);
        $salaryCostRials = $salaryRials ?? 0;
        $salaryCostAvailable = $salaryRials !== null;
        $expensesComplete = $salaryCostAvailable && $equipmentCostRials !== null;
        $totalExpenseRials = $salaryCostRials + ($equipmentCostRials ?? 0) + $otherIndirectExpenseRials;
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
                'salary_rials' => $salaryCostRials,
                'equipment_income_rials' => $equipmentIncomeRials,
                'equipment_costs_rials' => $equipmentCostRials,
                'indirect_costs_rials' => $costCoefficients->isNotEmpty() ? ($salaryRials ?? 0) : 0,
                'cost_coefficients' => $costCoefficients->map(fn (RoadIndirectCostCoefficient $coefficient) => ['title' => $coefficient->title, 'factor' => $coefficient->factor])->values(),
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
            'salary' => $payslip ? [
                'issue_year_month' => (int) $payslip->IssueYearMonth,
                'effective_days' => (float) $payslip->EffectiveDays,
                'total_benefits' => (float) $payslip->TotalBenefits,
                'total_deductions' => (float) $payslip->TotalDeductions,
                'net_pay' => (float) $payslip->NetPay,
                'net_paid' => (float) $payslip->NetPay,
                'gross_salary' => $grossSalaryRials,
                'salary_cost_rials' => $salaryRials,
            ] : null,
            'salary_status' => !$driver ? 'driver_not_mapped' : ($salaryAvailable ? 'available' : 'payslip_not_found'),
            'equipment_status' => $equipment['status'],
            'equipment' => $equipment['equipment'],
            'equipment_costs' => $equipment['details'],
            'indirect_expenses' => $salaryAvailable && $driver ? $coefficientCosts->map(fn (array $cost) => [
                'title' => $cost['title'],
                'allocation_method' => 'net_pay_coefficient',
                'vehicle_code' => $vehicleCode,
                'vehicle_plate' => null,
                'personnel_code' => (int) $driver->personnel_code,
                'employee_name' => $driver->employee_name,
                'effective_days' => (float) $payslip->EffectiveDays,
                ...$cost,
                'id' => $cost['id'].'-'.$vehicleCode.'-'.$year.'-'.$month,
            ])->values() : [],
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
                'salary_rials' => $sum('salary_rials'),
                'equipment_income_rials' => $sum('equipment_income_rials'),
                'equipment_costs_rials' => $sum('equipment_costs_rials'),
                'indirect_costs_rials' => $sum('indirect_costs_rials'),
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
                'salary_rials' => $item['totals']['salary_rials'],
                'equipment_income_rials' => $item['totals']['equipment_income_rials'],
                'equipment_costs_rials' => $item['totals']['equipment_costs_rials'],
                'indirect_costs_rials' => $item['totals']['indirect_costs_rials'],
                'expense_rials' => $item['totals']['expense_rials'],
                'expense_complete' => $item['totals']['expense_complete'],
                'net_rials' => $item['totals']['net_rials'],
                'bill_count' => $item['totals']['bill_count'],
                'salary_status' => $item['salary_status'],
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

    public function getPayslipSummary(int $personnelCode, int $yearMonth): ?object
    {
        return DB::connection('pilsan_sqlsrv')->selectOne(<<<'SQL'
            SELECT
                E.Code AS PersonnelCode,
                P.FullName AS EmployeeName,
                P.NationalID,
                P.FatherName,
                pc.IssueYearMonth,
                SUM(CASE WHEN CF.Name = 'Effectwork' THEN pci.Value ELSE 0 END) AS EffectiveDays,
                SUM(CASE WHEN CF.Name = 'BonusSum' THEN pci.Value ELSE 0 END) AS TotalBenefits,
                SUM(CASE
                    WHEN CF.Name IN ('EmployeeMainInsurance', 'Tax', 'kasremoaveghe', 'LoanSum') THEN pci.Value
                    WHEN CF.Name = 'UF636' THEN -pci.Value
                    ELSE 0
                END) AS TotalDeductions,
                SUM(CASE WHEN CF.Name = 'NetPay' THEN pci.Value ELSE 0 END) AS NetPay
            FROM HCM3.PayCalc pc
            INNER JOIN HCM3.PayCalcItem pci ON pc.PayCalcID = pci.PayCalcRef
            INNER JOIN HCM3.CompensationFactor CF ON pci.CompensationFactorRef = CF.CompensationFactorID
            INNER JOIN HCM3.Employee E ON pc.EmployeeRef = E.EmployeeID
            INNER JOIN GNR3.Party P ON P.PartyID = E.PartyRef
            WHERE pc.IssueYearMonth = ?
              AND E.Code = ?
              AND CF.Name IN (
                'Effectwork', 'BonusSum', 'DeductSum', 'NetPay',
                'EmployeeMainInsurance', 'Tax', 'kasremoaveghe', 'LoanSum', 'UF636'
              )
            GROUP BY E.Code, P.FullName, P.NationalID, P.FatherName, pc.IssueYearMonth
        SQL, [$yearMonth, $personnelCode]);
    }
}
