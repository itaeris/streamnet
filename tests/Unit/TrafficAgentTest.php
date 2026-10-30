<?php

namespace Tests\Unit;

use App\Services\TrafficAgent;
use PHPUnit\Framework\TestCase;

class TrafficAgentTest extends TestCase
{
    public function test_ordinary_web_traffic_is_counted_and_not_flagged(): void
    {
        $report = (new TrafficAgent)->analyze([
            ['src-address' => '10.10.10.20:51000', 'dst-address' => '1.1.1.1:443', 'orig-rate' => '1000', 'repl-rate' => '8000'],
        ], ['10.10.10.20' => 'Nita']);

        $this->assertSame(0, $report['inbound']);
        $this->assertSame(1, $report['outbound']);
        $this->assertSame([], $report['findings']);
        $this->assertSame(100, $report['score']);
    }

    public function test_unusual_outbound_and_inbound_service_ports_are_flagged(): void
    {
        $report = (new TrafficAgent)->analyze([
            ['src-address' => '10.10.10.20:51000', 'dst-address' => '8.8.8.8:445', 'orig-rate' => '100', 'repl-rate' => '50'],
            ['src-address' => '9.9.9.9:40000', 'dst-address' => '10.10.10.1:8291', 'orig-rate' => '200', 'repl-rate' => '10'],
            ['src-address' => '203.0.113.8:443', 'dst-address' => '10.10.10.30:51515', 'orig-rate' => '1000', 'repl-rate' => '1000'],
            ['src-address' => '10.10.10.20:1', 'dst-address' => '10.10.10.30:80', 'orig-rate' => '1', 'repl-rate' => '1'],
        ], ['10.10.10.20' => 'Nita']);

        $this->assertSame(2, $report['inbound']);
        $this->assertSame(1, $report['outbound']);
        $this->assertSame(3, $report['suspicious']);
        $this->assertSame('Masuk ke port layanan', $report['findings'][0]['title']);
        $this->assertSame('critical', $report['findings'][0]['severity']);
        $this->assertSame('in', $report['findings'][0]['direction']);

        $outbound = null;
        foreach ($report['findings'] as $finding) {
            if ($finding['title'] === 'Port keluar tidak biasa') {
                $outbound = $finding;
            }
        }
        $this->assertNotNull($outbound);
        $this->assertSame('Nita', $outbound['local_name']);
        $this->assertSame(445, $outbound['port']);
        $this->assertSame('out', $outbound['direction']);
        $this->assertGreaterThanOrEqual(1, $report['score']);
        $this->assertLessThan(100, $report['score']);
    }

    public function test_a_host_talking_to_many_destinations_is_flagged(): void
    {
        $rows = [];
        for ($i = 1; $i <= 20; $i++) {
            $rows[] = ['src-address' => '192.168.1.5:1000', 'dst-address' => "203.0.113.{$i}:443", 'orig-rate' => '1', 'repl-rate' => '1'];
        }

        $report = (new TrafficAgent)->analyze($rows);

        $this->assertSame(20, $report['outbound']);
        $spread = null;
        foreach ($report['findings'] as $finding) {
            if ($finding['title'] === 'Banyak tujuan sekaligus') {
                $spread = $finding;
            }
        }
        $this->assertNotNull($spread);
        $this->assertSame('out', $spread['direction']);
        $this->assertStringContainsString('20 alamat', $spread['detail']);
    }

    public function test_a_users_own_upload_heavy_traffic_is_read(): void
    {
        $report = (new TrafficAgent)->analyze([
            ['src-address' => '10.10.10.20:51000', 'dst-address' => '1.1.1.1:443', 'orig-rate' => '8000000', 'repl-rate' => '100000'],
        ], [], [[
            'address' => '10.10.10.20',
            'name' => 'Nita',
            'mac' => 'AA:BB:CC:DD:EE:FF',
            'down' => 100000,
            'up' => 8000000,
        ]]);

        $this->assertSame(1, $report['suspicious']);
        $this->assertSame('Unggahan user jauh lebih besar', $report['findings'][0]['title']);
        $this->assertSame('Nita', $report['users'][0]['name']);
        $this->assertSame('AA:BB:CC:DD:EE:FF', $report['users'][0]['mac']);
        $this->assertSame(8000000, $report['users'][0]['up']);
        $this->assertSame('1.1.1.1', $report['users'][0]['flows'][0]['remote']);
        $this->assertSame(443, $report['users'][0]['flows'][0]['port']);
    }
}
