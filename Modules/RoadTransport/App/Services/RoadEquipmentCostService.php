<?php

namespace Modules\RoadTransport\App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Modules\RoadTransport\App\Models\RoadVehicle;

class RoadEquipmentCostService
{
    public function getAllEquipmentTypesFromDB(): array
    {
        return DB::connection('gtarabar')
            ->table('FIN3.DLType as dt')
            ->join('FIN3.DL as dl', 'dl.DLTypeRef', '=', 'dt.DLTypeID')
            ->select('dt.DLTypeID', 'dt.Title', 'dt.Title_En')
            ->distinct()
            ->orderBy('dt.Title')
            ->get()
            ->toArray();
    }

    public function getEquipmentCosts(string $equipmentCode, int $typeId, string $fromDate, string $toDate): array
    {
        $equipmentCode = (string) $equipmentCode;

        return DB::connection('gtarabar')
            ->table('FIN3.Voucher as v')
            ->join('FIN3.VoucherItem as vi', 'v.VoucherID', '=', 'vi.VoucherRef')
            ->join('FIN3.SL as sl', 'vi.SLRef', '=', 'sl.SLID')
            ->join('FIN3.DL as dl', function ($join) {
                $join->on('dl.Code', '=', 'vi.DLLevel5')
                    ->whereColumn('dl.DLTypeRef', '=', 'vi.DLTypeRef5');
            })
            ->whereDate('v.Date', '>=', $fromDate)
            ->whereDate('v.Date', '<=', $toDate)
            ->where('vi.DLLevel5', '=', $equipmentCode)
            ->where('vi.DLTypeRef5', '=', $typeId)
            ->select(
                'v.VoucherID',
                'v.Number as voucher_number',
                'v.Date as voucher_date',
                'v.State as voucher_state',
                'v.Description as voucher_description',
                'vi.RowNumber as row_number',
                'sl.Title as account_title',
                'sl.Code as account_code',
                'dl.Title as equipment_title',
                'dl.Code as equipment_code',
                'vi.Debit',
                'vi.Credit',
                DB::raw('ISNULL(vi.Debit, 0) as expense_amount'),
                DB::raw('ISNULL(vi.Credit, 0) as income_amount'),
                DB::raw('(ISNULL(vi.Debit, 0) - ISNULL(vi.Credit, 0)) as amount')
            )
            ->orderBy('v.Date', 'desc')
            ->orderBy('v.Number', 'desc')
            ->get()
            ->toArray();
    }

