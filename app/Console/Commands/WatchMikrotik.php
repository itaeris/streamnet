<?php

namespace App\Console\Commands;

use App\Events\MikrotikPulse;
use App\Models\MikrotikSession;
use App\Services\Mikrotik\MikrotikPoller;
use App\Services\Mikrotik\MikrotikSessionRegistry;
use Illuminate\Console\Command;
use Throwable;

class WatchMikrotik extends Command
{
    protected $signature = 'mikrotik:watch {--once : Ambil satu sampel lalu berhenti}';

    protected $description = 'Poll MikroTik dan siarkan pembaruan lewat Reverb';

    public function handle(MikrotikPoller $poller, MikrotikSessionRegistry $sessions): int
    {
        if ($this->option('once')) {
            return $this->broadcastAll($poller->pollEnabled());
        }

        $sessions->ensureDefault();
        $fullEvery = max(2, (int) config('mikrotik.poll_seconds'));
        $lastFull = [];
        $quietUntil = [];

        do {
            $started = microtime(true);
            $rows = MikrotikSession::query()->where('enabled', true)->orderBy('id')->get();

            if ($rows->isEmpty()) {
                $this->broadcastAll($poller->pollEnabled());
            }

            foreach ($rows as $session) {
                $id = $session->id;
                if (($quietUntil[$id] ?? 0) > microtime(true)) {
                    continue;
                }

                $full = ! isset($lastFull[$id]) || (microtime(true) - $lastFull[$id]) >= $fullEvery;

                try {
                    $payload = $full ? $poller->poll($session) : $poller->refreshRates($session);
                } catch (Throwable $exception) {
                    $this->components->error($exception->getMessage());
                    $quietUntil[$id] = microtime(true) + 15;

                    continue;
                }

                if ($payload['online'] ?? false) {
                    unset($quietUntil[$id]);
                    if ($full) {
                        $lastFull[$id] = microtime(true);
                    }
                } else {
                    $quietUntil[$id] = microtime(true) + 15;
                }

                $this->broadcast($payload);
                $this->report($payload);
            }

            $sleep = 1 - (microtime(true) - $started);
            if ($sleep > 0) {
                usleep((int) ($sleep * 1_000_000));
            }
        } while (true);
    }

    /**
     * @param  array<int, array<string, mixed>>  $payloads
     */
    private function broadcastAll(array $payloads): int
    {
        foreach ($payloads as $payload) {
            $this->broadcast($payload);
            $this->report($payload);
        }

        return self::SUCCESS;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function broadcast(array $payload): void
    {
        try {
            event(new MikrotikPulse($payload));
        } catch (Throwable $exception) {
            $this->components->warn('Broadcast gagal: '.$exception->getMessage());
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function report(array $payload): void
    {
        if (! ($payload['online'] ?? false)) {
            $this->components->error($payload['error'] ?? 'Router offline');

            return;
        }

        $wan = $payload['wan'] ?? [];
        $summary = $payload['lease_summary'] ?? [];
        $this->components->info(sprintf(
            '%s  %s  cpu %s%%  wan %s  lease %s  conn %s  log +%s',
            $payload['session_name'] ?? 'router',
            $payload['sampled_label'] ?? now()->format('H:i:s'),
            $payload['system']['cpu_load'] ?? 0,
            ($wan['name'] ?? '-'),
            $summary['bound'] ?? 0,
            $payload['security']['connections'] ?? 0,
            count($payload['logs'] ?? []),
        ));
    }
}
