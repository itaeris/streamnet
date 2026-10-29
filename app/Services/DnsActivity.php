<?php

namespace App\Services;

class DnsActivity
{
    /**
     * @var array<string, string>
     */
    private array $apps = [
        'cdninstagram.com' => 'Instagram',
        'instagram.com' => 'Instagram',
        'facebook.com' => 'Facebook',
        'fbcdn.net' => 'Facebook',
        'messenger.com' => 'Messenger',
        'whatsapp.net' => 'WhatsApp',
        'whatsapp.com' => 'WhatsApp',
        'googlevideo.com' => 'YouTube',
        'ytimg.com' => 'YouTube',
        'youtube.com' => 'YouTube',
        'youtu.be' => 'YouTube',
        'tiktokv.com' => 'TikTok',
        'tiktokcdn.com' => 'TikTok',
        'tiktok.com' => 'TikTok',
        'musical.ly' => 'TikTok',
        'nflxvideo.net' => 'Netflix',
        'netflix.com' => 'Netflix',
        'scdn.co' => 'Spotify',
        'spotify.com' => 'Spotify',
        'telegram.org' => 'Telegram',
        't.me' => 'Telegram',
        'zoom.us' => 'Zoom',
        'github.com' => 'GitHub',
        'githubusercontent.com' => 'GitHub',
        'microsoftonline.com' => 'Microsoft',
        'office.com' => 'Microsoft',
        'live.com' => 'Microsoft',
        'microsoft.com' => 'Microsoft',
        'icloud.com' => 'Apple',
        'apple.com' => 'Apple',
        'mzstatic.com' => 'Apple',
        'steampowered.com' => 'Steam',
        'steamstatic.com' => 'Steam',
        'discord.com' => 'Discord',
        'discordapp.com' => 'Discord',
        'twitch.tv' => 'Twitch',
        'reddit.com' => 'Reddit',
        'x.com' => 'X',
        'twitter.com' => 'X',
        'twimg.com' => 'X',
        'linkedin.com' => 'LinkedIn',
        'gstatic.com' => 'Google',
        'googleapis.com' => 'Google',
        'google.com' => 'Google',
        'gvt1.com' => 'Google',
        'cloudflare.com' => 'Cloudflare',
        'cloudflare-dns.com' => 'Cloudflare',
        'doubleclick.net' => 'Iklan',
        'googlesyndication.com' => 'Iklan',
    ];

    public function __construct()
    {
        uksort($this->apps, fn (string $left, string $right) => strlen($right) <=> strlen($left));
        uksort($this->browsers, fn (string $left, string $right) => strlen($right) <=> strlen($left));
    }

    /**
     * @var array<string, string>
     */
    private array $browsers = [
        'clients2.google.com' => 'Chrome',
        'clients4.google.com' => 'Chrome',
        'clientservices.googleapis.com' => 'Chrome',
        'update.googleapis.com' => 'Chrome',
        'safebrowsing.googleapis.com' => 'Chrome',
        'chromewebstore.google.com' => 'Chrome',
        'chrome.google.com' => 'Chrome',
        'detectportal.firefox.com' => 'Firefox',
        'firefox.settings.services.mozilla.com' => 'Firefox',
        'contile.services.mozilla.com' => 'Firefox',
        'push.services.mozilla.com' => 'Firefox',
        'aus5.mozilla.org' => 'Firefox',
        'incoming.telemetry.mozilla.org' => 'Firefox',
        'edge.microsoft.com' => 'Edge',
        'smartscreen.microsoft.com' => 'Edge',
        'nav.smartscreen.microsoft.com' => 'Edge',
        'variations.brave.com' => 'Brave',
        'go-updater.brave.com' => 'Brave',
        'brave-core-ext.s3.brave.com' => 'Brave',
        'autoupdate.geo.opera.com' => 'Opera',
        'opera.com' => 'Opera',
    ];

    /**
     * @return array{address: string, domain: string, qtype: string, app: string, browser: ?string}|null
     */
    public function parse(string $topics, string $message): ?array
    {
        $topic = strtolower($topics);
        if (! str_contains($topic, 'dns') && ! str_contains(strtolower($message), 'query from')) {
            return null;
        }

        if (! preg_match('/query from (\d{1,3}(?:\.\d{1,3}){3})(?::\d+)?:\s+#\d+\s+(\S+?)\.?\s+([A-Za-z0-9]+)/', $message, $match)) {
            return null;
        }

        $domain = strtolower(rtrim($match[2], '.'));
        $type = strtoupper($match[3]);
        if ($domain === '' || $type === 'PTR' || str_ends_with($domain, '.arpa') || str_ends_with($domain, '.local')) {
            return null;
        }

        return [
            'address' => $match[1],
            'domain' => substr($domain, 0, 255),
            'qtype' => substr($type, 0, 16),
            'app' => $this->app($domain),
            'browser' => $this->browser($domain),
        ];
    }

    public function app(string $domain): string
    {
        foreach ($this->apps as $suffix => $name) {
            if ($domain === $suffix || str_ends_with($domain, '.'.$suffix)) {
                return $name;
            }
        }

        $parts = explode('.', $domain);
        $label = count($parts) >= 2 ? $parts[count($parts) - 2] : $parts[0];

        return ucfirst($label);
    }

    public function browser(string $domain): ?string
    {
        foreach ($this->browsers as $suffix => $name) {
            if ($domain === $suffix || str_ends_with($domain, '.'.$suffix)) {
                return $name;
            }
        }

        return null;
    }
}