    public function syncMonthlyCosts(int $vehicleCode, string $fromDate, string $toDate): array
    {
        try {
            $types = DB::connection('gtarabar')
                ->table('FIN3.DLType as dt')
                ->join('FIN3.DL as dl', 'dl.DLTypeRef', '=', 'dt.DLTypeID')
                ->select('dt.DLTypeID', 'dt.Title', 'dt.Title_En', DB::raw('COUNT(dl.DLID) as EquipmentCount'))
                ->groupBy('dt.DLTypeID', 'dt.Title', 'dt.Title_En')
                ->orderByDesc('EquipmentCount')
                ->get();
        } catch (\Throwable $exception) {
            Log::warning('Unable to resolve truck equipment type in Gtarabar.', ['exception' => get_class($exception)]);
            return ['status' => 'source_unavailable', 'equipment' => null, 'total_amount' => null, 'details' => []];
        }

        $truckTypeTitle = RoadVehicle::normalizePlate('کامیون');
        $type = $types->first(fn ($item) => RoadVehicle::normalizePlate((string) $item->Title) === $truckTypeTitle);
        if (!$type) {
            return ['status' => 'equipment_type_not_found', 'equipment' => null, 'total_amount' => null, 'details' => []];
        }

        $vehicle = DB::table('road_vehicles')->where('vehicle_code', $vehicleCode)->first(['plate']);
        if (!$vehicle) {
            return [
                'status' => 'equipment_not_found', 'equipment' => null,
                'equipment_type' => ['id' => (int) $type->DLTypeID, 'title' => $type->Title],
                'total_amount' => null, 'details' => [],
            ];
        }

        try {
            $equipmentRows = DB::connection('gtarabar')
                ->table('FIN3.DL')
                ->where('DLTypeRef', (int) $type->DLTypeID)
                ->select('DLID', 'Title', 'Code')
                ->get();
        } catch (\Throwable $exception) {
            Log::warning('Unable to retrieve truck equipment list from Gtarabar.', ['exception' => get_class($exception)]);
            return ['status' => 'source_unavailable', 'equipment' => null, 'total_amount' => null, 'details' => []];
        }

        $normalizedPlate = RoadVehicle::normalizePlate($vehicle->plate);
        $matches = $equipmentRows->filter(function ($item) use ($normalizedPlate) {
            $normalizedTitle = RoadVehicle::normalizePlate((string) $item->Title);
            return $normalizedPlate !== '' && str_contains($normalizedTitle, $normalizedPlate);
        })->values();

        if ($matches->count() !== 1) {
            return [
                'status' => $matches->isEmpty() ? 'equipment_not_found' : 'equipment_mapping_ambiguous',
                'equipment' => null,
                'equipment_type' => ['id' => (int) $type->DLTypeID, 'title' => $type->Title],
                'equipment_candidates' => $matches->map(fn ($item) => ['code' => (string) $item->Code, 'title' => $item->Title])->values(),
                'total_amount' => null,
                'details' => [],
            ];
        }

        $equipment = $matches->first();
        try {
            $sourceCosts = $this->getEquipmentCosts((string) $equipment->Code, (int) $type->DLTypeID, $fromDate, $toDate);
        } catch (\Throwable $exception) {
            Log::warning('Unable to retrieve Pilsan equipment costs from Gtarabar.', ['exception' => get_class($exception)]);
            return ['status' => 'source_unavailable', 'equipment' => null, 'total_amount' => null, 'details' => []];
        }

        $now = now();
        $rows = array_values(array_filter(array_map(fn ($cost) => [
            'vehicle_code' => $vehicleCode,
            'equipment_type_id' => (int) $type->DLTypeID,
            'equipment_type_title' => $type->Title,
            'equipment_code' => (string) $cost->equipment_code,
            'equipment_title' => $cost->equipment_title,
            'voucher_id' => (int) $cost->VoucherID,
            'voucher_number' => isset($cost->voucher_number) ? (string) $cost->voucher_number : null,
            'voucher_date' => $cost->voucher_date,
            'voucher_state' => isset($cost->voucher_state) ? (string) $cost->voucher_state : null,
            'voucher_description' => $cost->voucher_description,
            'row_number' => (int) $cost->row_number,
            'account_title' => $cost->account_title,
            'account_code' => isset($cost->account_code) ? (string) $cost->account_code : null,
            'debit' => (float) ($cost->Debit ?? 0),
            'credit' => (float) ($cost->Credit ?? 0),
            'amount' => (float) ($cost->amount ?? 0),
            'synced_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ], $sourceCosts), fn (array $row) => !empty($row['voucher_date'])));

        $toExclusive = date('Y-m-d', strtotime($toDate . ' +1 day'));
        DB::transaction(function () use ($rows, $vehicleCode, $fromDate, $toExclusive): void {
            DB::table('road_equipment_costs')
                ->where('vehicle_code', $vehicleCode)
                ->where('voucher_date', '>=', $fromDate)
                ->where('voucher_date', '<', $toExclusive)
                ->delete();

            foreach (array_chunk($rows, 250) as $chunk) {
                DB::table('road_equipment_costs')->insert($chunk);
            }
        });

        $details = DB::table('road_equipment_costs')
            ->where('vehicle_code', $vehicleCode)
            ->where('voucher_date', '>=', $fromDate)
            ->where('voucher_date', '<', $toExclusive)
            ->orderByDesc('voucher_date')->orderByDesc('voucher_number')->orderBy('row_number')
            ->get();

        return [
            'status' => 'available',
            'equipment' => [
                'code' => (string) $equipment->Code,
                'title' => $equipment->Title,
                'type_id' => (int) $type->DLTypeID,
                'type_title' => $type->Title,
                'type_title_en' => $type->Title_En,
            ],
            'total_amount' => (float) $details->sum('debit'),
            'total_expense' => (float) $details->sum('debit'),
            'total_income' => (float) $details->sum('credit'),
            'details' => $details->values(),
        ];
    }
}
