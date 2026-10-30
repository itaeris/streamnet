<?php

namespace App\Services;

use App\Models\DnsFilterWindow;
use App\Models\MikrotikSession;
use App\Services\Mikrotik\DnsFilter;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

class DnsFilterSchedule
{
    public function __construct(private DnsFilter $filter) {}

    /**
     * The shortest window that covers this minute wins, so a one-hour break
     * beats a long overnight block when they meet on the same minute.
     *
     * @param  Collection<int, DnsFilterWindow>|iterable<int, DnsFilterWindow>  $windows
     * @return array{blocked: bool, label: string}|null
     */
    public function current(iterable $windows, CarbonInterface $at): ?array
    {
        $minute = ((int) $at->format('H')) * 60 + (int) $at->format('i');
        $match = null;
        $span = PHP_INT_MAX;

        foreach ($windows as $window) {
            $start = $this->minutes($window->starts_at);
            $end = $this->minutes($window->ends_at);
            if ($start === null || $end === null || ! $this->covers($start, $end, $minute)) {
                continue;
            }

            $length = $start <= $end ? $end - $start : (1440 - $start) + $end;
            if ($length < $span) {
                $span = $length;
                $match = ['blocked' => (bool) $window->blocked, 'label' => $window->label];
            }
        }

        return $match;
    }

    public function enforce(MikrotikSession $session, ?CarbonInterface $at = null): void
    {
        $windows = DnsFilterWindow::query()
            ->where('mikrotik_session_id', $session->id)
            ->get()
            ->groupBy('domain');
        if ($windows->isEmpty()) {
            return;
        }

        $current = $this->filter->read($session);
        if (! $current['online']) {
            return;
        }

        $at = ($at ?? now())->timezone((string) config('app.timezone'));
        foreach ($windows as $domain => $rows) {
            $wanted = $this->current($rows, $at);
            $site = collect($current['sites'])->firstWhere('domain', $domain);
            if ($wanted === null || $site === null || (bool) $site['enabled'] === $wanted['blocked']) {
                continue;
            }

            $this->filter->toggle($session, (string) $domain, $wanted['blocked']);
            $current = $this->filter->read($session);
            if (! $current['online']) {
                return;
            }
        }
    }

    private function minutes(string $time): ?int
    {
        if (! preg_match('/^(\d{2}):(\d{2})$/', $time, $match)) {
            return null;
        }

        $hour = (int) $match[1];
        $minute = (int) $match[2];
        if ($hour > 23 || $minute > 59) {
            return null;
        }

        return $hour * 60 + $minute;
    }

    private function covers(int $start, int $end, int $minute): bool
    {
        if ($start <= $end) {
            return $minute >= $start && $minute <= $end;
        }

        return $minute >= $start || $minute <= $end;
    }
}
