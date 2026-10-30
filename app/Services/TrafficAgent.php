<?php

namespace App\Services;

class TrafficAgent
{
    /** @var array<int, string> */
    private const OUTBOUND_PORTS = [
        23 => 'Telnet',
        135 => 'RPC',
        139 => 'NetBIOS',
        445 => 'SMB',
        1433 => 'MSSQL',
        3306 => 'MySQL',
        3389 => 'RDP',
        4444 => 'port tidak biasa',
        5432 => 'Postgres',
        5900 => 'VNC',
        6379 => 'Redis',
        6667 => 'IRC',
        8291 => 'Winbox',
        8728 => 'API RouterOS',
        8729 => 'API RouterOS',
        9200 => 'Elasticsearch',
        27017 => 'MongoDB',
    ];

    /** @var array<int, string> */
    private const INBOUND_SENSITIVE = [
        21 => 'FTP',
        22 => 'SSH',
        23 => 'Telnet',
        445 => 'SMB',
        3389 => 'RDP',
        5900 => 'VNC',
        8291 => 'Winbox',
        8728 => 'API RouterOS',
        8729 => 'API RouterOS',
    ];

    /**
     * @param  array<int, array<string, mixed>>  $rows
     * @param  array<string, string>  $names
     * @param  array<int, array<string, mixed>>  $users
     * @return array{inbound: int, outbound: int, suspicious: int, score: int, findings: array<int, array<string, mixed>>, users: array<int, array<string, mixed>>}
     */
    public function analyze(array $rows, array $names = [], array $users = []): array
    {
        foreach ($users as $user) {
            if (! is_array($user)) {
                continue;
            }

            $address = trim((string) ($user['address'] ?? ''));
            $label = trim((string) ($user['name'] ?? ''));
            if ($address !== '' && $label !== '') {
                $names[$address] = $label;
            }
        }

        $flows = [];
        $remotesByLocal = [];
        $sourcesByLocal = [];
        $inbound = 0;
        $outbound = 0;

        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }

            [$src, $srcPort] = $this->endpoint((string) ($row['src-address'] ?? ''));
            [$dst, $dstPort] = $this->endpoint((string) ($row['dst-address'] ?? ''));
            if ($src === null || $dst === null || $src === $dst) {
                continue;
            }

            $orig = $this->integer($row['orig-rate'] ?? 0);
            $repl = $this->integer($row['repl-rate'] ?? 0);
            $srcLan = $this->lan($src);
            $dstLan = $this->lan($dst);

