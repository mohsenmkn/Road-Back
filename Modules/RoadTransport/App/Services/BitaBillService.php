<?php

namespace Modules\RoadTransport\App\Services;

use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;
use Modules\RoadTransport\App\Models\RoadVehicle;
use Modules\RoadTransport\App\Models\RoadBill;
use Modules\RoadTransport\App\Services\DriverOtpService;
use Morilog\Jalali\Jalalian;
use RuntimeException;

class BitaBillService
{
    public function searchRange(Carbon $from, Carbon $to): array
    {
        $body = [
            'fromDate' => \Morilog\Jalali\Jalalian::fromCarbon($from)->format('Y/m/d'),
            'toDate' => \Morilog\Jalali\Jalalian::fromCarbon($to)->format('Y/m/d'),
        ];

        try {
            $response = Http::acceptJson()->withToken($this->accessToken())
                ->timeout(60)->post($this->url('/Back/Bill/BLSearch'), $body);
        } catch (\Throwable $e) {
            throw new RuntimeException('ارتباط با سرویس واکشی بارنامه‌ها برقرار نشد.', 0, $e);
        }

        if (!$response->successful()) {
            $errorPayload = $response->json();
            Log::warning('Bita bill range sync failed', [
                'status' => $response->status(),
                'content_type' => $response->header('Content-Type'),
                'response_keys' => is_array($errorPayload) ? array_keys($errorPayload) : [],
            ]);
            throw new RuntimeException('واکشی بارنامه‌ها از سرویس بی‌تا ناموفق بود.');
        }

        $payload = $response->json();
        if (is_string($payload)) $payload = json_decode($payload, true);
        if (isset($payload['data']) && is_array($payload['data'])) $payload = $payload['data'];
        if (!is_array($payload)) throw new RuntimeException('قالب پاسخ واکشی بارنامه‌ها از بی‌تا معتبر نیست.');

        return array_is_list($payload) ? $payload : [$payload];
    }

    public function findByNumber(string $number, ?string $serial = null): array
    {
        $body = ['number' => (int) preg_replace('/\D+/', '', $number)];
        if ($serial !== null && trim($serial) !== '') $body['serial'] = trim($serial);
        try {
            $response = Http::acceptJson()->withToken($this->accessToken())
                ->timeout(20)->post($this->url('/Back/Bill/BLSearchNumber'), $body);
        } catch (\Throwable $e) {
            throw new RuntimeException('ارتباط با سرویس بارنامه برقرار نشد.', 0, $e);
        }
        if (!$response->successful()) {
            $payload = $response->json();
            $diagnostics = [];
            if (is_array($payload)) {
                array_walk_recursive($payload, function ($value, $key) use (&$diagnostics) {
                    if (is_string($value) && preg_match('/error|message|code|title|detail|trace|status/i', (string) $key)) {
                        $diagnostics[$key] = mb_substr($value, 0, 300);
                    }
                });
            }
            Log::warning('Bita bill lookup failed', [
                'status' => $response->status(),
                'content_type' => $response->header('Content-Type'),
                'response_keys' => is_array($payload) ? array_keys($payload) : [],
                'diagnostics' => $diagnostics,
            ]);
            throw new RuntimeException('دریافت بارنامه از سرویس بی‌تا ناموفق بود.');
        }

        $data = $response->json();
        if (is_string($data)) $data = json_decode($data, true);
        if (isset($data['data']) && is_array($data['data'])) $data = $data['data'];
        if (isset($data[0]) && is_array($data[0])) $data = $data[0];
        if (!is_array($data) || empty($data['driverMobileNr'])) throw new RuntimeException('بارنامه پیدا نشد یا شماره موبایل راننده در آن ثبت نشده است.');

        return $data;
    }

    /**
     * On-demand fallback for driver login: look for a bill in the current
     * Jalali month using the same seven-day ranges as the scheduled sync.
     */
    public function syncBillNumber(string $number, ?string $serial = null): int
    {
        $targetNumber = self::normalizeBillNumber($number);
        $targetSerial = trim((string) $serial);
        $timezone = new \DateTimeZone('Asia/Tehran');
        $current = Jalalian::now($timezone);
        $year = $current->getYear();
        $month = $current->getMonth();
        $monthDays = $current->getMonthDays();
        $currentDay = $current->getDay();

        // Check the newest week first because a driver is most likely entering
        // a bill issued recently. Bita accepts a maximum seven-day range here.
        $ranges = [];
        for ($startDay = 1; $startDay <= $currentDay; $startDay += 7) {
            $endDay = min($startDay + 6, $monthDays, $currentDay);
            $ranges[] = [$startDay, $endDay];
        }

        foreach (array_reverse($ranges) as [$startDay, $endDay]) {
            $from = (new Jalalian($year, $month, $startDay, 0, 0, 0, $timezone))->toCarbon();
            $to = (new Jalalian($year, $month, $endDay, 23, 59, 59, $timezone))->toCarbon();
            $matches = array_values(array_filter($this->searchRange($from, $to), function ($bill) use ($targetNumber, $targetSerial) {
                if (!is_array($bill) || self::normalizeBillNumber((string) ($bill['bolNr'] ?? '')) !== $targetNumber) {
                    return false;
                }

                return $targetSerial === '' || trim((string) ($bill['serialNr'] ?? '')) === $targetSerial;
            }));

            if ($matches) {
                return $this->storeBills($matches);
            }
        }

        return 0;
    }

