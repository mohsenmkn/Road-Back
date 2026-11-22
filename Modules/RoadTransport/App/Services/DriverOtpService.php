<?php

namespace Modules\RoadTransport\App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Modules\RoadTransport\App\Models\RoadBill;
use Modules\RoadTransport\App\Models\RoadVehicle;
use RuntimeException;

class DriverOtpService
{
    public function request(string $billNumber, ?string $serial, string $ip): void
    {
        $billNumber = self::normalizeBillNumber($billNumber);
        $serial = $serial !== null ? trim($serial) : null;
        $limitKey = 'road_driver_otp:'.$ip.':'.$billNumber;
        if (!Cache::add($limitKey, true, now()->addSeconds(60))) {
            throw new RuntimeException('برای این بارنامه اخیراً کد ارسال شده است؛ کمی بعد دوباره تلاش کنید.');
        }

        $findBill = function () use ($billNumber, $serial) {
            $query = RoadBill::query()->where('bill_number', $billNumber);
            if ($serial !== null && $serial !== '') $query->where('bill_serial', $serial);
            return $query->orderByDesc('bill_issued_at')->orderByDesc('id')->first();
        };

        $billRecord = $findBill();
        if (!$billRecord || (!$billRecord->bill_canceled && empty($billRecord->driver_mobile))) {
            app(BitaBillService::class)->syncBillNumber($billNumber, $serial);
            $billRecord = $findBill();
        }

        if (!$billRecord) {
            throw new RuntimeException('بارنامه در سرویس بی‌تا پیدا نشد. شماره بارنامه را بررسی کنید یا با مدیر سامانه تماس بگیرید.');
        }
        if ($billRecord->bill_canceled) {
            throw new RuntimeException('این بارنامه در سرویس بی‌تا لغو شده است.');
        }

        $bill = $billRecord->bill_snapshot;
        $plate = $bill['plauqeNr'] ?? $billRecord->vehicle_plate;
        if (!RoadVehicle::isRegistered($plate, $billRecord->vehicle_code ? (int) $billRecord->vehicle_code : null)) {
            throw new RuntimeException('این بارنامه از بارنامه‌های شرکت گهرترابر نمی‌باشد.');
        }
        if (!empty($bill['billCanceled'])) throw new RuntimeException('بارنامه لغو شده است.');
        if (empty($bill['driverMobileNr']) && !empty($billRecord->driver_mobile)) {
            $bill['driverMobileNr'] = $billRecord->driver_mobile;
        }
        if (empty($bill['driverMobileNr'])) {
            throw new RuntimeException('شماره موبایل راننده در بارنامه ثبت نشده است؛ با مدیر سامانه تماس بگیرید.');
        }
        Log::info('Road driver code resolved from synced bill', [
            'driver_code' => $bill['driverSm'] ?? null,
        ]);
        $mobile = $this->normalizeMobile((string) $bill['driverMobileNr']);
        if (strlen($mobile) < 10) throw new RuntimeException('شماره موبایل ثبت‌شده در بارنامه معتبر نیست.');

        $code = str_pad((string) random_int(0, 99999), 5, '0', STR_PAD_LEFT);
        $smsKey = (string) config('services.msgway.api_key');
        if ($smsKey === '') throw new RuntimeException('تنظیمات سرویس پیامک کامل نیست.');

        try {
            $sms = Http::timeout(15)->withHeaders(['apiKey' => $smsKey])->post('https://api.msgway.com/send', [
                'mobile' => $mobile,
                'method' => 'sms',
                'provider' => (int) config('services.msgway.provider', 0),
                'templateID' => (int) config('services.msgway.template_id_default', 0),
                'params' => [$code, 'راه پیام', 'msgway.com'],
            ]);
        } catch (\Throwable $e) { throw new RuntimeException('ارتباط با سرویس پیامک برقرار نشد.', 0, $e); }
        if (!$sms->successful()) throw new RuntimeException('ارسال پیامک تأیید انجام نشد.');

        Log::info('Road driver OTP sent', [
            'bill_number' => $billRecord->bill_number,
            'driver_code' => $bill['driverSm'] ?? null,
            'otp_code' => $code,
        ]);

        DB::table('road_driver_otps')->insert([
            'road_bill_id' => $billRecord->id,
            'bill_number' => $billRecord->bill_number,
            'bill_serial' => $billRecord->bill_serial !== '' ? $billRecord->bill_serial : null,
            'driver_mobile' => $mobile,
            'code_hash' => Hash::make($code),
            'expires_at' => now()->addMinutes(5),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function verify(string $billNumber, string $code): string
    {
        $otp = DB::table('road_driver_otps')->where('bill_number', self::normalizeBillNumber($billNumber))
            ->whereNull('verified_at')->where('expires_at', '>', now())->latest('id')->first();
        if (!$otp || $otp->attempts >= 10 || !Hash::check($code, $otp->code_hash)) {
            if ($otp) DB::table('road_driver_otps')->where('id', $otp->id)->increment('attempts');
            throw new RuntimeException('کد تأیید نادرست یا منقضی شده است.');
        }

        $token = Str::random(64);
        DB::table('road_driver_otps')->where('id', $otp->id)->update([
            'verified_at' => now(),
            'session_token_hash' => hash('sha256', $token),
            'session_expires_at' => now()->addHours(12),
            'updated_at' => now(),
        ]);
        return $token;
    }

    public function session(string $plainToken): object
    {
        $candidate = DB::table('road_driver_otps')->where('session_token_hash', hash('sha256', $plainToken))
            ->where('session_expires_at', '>', now())->first();
        if ($candidate) return $candidate;
        throw new RuntimeException('نشست راننده معتبر نیست یا منقضی شده است.');
    }

    public static function normalizeMobile(string $mobile): string
    {
        $mobile = strtr($mobile, ['۰'=>'0','۱'=>'1','۲'=>'2','۳'=>'3','۴'=>'4','۵'=>'5','۶'=>'6','۷'=>'7','۸'=>'8','۹'=>'9','٠'=>'0','١'=>'1','٢'=>'2','٣'=>'3','٤'=>'4','٥'=>'5','٦'=>'6','٧'=>'7','٨'=>'8','٩'=>'9']);
        $mobile = preg_replace('/\D+/', '', $mobile);
        if (str_starts_with($mobile, '0098')) $mobile = substr($mobile, 4);
        elseif (str_starts_with($mobile, '98')) $mobile = substr($mobile, 2);
        if (strlen($mobile) === 10 && str_starts_with($mobile, '9')) $mobile = '0'.$mobile;
        return $mobile;
    }

    public static function normalizeBillNumber(string $number): string
    {
        $number = strtr(trim($number), ['۰'=>'0','۱'=>'1','۲'=>'2','۳'=>'3','۴'=>'4','۵'=>'5','۶'=>'6','۷'=>'7','۸'=>'8','۹'=>'9','٠'=>'0','١'=>'1','٢'=>'2','٣'=>'3','٤'=>'4','٥'=>'5','٦'=>'6','٧'=>'7','٨'=>'8','٩'=>'9']);
        return preg_replace('/\D+/', '', $number);
    }
}
