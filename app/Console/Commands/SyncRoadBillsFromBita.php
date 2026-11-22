<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Morilog\Jalali\Jalalian;
use Modules\RoadTransport\App\Services\BitaBillService;
use Modules\RoadTransport\App\Models\RoadBitaSyncRun;
use Throwable;

class SyncRoadBillsFromBita extends Command
{
    protected $signature = 'road-transport:sync-bills {mode=pilisan : pilisan for registered company trucks, all for every bill} {--from= : Earliest Jalali date (YYYY/MM/DD); defaults to the first day of the current Jalali month} {--run-id= : Existing sync status record id}';

    protected $description = 'Sync Bita bills backwards weekly, filtering to registered company trucks or all bills';

    public function handle(BitaBillService $bita): int
    {
        $run = $this->option('run-id')
            ? RoadBitaSyncRun::find($this->option('run-id'))
            : null;
        $run ??= RoadBitaSyncRun::create([
            'status' => 'running',
            'started_at' => now(),
        ]);

        $lock = Cache::lock('road-transport.bita-manual-sync', 1800);
        if (!$lock->get()) {
            $run->update([
                'status' => 'failed',
                'finished_at' => now(),
                'error' => 'واکشی دیگری در حال اجراست.',
            ]);
            $this->error('واکشی دیگری در حال اجراست.');
            return self::FAILURE;
        }

        try {
            $exitCode = $this->performSync($bita);
            $run->update([
                'status' => $exitCode === self::SUCCESS ? 'success' : 'failed',
                'finished_at' => now(),
                'error' => $exitCode === self::SUCCESS ? null : ($run->error ?: 'فرمان واکشی با خطا پایان یافت.'),
            ]);
            return $exitCode;
        } catch (Throwable $exception) {
            report($exception);
            $run->update([
                'status' => 'failed',
                'finished_at' => now(),
                'error' => mb_substr($exception->getMessage(), 0, 4000),
            ]);
            $this->error($exception->getMessage());
            return self::FAILURE;
        } finally {
            $lock->release();
        }
    }

    private function performSync(BitaBillService $bita): int
    {
        $mode = strtolower(trim((string) $this->argument('mode')));
        if (!in_array($mode, ['pilisan', 'all'], true)) {
            $this->error('حالت باید pilisan یا all باشد.');
            return self::FAILURE;
        }

        $timezone = new \DateTimeZone('Asia/Tehran');
        $current = Jalalian::now($timezone);
        $fromOption = trim((string) $this->option('from'));

        if ($fromOption !== '') {
            $fromOption = strtr($fromOption, ['۰'=>'0','۱'=>'1','۲'=>'2','۳'=>'3','۴'=>'4','۵'=>'5','۶'=>'6','۷'=>'7','۸'=>'8','۹'=>'9','٠'=>'0','١'=>'1','٢'=>'2','٣'=>'3','٤'=>'4','٥'=>'5','٦'=>'6','٧'=>'7','٨'=>'8','٩'=>'9']);
            if (!preg_match('/^\d{4}\/\d{2}\/\d{2}$/', $fromOption)) {
                $this->error('فرمت تاریخ شروع باید YYYY/MM/DD باشد؛ نمونه: 1405/08/01');
                return self::FAILURE;
            }

            try {
                $fromDate = Jalalian::fromFormat('Y/m/d', $fromOption, $timezone)->toCarbon()->startOfDay();
            } catch (\Throwable $e) {
                $this->error('تاریخ شروع جلالی معتبر نیست.');
                return self::FAILURE;
            }
        } else {
            $fromDate = (new Jalalian($current->getYear(), $current->getMonth(), 1, 0, 0, 0, $timezone))->toCarbon()->startOfDay();
        }

        $cursor = $current->toCarbon()->endOfDay();
        if ($fromDate->gt($cursor)) {
            $this->error('تاریخ شروع نمی‌تواند بعد از تاریخ جاری باشد.');
            return self::FAILURE;
        }

        $total = 0;
        $rangeCount = 0;
        while ($cursor->gte($fromDate)) {
            $to = $cursor->copy()->endOfDay();
            $from = $cursor->copy()->subDays(6)->startOfDay();
            if ($from->lt($fromDate)) $from = $fromDate->copy();

            $fromJalali = Jalalian::fromCarbon($from);
            $toJalali = Jalalian::fromCarbon($to);
            $rangeLabel = sprintf('%04d/%02d/%02d تا %04d/%02d/%02d',
                $fromJalali->getYear(), $fromJalali->getMonth(), $fromJalali->getDay(),
                $toJalali->getYear(), $toJalali->getMonth(), $toJalali->getDay());

            try {
                $bills = $bita->searchRange($from, $to);
            } catch (Throwable $e) {
                $this->error("واکشی بازه {$rangeLabel} ناموفق بود: {$e->getMessage()}");
                return self::FAILURE;
            }

            $fetched = count($bills);
            if ($mode === 'pilisan') {
                $bills = $bita->registeredFleetBills($bills);
            }
            $matched = count($bills);
            $stored = $bita->storeBills($bills);
            $total += $stored;
            $rangeCount++;
            $this->line("{$rangeLabel}: {$fetched} دریافتی، {$matched} منطبق با حالت {$mode}، {$stored} ذخیره/به‌روزرسانی");
            $cursor = $from->copy()->subSecond();
        }

        $this->info("همگام‌سازی {$rangeCount} بازه هفتگی کامل شد؛ {$total} بارنامه ذخیره/به‌روزرسانی شد.");
        return self::SUCCESS;
    }
}