    /** Persist normalized Bita bill records using the scheduled sync identity. */
    /** Keep only bills whose plate matches a vehicle registered in the company fleet. */
    public function registeredFleetBills(array $bills): array
    {
        $plates = DB::table('road_vehicles')->pluck('vehicle_code', 'normalized_plate');

        return array_values(array_filter($bills, static function ($bill) use ($plates): bool {
            if (!is_array($bill)) return false;

            $plate = RoadVehicle::normalizePlate(isset($bill['plauqeNr']) ? (string) $bill['plauqeNr'] : null);
            return $plate !== '' && $plates->has($plate);
        }));
    }

    public function storeBills(array $bills): int
    {
        $now = now();
        $rows = [];
        $vehicleCodes = DB::table('road_vehicles')->pluck('vehicle_code', 'normalized_plate');

        foreach ($bills as $bill) {
            if (!is_array($bill) || !isset($bill['bolNr'])) continue;

            $billNumber = self::normalizeBillNumber((string) $bill['bolNr']);
            if ($billNumber === '') continue;
            $plate = $bill['plauqeNr'] ?? null;

            $rows[] = [
                'company_code' => (string) ($bill['companyCode'] ?? ''),
                'office_code' => (string) ($bill['officeCode'] ?? ''),
                'bill_number' => $billNumber,
                'bill_serial' => trim((string) ($bill['serialNr'] ?? '')),
                'driver_mobile' => !empty($bill['driverMobileNr'])
                    ? DriverOtpService::normalizeMobile((string) $bill['driverMobileNr'])
                    : null,
                'driver_name' => $bill['driverName'] ?? null,
                'vehicle_plate' => $plate,
                'vehicle_code' => $vehicleCodes->get(RoadVehicle::normalizePlate($plate)),
                'origin' => $bill['senderCityName'] ?? null,
                'destination' => $bill['receiverCityName'] ?? null,
                'bill_issued_at' => !empty($bill['issueDateTime']) ? $bill['issueDateTime'] : null,
                'bill_canceled' => (bool) ($bill['billCanceled'] ?? false),
                'bill_snapshot' => json_encode($bill, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                'synced_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        foreach (array_chunk($rows, 500) as $chunk) {
            DB::table('road_bills')->upsert(
                $chunk,
                ['company_code', 'office_code', 'bill_number', 'bill_serial'],
                ['driver_mobile', 'driver_name', 'vehicle_plate', 'vehicle_code', 'origin', 'destination', 'bill_issued_at', 'bill_canceled', 'bill_snapshot', 'synced_at', 'updated_at']
            );
        }

        if ($rows) {
            $billIds = DB::table('road_bills')->where('synced_at', $now)->whereIn('bill_number', array_column($rows, 'bill_number'))
                ->whereNotNull('vehicle_code')->pluck('id');
            $financialService = app(BillFinancialService::class);
            RoadBill::query()->whereIn('id', $billIds)->get()->each(fn (RoadBill $bill) => $financialService->syncBill($bill));
        }

        return count($rows);
    }

    private static function normalizeBillNumber(string $number): string
    {
        $number = strtr(trim($number), [
            '۰'=>'0','۱'=>'1','۲'=>'2','۳'=>'3','۴'=>'4','۵'=>'5','۶'=>'6','۷'=>'7','۸'=>'8','۹'=>'9',
            '٠'=>'0','١'=>'1','٢'=>'2','٣'=>'3','٤'=>'4','٥'=>'5','٦'=>'6','٧'=>'7','٨'=>'8','٩'=>'9',
        ]);

        return preg_replace('/\D+/', '', $number);
    }

    private function accessToken(): string
    {
        if ($token = Cache::get('road_transport.bita_access_token')) return $token;

            $username = (string) config('services.bita_bill.username');
            $password = (string) config('services.bita_bill.password');
            if ($username === '' || $password === '') throw new RuntimeException('تنظیمات ورود API بارنامه در محیط سرور کامل نشده است.');

            $credentials = ['userName' => $username, 'password' => $password];
            if ($clientId = config('services.bita_bill.client_id')) $credentials['clientId'] = $clientId;
            try { $response = Http::acceptJson()->timeout(15)->post($this->url('/Back/Bill/GetToken'), $credentials); }
            catch (\Throwable $e) { throw new RuntimeException('ارتباط با سرویس احراز هویت بارنامه برقرار نشد.', 0, $e); }
            $payload = $response->json();
            if (!$response->successful() || !is_array($payload) || empty($payload['access_token'])) {
                throw new RuntimeException('احراز هویت با API بارنامه ناموفق بود.');
            }

            $expiresAt = isset($payload['acExpire']) ? Carbon::parse($payload['acExpire']) : now()->addMinutes(5);
            $ttl = max(1, min(240, now()->diffInSeconds($expiresAt, false) - 15));
            Cache::put('road_transport.bita_access_token', $payload['access_token'], now()->addSeconds($ttl));
            return $payload['access_token'];
    }

    private function url(string $path): string
    {
        return rtrim((string) config('services.bita_bill.base_url'), '/').$path;
    }
}
