<?php

namespace Tests\Unit;

use App\Services\DnsActivity;
use PHPUnit\Framework\TestCase;

class DnsActivityTest extends TestCase
{
    public function test_it_reads_a_routeros_dns_query(): void
    {
        $parsed = (new DnsActivity)->parse('dns', 'query from 10.10.10.23: #9 instagram.com. A');

        $this->assertSame('10.10.10.23', $parsed['address']);
        $this->assertSame('instagram.com', $parsed['domain']);
        $this->assertSame('Instagram', $parsed['app']);
        $this->assertNull($parsed['browser']);
    }

    public function test_it_guesses_what_kind_of_traffic_a_domain_carries(): void
    {
        $dns = new DnsActivity;

        $this->assertSame('Streaming video', $dns->kind('rr3---sn-npoe7n7s.googlevideo.com'));
        $this->assertSame('Update & unduhan software', $dns->kind('2.tlu.dl.delivery.mp.microsoft.com'));
        $this->assertSame('Cloud sync & backup', $dns->kind('blob.lvl01prdstr04a.store.core.windows.net'));
        $this->assertSame('Browsing & lainnya', $dns->kind('kompas.com'));
        $this->assertSame('Tidak diketahui', $dns->kind(null));
    }

    public function test_it_points_an_app_at_its_own_website(): void
    {
        $dns = new DnsActivity;

        $this->assertSame('youtube.com', $dns->website('YouTube'));
        $this->assertSame('instagram.com', $dns->website('Instagram'));
        $this->assertSame('microsoft.com', $dns->website('Microsoft'));
    }

    public function test_it_ignores_reverse_lookups(): void
    {
        $this->assertNull((new DnsActivity)->parse('dns', 'query from 10.10.10.23: #9 1.0.0.10.in-addr.arpa. PTR'));
    }
}
