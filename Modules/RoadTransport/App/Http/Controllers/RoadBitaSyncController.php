<?php

namespace Modules\RoadTransport\App\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Console\Command;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Modules\RoadTransport\App\Models\RoadBitaSyncRun;
use Throwable;

class RoadBitaSyncController extends Controller
{
    public function latest()
    {
        return response()->json(['data' => RoadBitaSyncRun::query()
            ->orderByRaw('COALESCE(finished_at, started_at) DESC')
            ->orderByDesc('id')
            ->first()]);
    }

    public function sync(Request $request)
    {
        $validated = $request->validate([
            'from' => ['required', 'regex:/^\d{4}\/\d{2}\/\d{2}$/'],
            'to' => ['required', 'regex:/^\d{4}\/\d{2}\/\d{2}$/'],
        ]);
        $run = RoadBitaSyncRun::create([
            'status' => 'running',
            'started_at' => now(),
            'triggered_by' => $request->user()?->id,
        ]);

        try {
            $exitCode = Artisan::call('road-transport:sync-bills', [
                'mode' => 'pilisan',
                '--from' => $validated['from'],
                '--to' => $validated['to'],
                '--run-id' => $run->id,
            ]);
            $output = trim(Artisan::output());
            $run->update([
                'status' => $exitCode === Command::SUCCESS ? 'success' : 'failed',
                'finished_at' => now(),
                'output' => $output !== '' ? mb_substr($output, 0, 12000) : null,
                'error' => $exitCode === Command::SUCCESS ? null : ($output ?: 'فرمان واکشی با خطا پایان یافت.'),
            ]);
        } catch (Throwable $exception) {
            report($exception);
            $run->update([
                'status' => 'failed',
                'finished_at' => now(),
                'error' => mb_substr($exception->getMessage(), 0, 4000),
            ]);
        }

        return response()->json(['data' => $run->fresh()]);
    }
}
