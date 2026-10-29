<?php

namespace Tests\Feature;

use App\Services\SiteLogo;
use Illuminate\Support\Facades\Http;
use Mockery;
use Tests\TestCase;

class SiteLogoTest extends TestCase
{
    public function test_it_only_accepts_a_public_website_name(): void
    {
        $logos = new SiteLogo;

        $this->assertTrue($logos->allowed('youtube.com'));
        $this->assertFalse($logos->allowed('localhost'));
        $this->assertFalse($logos->allowed('router.local'));
        $this->assertFalse($logos->allowed('10.10.10.1'));
        $this->assertFalse($logos->allowed('youtube'));
    }

    public function test_it_reads_the_icon_the_website_declares(): void
    {
        $url = (new SiteLogo)->iconUrl('youtube.com', '<html><link rel="icon" href="/yts/img/favicon.png"><link rel="apple-touch-icon" href="/apple.png"></html>');

        $this->assertSame('https://youtube.com/apple.png', $url);
    }

    public function test_it_stores_the_image_from_the_website(): void
    {
        Http::fake([
            'https://youtube.com/' => Http::response('<html><link rel="icon" href="/favicon.ico"></html>', 200),
            'https://youtube.com/favicon.ico' => Http::response("\x89PNG\r\nlogo", 200, ['Content-Type' => 'image/png']),
        ]);

        $logos = Mockery::mock(SiteLogo::class)->makePartial()->shouldAllowMockingProtectedMethods();
        $logos->shouldReceive('addresses')->andReturn(['142.250.4.190']);

        $image = $logos->fetch('youtube.com');

        $this->assertSame('image/png', $image['type']);
        $this->assertStringStartsWith("\x89PNG", $image['body']);
    }
}
