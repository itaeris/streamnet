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

    /**
     * What kind of traffic a domain usually carries, used to explain bandwidth spikes.
     *
     * @var array<string, string>
     */
    private array $kinds = [
        'googlevideo.com' => 'Streaming video',
        'youtube.com' => 'Streaming video',
        'ytimg.com' => 'Streaming video',
        'nflxvideo.net' => 'Streaming video',
        'netflix.com' => 'Streaming video',
        'tiktokcdn.com' => 'Streaming video',
        'tiktokcdn-us.com' => 'Streaming video',
        'tiktokv.com' => 'Streaming video',
        'byteoversea.com' => 'Streaming video',
        'ibyteimg.com' => 'Streaming video',
        'vidio.com' => 'Streaming video',
        'viu.com' => 'Streaming video',
        'iq.com' => 'Streaming video',
        'primevideo.com' => 'Streaming video',
        'aiv-cdn.net' => 'Streaming video',
        'hotstar.com' => 'Streaming video',
        'twitch.tv' => 'Streaming video',
        'ttvnw.net' => 'Streaming video',
        'spotify.com' => 'Streaming audio',
        'scdn.co' => 'Streaming audio',
        'fbcdn.net' => 'Media sosial',
        'cdninstagram.com' => 'Media sosial',
        'instagram.com' => 'Media sosial',
        'facebook.com' => 'Media sosial',
        'twimg.com' => 'Media sosial',
        'windowsupdate.com' => 'Update & unduhan software',
        'update.microsoft.com' => 'Update & unduhan software',
        'delivery.mp.microsoft.com' => 'Update & unduhan software',
        'officecdn.microsoft.com' => 'Update & unduhan software',
        'swcdn.apple.com' => 'Update & unduhan software',
        'updates.cdn-apple.com' => 'Update & unduhan software',
        'mesu.apple.com' => 'Update & unduhan software',
        'osxapps.itunes.apple.com' => 'Update & unduhan software',
        'appldnld.apple.com' => 'Update & unduhan software',
        'dl.google.com' => 'Update & unduhan software',
        'gvt1.com' => 'Update & unduhan software',
        'play-lh.googleusercontent.com' => 'Update & unduhan software',
        'download.nvidia.com' => 'Update & unduhan software',
        'ccmdl.adobe.com' => 'Update & unduhan software',
        'steamcontent.com' => 'Game & unduhan game',
        'steamserver.net' => 'Game & unduhan game',
        'steampowered.com' => 'Game & unduhan game',
        'epicgames.com' => 'Game & unduhan game',
        'riotgames.com' => 'Game & unduhan game',
        'roblox.com' => 'Game & unduhan game',
        'garena.com' => 'Game & unduhan game',
        'mobilelegends.com' => 'Game & unduhan game',
        'icloud-content.com' => 'Cloud sync & backup',
        'icloud.com' => 'Cloud sync & backup',
        'sharepoint.com' => 'Cloud sync & backup',
        'onedrive.com' => 'Cloud sync & backup',
        '1drv.com' => 'Cloud sync & backup',
        'blob.core.windows.net' => 'Cloud sync & backup',
        'store.core.windows.net' => 'Cloud sync & backup',
        'drive.google.com' => 'Cloud sync & backup',
        'docs.googleusercontent.com' => 'Cloud sync & backup',
        'dropbox.com' => 'Cloud sync & backup',
        'dropboxusercontent.com' => 'Cloud sync & backup',
        'larksuite.com' => 'Cloud sync & backup',
        'larksuitecdn.com' => 'Cloud sync & backup',
        'zoom.us' => 'Video call',
        'zoom.com' => 'Video call',
        'teams.microsoft.com' => 'Video call',
        'skype.com' => 'Video call',
        'webex.com' => 'Video call',
        'meet.google.com' => 'Video call',
        'whatsapp.net' => 'Chat & panggilan',
        'whatsapp.com' => 'Chat & panggilan',
    ];

    public function __construct()
    {
        uksort($this->apps, fn (string $left, string $right) => strlen($right) <=> strlen($left));
        uksort($this->browsers, fn (string $left, string $right) => strlen($right) <=> strlen($left));
        uksort($this->kinds, fn (string $left, string $right) => strlen($right) <=> strlen($left));
    }

    public function kind(?string $domain): string
    {
        $domain = strtolower(trim((string) $domain, '. '));
        if ($domain === '') {
            return 'Tidak diketahui';
        }

        foreach ($this->kinds as $suffix => $kind) {
            if ($domain === $suffix || str_ends_with($domain, '.'.$suffix)) {
                return $kind;
            }
        }

        return 'Browsing & lainnya';
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

    public function isResolverLine(string $topics, string $message): bool
    {
        $parts = array_map('trim', explode(',', strtolower($topics)));
        if (! in_array('dns', $parts, true) || array_intersect($parts, ['error', 'warning', 'critical']) !== []) {
            return false;
        }

        return (bool) preg_match('/^\s*(query from|done query|local query)\b/i', $message);
    }

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