            if ($srcLan && ! $dstLan) {
                $outbound++;
                $key = "out|{$src}|{$dst}|".($dstPort ?? '');
                $flows[$key] = $this->flow($flows[$key] ?? null, 'out', $src, $dst, $dstPort, $orig, $repl);
                $remotesByLocal[$src][$dst] = true;
            } elseif (! $srcLan && $dstLan) {
                $inbound++;
                $key = "in|{$dst}|{$src}|".($dstPort ?? '');
                $flows[$key] = $this->flow($flows[$key] ?? null, 'in', $dst, $src, $dstPort, $repl, $orig);
                $sourcesByLocal[$dst][$src] = true;
            }
        }

        $findings = [];
        foreach ($flows as $flow) {
            $reason = $this->reason($flow);
            if ($reason === null) {
                continue;
            }

            $findings[] = $this->finding($flow, $reason, $names);
        }

        foreach ($remotesByLocal as $local => $remotes) {
            if (count($remotes) < 20) {
                continue;
            }

            $findings[] = $this->spread('out', 'warning', 'Banyak tujuan sekaligus', $local, count($remotes), 'menghubungi', $names);
        }

        foreach ($sourcesByLocal as $local => $sources) {
            if (count($sources) < 8) {
                continue;
            }

            $findings[] = $this->spread('in', 'critical', 'Banyak sumber luar', $local, count($sources), 'dihubungi oleh', $names);
        }

        foreach ($users as $user) {
            if (! is_array($user)) {
                continue;
            }

            $address = trim((string) ($user['address'] ?? ''));
            $down = $this->integer($user['down'] ?? 0);
            $up = $this->integer($user['up'] ?? 0);
            if ($address === '' || ! $this->lan($address) || $up < 2_000_000 || $up < max(1, $down) * 8) {
                continue;
            }

            $findings[] = [
                'direction' => 'out',
                'severity' => 'warning',
                'title' => 'Unggahan user jauh lebih besar',
                'detail' => $this->label($address, $names).' mengunggah jauh lebih banyak daripada yang diunduh.',
                'local' => $address,
                'local_name' => $this->name($address, $names),
                'remote' => null,
                'port' => null,
                'down' => $down,
                'up' => $up,
            ];
        }

        usort($findings, function (array $left, array $right): int {
            $rank = ['critical' => 2, 'warning' => 1];
            $bySeverity = ($rank[$right['severity']] ?? 0) <=> ($rank[$left['severity']] ?? 0);

            return $bySeverity !== 0 ? $bySeverity : (($right['down'] + $right['up']) <=> ($left['down'] + $left['up']));
        });

        return [
            'inbound' => $inbound,
            'outbound' => $outbound,
            'suspicious' => count($findings),
            'score' => $this->score($inbound, $outbound, $findings),
            'findings' => array_slice($findings, 0, 40),
            'users' => $this->people($findings, $flows, $users, $names),
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $findings
     */
    private function score(int $inbound, int $outbound, array $findings): int
    {
        $weight = 0;
        foreach ($findings as $finding) {
            $weight += ($finding['severity'] ?? '') === 'critical' ? 8 : 3;
        }

        $penalty = (int) round(100 * $weight / max(1, $inbound + $outbound + $weight));

        return max(1, min(100, 100 - min(99, $penalty)));
    }

    /**
     * @param  array<int, array<string, mixed>>  $findings
     * @param  array<string, array{direction: string, local: string, remote: string, port: int|null, down: int, up: int}>  $flows
     * @param  array<int, array<string, mixed>>  $users
     * @param  array<string, string>  $names
     * @return array<int, array<string, mixed>>
     */
    private function people(array $findings, array $flows, array $users, array $names): array
    {
        $profiles = [];
        foreach ($users as $user) {
            if (! is_array($user)) {
                continue;
            }

            $address = trim((string) ($user['address'] ?? ''));
            if ($address !== '') {
                $profiles[$address] = $user;
            }
        }

        $grouped = [];
        foreach ($findings as $finding) {
            $local = (string) ($finding['local'] ?? '');
            if ($local === '') {
                continue;
            }

            $grouped[$local]['reasons'][(string) $finding['title']] = true;
            if (($grouped[$local]['severity'] ?? '') !== 'critical') {
                $grouped[$local]['severity'] = (string) $finding['severity'];
            }
        }

        $byLocal = [];
        foreach ($flows as $flow) {
            $byLocal[$flow['local']][] = $flow;
        }

        $people = [];
        foreach ($grouped as $local => $info) {
            $profile = $profiles[$local] ?? [];
            $list = $byLocal[$local] ?? [];
            usort($list, function (array $left, array $right): int {
                $rank = fn (array $flow): int => ($flow['direction'] === 'in' || isset(self::OUTBOUND_PORTS[(int) $flow['port']])) ? 1 : 0;
                $byKind = $rank($right) <=> $rank($left);

                return $byKind !== 0 ? $byKind : (($right['down'] + $right['up']) <=> ($left['down'] + $left['up']));
            });
            $down = $this->integer($profile['down'] ?? 0);
            $up = $this->integer($profile['up'] ?? 0);
            if ($down === 0 && $up === 0) {
                foreach ($list as $flow) {
                    $down += $flow['down'];
                    $up += $flow['up'];
                }
            }

            $mac = trim((string) ($profile['mac'] ?? ''));
            $people[] = [
                'address' => $local,
                'name' => $this->name($local, $names),
                'mac' => $mac !== '' ? $mac : null,
                'down' => $down,
                'up' => $up,
                'severity' => $info['severity'] ?? 'warning',
                'reasons' => array_keys($info['reasons']),
                'flows' => array_map(fn (array $flow) => [
                    'direction' => $flow['direction'],
                    'remote' => $flow['remote'],
                    'port' => $flow['port'],
                    'down' => $flow['down'],
                    'up' => $flow['up'],
                ], array_slice($list, 0, 5)),
            ];
        }

        usort($people, function (array $left, array $right): int {
            $rank = ['critical' => 2, 'warning' => 1];
            $bySeverity = ($rank[$right['severity']] ?? 0) <=> ($rank[$left['severity']] ?? 0);

            return $bySeverity !== 0 ? $bySeverity : (($right['down'] + $right['up']) <=> ($left['down'] + $left['up']));
        });

        return array_slice($people, 0, 12);
    }

    /**
     * @param  array{direction: string, local: string, remote: string, port: int|null, down: int, up: int}|null  $current
     * @return array{direction: string, local: string, remote: string, port: int|null, down: int, up: int}
     */
    private function flow(?array $current, string $direction, string $local, string $remote, ?int $port, int $up, int $down): array
    {
        return [
            'direction' => $direction,
            'local' => $local,
            'remote' => $remote,
            'port' => $port,
            'down' => ($current['down'] ?? 0) + $down,
            'up' => ($current['up'] ?? 0) + $up,
        ];
    }

    /**
     * @param  array{direction: string, local: string, remote: string, port: int|null, down: int, up: int}  $flow
     * @return array{0: string, 1: string, 2: string}|null
     */
    private function reason(array $flow): ?array
    {
        $port = $flow['port'];
        if ($flow['direction'] === 'out') {
            if ($port === null || ! isset(self::OUTBOUND_PORTS[$port])) {
                return null;
            }

            return ['warning', 'Port keluar tidak biasa', 'Klien menghubungi '.self::OUTBOUND_PORTS[$port].' di port '.$port.'.'];
        }

        if ($port !== null && isset(self::INBOUND_SENSITIVE[$port])) {
            return ['critical', 'Masuk ke port layanan', 'Alamat luar menghubungi '.self::INBOUND_SENSITIVE[$port].' di port '.$port.'.'];
        }

        return ['warning', 'Koneksi masuk dari luar', 'Alamat luar membuka koneksi ke jaringan lokal.'];
    }

    /**
     * @param  array{direction: string, local: string, remote: string, port: int|null, down: int, up: int}  $flow
     * @param  array{0: string, 1: string, 2: string}  $reason
     * @param  array<string, string>  $names
     * @return array<string, mixed>
     */
    private function finding(array $flow, array $reason, array $names): array
    {
        return [
            'direction' => $flow['direction'],
            'severity' => $reason[0],
            'title' => $reason[1],
            'detail' => $reason[2],
            'local' => $flow['local'],
            'local_name' => $this->name($flow['local'], $names),
            'remote' => $flow['remote'],
            'port' => $flow['port'],
            'down' => $flow['down'],
            'up' => $flow['up'],
        ];
    }

    /**
     * @param  array<string, string>  $names
     * @return array<string, mixed>
     */
    private function spread(string $direction, string $severity, string $title, string $local, int $count, string $verb, array $names): array
    {
        return [
            'direction' => $direction,
            'severity' => $severity,
            'title' => $title,
            'detail' => $this->label($local, $names).' '.$verb.' '.$count.' alamat dalam satu cuplikan.',
            'local' => $local,
            'local_name' => $this->name($local, $names),
            'remote' => null,
            'port' => null,
            'down' => 0,
            'up' => 0,
        ];
    }

    /**
     * @return array{0: string|null, 1: int|null}
     */
    private function endpoint(string $value): array
    {
        $value = trim($value);
        if (preg_match('/^(\d{1,3}(?:\.\d{1,3}){3}):(\d+)$/', $value, $match) === 1) {
            return [$match[1], (int) $match[2]];
        }

        if (filter_var($value, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            return [$value, null];
        }

        return [null, null];
    }

    private function lan(string $ip): bool
    {
        return filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false
            && filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 | FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false;
    }

    /**
     * @param  array<string, string>  $names
     */
    private function name(string $ip, array $names): ?string
    {
        $name = trim((string) ($names[$ip] ?? ''));

        return $name !== '' ? $name : null;
    }

    /**
     * @param  array<string, string>  $names
     */
    private function label(string $ip, array $names): string
    {
        $name = $this->name($ip, $names);

        return $name === null ? $ip : $name.' ('.$ip.')';
    }

    private function integer(mixed $value): int
    {
        return (int) $value;
    }
}
