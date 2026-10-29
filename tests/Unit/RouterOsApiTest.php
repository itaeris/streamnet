<?php

namespace Tests\Unit;

use App\Services\Mikrotik\RouterOsApi;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class RouterOsApiTest extends TestCase
{
    public function test_sentence_encoding_uses_api_length_bytes(): void
    {
        $this->assertSame("\x06/login\x00", RouterOsApi::encodeSentence(['/login']));
        $this->assertSame("\x04test\x00", RouterOsApi::encodeSentence(['test']));
    }

    public function test_login_and_print_match_the_shapes_the_poller_expects(): void
    {
        [$client, $server] = $this->pair();
        fwrite($server, RouterOsApi::encodeSentence(['!done'])
            .RouterOsApi::encodeSentence(['!re', '=uptime=1d', '=total-memory=1000'])
            .RouterOsApi::encodeSentence(['!done'])
            .RouterOsApi::encodeSentence(['!re', '=.id=*1', '=name=ether1'])
            .RouterOsApi::encodeSentence(['!re', '=.id=*2', '=name=ether2'])
            .RouterOsApi::encodeSentence(['!done'])
            .RouterOsApi::encodeSentence(['!done', '=ret=12']));

        $api = new RouterOsApi($client);
        $api->login('wiki', 'secret');

        $resource = $api->query('get', 'system/resource', []);
        $interfaces = $api->query('get', 'interface', []);
        $count = $api->query('post', 'ip/firewall/connection/print', ['count-only' => '']);

        $this->assertSame('1000', $resource['total-memory']);
        $this->assertCount(2, $interfaces);
        $this->assertSame('ether1', $interfaces[0]['name']);
        $this->assertSame('12', $count['ret']);

        stream_set_blocking($server, false);
        $written = stream_get_contents($server);
        $this->assertStringContainsString('/login', $written);
        $this->assertStringContainsString('=name=wiki', $written);
        $this->assertStringContainsString('=password=secret', $written);
        $this->assertStringContainsString('/system/resource/print', $written);
        $this->assertStringContainsString('/interface/print', $written);
        $this->assertStringContainsString('/ip/firewall/connection/print', $written);
        $this->assertStringContainsString('=count-only=', $written);
    }

    public function test_trap_surfaces_the_router_message(): void
    {
        [$client, $server] = $this->pair();
        fwrite($server, RouterOsApi::encodeSentence(['!trap', '=message=invalid user name or password'])
            .RouterOsApi::encodeSentence(['!done']));

        $api = new RouterOsApi($client);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('invalid user name or password');
        $api->login('wiki', 'salah');
    }

    /**
     * @return array{0: resource, 1: resource}
     */
    private function pair(): array
    {
        $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        $this->assertIsArray($pair);
        stream_set_blocking($pair[0], true);
        stream_set_blocking($pair[1], true);

        return $pair;
    }
}
