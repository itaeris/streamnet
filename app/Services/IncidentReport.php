<?php

namespace App\Services;

use App\Models\DhcpLease;
use App\Models\SecurityEvent;
use Carbon\Carbon;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Writer\XLSX\Writer;

class IncidentReport
{
    public const RANGES = [
        'today' => 'Hari ini',
        'yesterday' => 'Kemarin',
        '7d' => '7 hari terakhir',
        '30d' => '30 hari terakhir',
    ];

    /**
     * @return array{start: Carbon, end: Carbon, label: string}
     */
    public function window(string $range): array
    {
        $now = now();

        return match ($range) {
            'today' => ['start' => $now->copy()->startOfDay(), 'end' => $now, 'label' => self::RANGES['today']],
            'yesterday' => ['start' => $now->copy()->subDay()->startOfDay(), 'end' => $now->copy()->subDay()->endOfDay(), 'label' => self::RANGES['yesterday']],
            '30d' => ['start' => $now->copy()->subDays(30), 'end' => $now, 'label' => self::RANGES['30d']],
            default => ['start' => $now->copy()->subDays(7), 'end' => $now, 'label' => self::RANGES['7d']],
        };
    }

    /**
     * Bandwidth incidents with the client details pulled out of the stored context or the detail text.
     *
     * @return array<int, array<string, mixed>>
     */
    public function rows(?int $sessionId, Carbon $start, Carbon $end): array
    {
        if ($sessionId === null) {
            return [];
        }

        $events = SecurityEvent::query()
            ->where('mikrotik_session_id', $sessionId)
            ->where('category', 'bandwidth')
            ->whereBetween('occurred_at', [$start, $end])
            ->orderByDesc('occurred_at')
            ->limit(5000)
            ->get();

        $addresses = $events->map(fn (SecurityEvent $event) => $this->address($event))->filter()->unique()->values();
        $macs = DhcpLease::query()
            ->where('mikrotik_session_id', $sessionId)
            ->whereIn('address', $addresses)
            ->pluck('mac_address', 'address');

        return $events->map(function (SecurityEvent $event) use ($macs) {
            $client = $event->context['client'] ?? [];
            $address = $this->address($event);
            $detail = (string) $event->detail;
            preg_match('/download\s+([\d.]+)\s*Mbps/i', $detail, $rate);
            preg_match('/(\d+)%\s*kapasitas/i', $detail, $share);

            return [
                ...$event->toPayload(),
                'name' => $client['name'] ?? (trim(strstr($detail, ' (', true) ?: '') ?: $address),
                'address' => $address,
                'mac' => $client['mac'] ?? ($macs[$address] ?? null),
                'down' => isset($client['down']) ? (int) $client['down'] : (isset($rate[1]) ? (int) round((float) $rate[1] * 1_000_000) : null),
                'share' => $client['share'] ?? (isset($share[1]) ? (int) $share[1] : null),
                'kind' => $event->context['kind'] ?? null,
            ];
        })->values()->all();
    }

    /**
     * One line per client: how often, how hard, and the usual cause.
     *
     * @param  array<int, array<string, mixed>>  $rows
     * @return array<int, array<string, mixed>>
     */
    public function recap(array $rows): array
    {
        return collect($rows)
            ->groupBy(fn (array $row) => $row['address'] ?: $row['name'])
            ->map(function ($group) {
                $rates = $group->pluck('down')->filter(fn ($value) => $value !== null);
                $kinds = $group->pluck('kind')->filter(fn ($kind) => $kind && $kind !== 'Tidak diketahui')->countBy()->sortDesc();
                $latest = $group->sortByDesc('time')->first();

                return [
                    'name' => $latest['name'],
                    'address' => $latest['address'],
                    'mac' => $group->pluck('mac')->filter()->first(),
                    'count' => $group->count(),
                    'max_down' => $rates->max(),
                    'avg_down' => $rates->isEmpty() ? null : (int) round($rates->avg()),
                    'first' => $group->min('time'),
                    'last' => $group->max('time'),
                    'kind' => $kinds->keys()->first(),
                ];
            })
            ->sortByDesc('count')
            ->values()
            ->all();
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     * @return string path of a temporary .xlsx file
     */
    public function export(array $rows, string $label): string
    {
        $path = tempnam(sys_get_temp_dir(), 'insiden').'.xlsx';
        $bold = (new Style)->withFontBold(true);
        $mbps = fn (?int $bps) => $bps === null ? null : round($bps / 1_000_000, 1);

        $writer = new Writer;
        $writer->openToFile($path);

        $writer->getCurrentSheet()->setName('Rekap');
        $writer->addRow(Row::fromValuesWithStyle(["Rekap insiden bandwidth · {$label}"], $bold));
        $writer->addRow(Row::fromValues(['Dibuat '.now()->format('Y-m-d H:i')]));
        $writer->addRow(Row::fromValues([]));
        $writer->addRow(Row::fromValuesWithStyle(['Klien', 'IP address', 'MAC address', 'Jumlah insiden', 'Tertinggi (Mbps)', 'Rata-rata (Mbps)', 'Pertama', 'Terakhir', 'Dugaan penyebab'], $bold));
        foreach ($this->recap($rows) as $line) {
            $writer->addRow(Row::fromValues([
                $line['name'],
                $line['address'],
                $line['mac'],
                $line['count'],
                $mbps($line['max_down']),
                $mbps($line['avg_down']),
                $line['first'],
                $line['last'],
                $line['kind'],
            ]));
        }
        $writer->getCurrentSheet()->setColumnWidth(22, 1, 9);
        $writer->getCurrentSheet()->setColumnWidth(16, 2, 4, 5, 6);
        $writer->getCurrentSheet()->setColumnWidth(19, 3, 7, 8);

        $writer->addNewSheetAndMakeItCurrent()->setName('Insiden');
        $writer->addRow(Row::fromValuesWithStyle(['Waktu', 'Klien', 'IP address', 'MAC address', 'Insiden', 'Download (Mbps)', 'Porsi kapasitas (%)', 'Dugaan penyebab', 'Detail'], $bold));
        foreach ($rows as $row) {
            $writer->addRow(Row::fromValues([
                $row['time'],
                $row['name'],
                $row['address'],
                $row['mac'],
                $row['title'],
                $mbps($row['down']),
                $row['share'],
                $row['kind'],
                $row['detail'],
            ]));
        }
        $writer->getCurrentSheet()->setColumnWidth(19, 1, 4);
        $writer->getCurrentSheet()->setColumnWidth(22, 2, 5, 8);
        $writer->getCurrentSheet()->setColumnWidth(15, 3, 6, 7);
        $writer->getCurrentSheet()->setColumnWidth(48, 9);

        $writer->close();

        return $path;
    }

    private function address(SecurityEvent $event): string
    {
        $address = $event->context['client']['address'] ?? null;
        if (is_string($address) && $address !== '') {
            return $address;
        }

        return preg_match('/\((\d{1,3}(?:\.\d{1,3}){3})\)/', (string) $event->detail, $match) ? $match[1] : '';
    }
}
