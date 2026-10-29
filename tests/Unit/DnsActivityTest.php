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

    public function test_it_ignores_reverse_lookups(): void
    {
        $this->assertNull((new DnsActivity)->parse('dns', 'query from 10.10.10.23: #9 1.0.0.10.in-addr.arpa. PTR'));
    }
}
