<?php

namespace App\Jobs;

use App\Models\Subscription;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;

class UpdateSubscriptionStatusesJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 5;

    public int $backoff = 60;

    public function handle(): void
    {
        $now = Carbon::now();

        DB::transaction(function () use ($now) {

            // 1. active -> expired (langganan sudah lewat tanggal habis)
            $expiredCount = Subscription::where('status', 'active')
                ->whereNotNull('ends_at')
                ->where('ends_at', '<=', $now)
                ->update(['status' => 'expired']);

            // 2. expired -> active (jika langganan diperpanjang)
            $reactivatedCount = Subscription::where('status', 'expired')
                ->whereNotNull('ends_at')
                ->where('ends_at', '>', $now)
                ->update(['status' => 'active']);

            Log::info('UpdateSubscriptionStatusesJob selesai', [
                'expired' => $expiredCount,
                'reactivated' => $reactivatedCount,
                'run_at' => $now->toDateTimeString(),
            ]);
        });
    }
}
