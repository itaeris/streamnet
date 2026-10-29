<?php

namespace App\Console\Commands;

use App\Models\LiveSnapshot;
use App\Models\MikrotikSession;
use App\Services\Mikrotik\DeliveredRates;
use Illuminate\Console\Command;
use Throwable;

class TorchMikrotik extends Command
{
    protected $signature = 'mikrotik:torch {--once : Ukur sekali lalu berhenti}';

    protected $description = 'Ukur rate per klien setelah queue dengan Torch di interface LAN';

    public function handle(DeliveredRates $rates): int
    {
        if (! config('mikrotik.torch')) {
            $this->components->warn('MIKROTIK_TORCH=false, pengukuran setelah queue dimatikan.');

            return self::SUCCESS;
        }

        $every = max(3, (int) config('mikrotik.torch_seconds'));
        $quietUntil = [];

        do {
            $started = microtime(true);

            foreach (MikrotikSession::query()->where('enabled', true)->orderBy('id')->get() as $session) {
                if (($quietUntil[$session->id] ?? 0) > microtime(true) || ! $this->online($session)) {
                    continue;
                }

                try {
                    $delivered = $rates->measure($session);
                } catch (Throwable $exception) {
                    $this->components->error($exception->getMessage());
                    $quietUntil[$session->id] = microtime(true) + 30;

                    continue;
                }

                if ($delivered === null) {
                    $quietUntil[$session->id] = microtime(true) + 30;

                    continue;
                }

                $rates->store($session, $delivered);
                $down = array_sum(array_column($delivered['clients'], 'down'));
                $this->components->info(sprintf(
                    '%s  %s  %d klien  ↓ %.1f Mbps',
                    $session->name,
                    $delivered['interface'],
                    count($delivered['clients']),
                    $down / 1_000_000,
                ));
            }

            if ($this->option('once')) {
                return self::SUCCESS;
            }

            $sleep = $every - (microtime(true) - $started);
            if ($sleep > 0) {
                usleep((int) ($sleep * 1_000_000));
            }
        } while (true);
    }

    private function online(MikrotikSession $session): bool
    {
        $pulse = LiveSnapshot::query()
            ->where('mikrotik_session_id', $session->id)
            ->where('key', 'pulse')
            ->first()?->payload;

        return (bool) ($pulse['online'] ?? false);
    }
}
