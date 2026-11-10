<?php

namespace App\Services;

use App\Models\DnsQuery;
use App\Models\InterfaceSample;
use App\Models\QueueSample;
use App\Models\RouterLog;
use App\Models\SecurityEvent;
use App\Models\SystemSample;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class SampleRetention
{
    public const DAYS = 30;

    /**
     * Drop history the way a recorder drops footage: anything past the window goes, in small batches.
     */
    public function prune(): void
    {
        $cutoff = now()->subDays(self::DAYS);

        $this->deleteOlder(SystemSample::class, 'sampled_at', $cutoff);
        $this->deleteOlder(InterfaceSample::class, 'sampled_at', $cutoff);
        $this->deleteOlder(QueueSample::class, 'sampled_at', $cutoff);
        $this->deleteOlder(RouterLog::class, 'logged_at', $cutoff);
        $this->deleteOlder(DnsQuery::class, 'logged_at', $cutoff);
        $this->deleteOlder(SecurityEvent::class, 'occurred_at', $cutoff);
        $this->deleteSessions($cutoff);
        $this->deleteFailedJobs($cutoff);
    }

    /**
     * @param  class-string  $model
     */
    private function deleteOlder(string $model, string $column, Carbon $cutoff): void
    {
        for ($pass = 0; $pass < 25; $pass++) {
            $ids = $model::query()
                ->where($column, '<', $cutoff)
                ->orderBy('id')
                ->limit(4000)
                ->pluck('id');

            if ($ids->isEmpty()) {
                return;
            }

            $model::query()->whereIn('id', $ids)->delete();

            if ($ids->count() < 4000) {
                return;
            }
        }
    }

    private function deleteSessions(Carbon $cutoff): void
    {
        if (! Schema::hasTable('sessions')) {
            return;
        }

        DB::table('sessions')->where('last_activity', '<', $cutoff->getTimestamp())->delete();
    }

    private function deleteFailedJobs(Carbon $cutoff): void
    {
        if (! Schema::hasTable('failed_jobs') || ! Schema::hasColumn('failed_jobs', 'failed_at')) {
            return;
        }

        DB::table('failed_jobs')->where('failed_at', '<', $cutoff)->delete();
    }
}
