<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

class SiteLogo
{
    /**
     * The site's own icon, cached. Null when the site has none we can show.
     *
     * @return array{type: string, body: string}|null
     */
    public function fetch(string $domain): ?array
    {
        $domain = strtolower(rtrim($domain, '.'));
        if (! $this->allowed($domain)) {
            return null;
        }

        $key = 'site-logo:'.sha1($domain);
        $cached = Cache::get($key);
        if ($cached === false) {
            return null;
        }
        if (is_array($cached) && isset($cached['type'], $cached['body'])) {
            return $cached;
        }

        $image = $this->download($domain);
        Cache::put($key, $image ?? false, $image ? now()->addDays(7) : now()->addHours(12));

        return $image;
    }

    public function allowed(string $domain): bool
    {
        $domain = strtolower(rtrim($domain, '.'));
        if (preg_match('/^(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,24}$/', $domain) !== 1) {
            return false;
        }

        return preg_match('/\.(local|localhost|internal|home|lan|localdomain)$/', $domain) !== 1;
    }

    public function iconUrl(string $domain, string $html): ?string
    {
        if (preg_match_all('/<link\b[^>]*>/i', $html, $tags) < 1) {
            return null;
        }

        $icons = [];
        foreach ($tags[0] as $tag) {
            if (preg_match('/\brel\s*=\s*["\'][^"\']*\b(?:apple-touch-icon|shortcut icon|icon)\b/i', $tag) !== 1) {
                continue;
            }
            if (preg_match('/\bhref\s*=\s*["\']([^"\']+)["\']/', $tag, $href) !== 1) {
                continue;
            }
            $url = $this->absolute($domain, html_entity_decode($href[1], ENT_QUOTES));
            if ($url === null) {
                continue;
            }
            $icons[] = [
                'url' => $url,
                'touch' => preg_match('/apple-touch-icon/i', $tag) === 1,
            ];
        }

        foreach ($icons as $icon) {
            if ($icon['touch']) {
                return $icon['url'];
            }
        }

        return $icons[0]['url'] ?? null;
    }

    /**
     * @return array{type: string, body: string}|null
     */
    private function download(string $domain): ?array
    {
        $home = $this->get('https://'.$domain.'/');
        $icon = is_array($home) ? $this->iconUrl($domain, $home['body']) : null;
        $icon ??= 'https://'.$domain.'/favicon.ico';

        $file = $this->image($icon);
        if ($file === null && $icon !== 'https://'.$domain.'/favicon.ico') {
            $file = $this->image('https://'.$domain.'/favicon.ico');
        }

        return $file;
    }

    /**
     * @return array{type: string, body: string}|null
     */
    private function image(string $url): ?array
    {
        $file = $this->get($url);
        if ($file === null) {
            return null;
        }

        $type = $this->rasterType($file['body']);

        return $type === null ? null : ['type' => $type, 'body' => $file['body']];
    }

    /**
     * @return array{body: string}|null
     */
    private function get(string $url, int $hop = 0): ?array
    {
        if ($hop > 2) {
            return null;
        }

        $host = parse_url($url, PHP_URL_HOST);
        if (! is_string($host) || ! $this->allowed($host)) {
            return null;
        }

        $ips = $this->addresses($host);
        if (! $this->publicAddresses($ips)) {
            return null;
        }

        try {
            $response = Http::timeout(3)
                ->connectTimeout(2)
                ->withOptions([
                    'allow_redirects' => false,
                    'curl' => [CURLOPT_RESOLVE => [$host.':443:'.$ips[0]]],
                ])
                ->withHeaders(['User-Agent' => 'Mozilla/5.0 (compatible; StreamNet/1.0)'])
                ->get($url);
        } catch (\Throwable) {
            return null;
        }

        if ($response->status() >= 300 && $response->status() < 400) {
            $next = $this->absolute($host, (string) $response->header('Location'));
            if ($next === null || $next === $url) {
                return null;
            }

            return $this->get($next, $hop + 1);
        }

        if (! $response->successful()) {
            return null;
        }

        $body = $response->body();
        if ($body === '' || strlen($body) > 262144) {
            return null;
        }

        return ['body' => $body];
    }

    private function absolute(string $domain, string $href): ?string
    {
        $href = trim($href);
        if ($href === '' || str_starts_with($href, 'data:')) {
            return null;
        }
        if (str_starts_with($href, '//')) {
            $href = 'https:'.$href;
        } elseif (str_starts_with($href, 'http://')) {
            $href = 'https://'.substr($href, 7);
        } elseif (str_starts_with($href, '/')) {
            $href = 'https://'.$domain.$href;
        } elseif (! str_starts_with($href, 'https://')) {
            $href = 'https://'.$domain.'/'.ltrim($href, './');
        }

        $host = parse_url($href, PHP_URL_HOST);

        return is_string($host) && $this->allowed($host) ? $href : null;
    }

    private function rasterType(string $body): ?string
    {
        if (str_starts_with($body, "\x89PNG\r\n")) {
            return 'image/png';
        }
        if (str_starts_with($body, "\xFF\xD8\xFF")) {
            return 'image/jpeg';
        }
        if (str_starts_with($body, 'GIF8')) {
            return 'image/gif';
        }
        if (str_starts_with($body, 'RIFF') && str_contains(substr($body, 0, 16), 'WEBP')) {
            return 'image/webp';
        }
        if (str_starts_with($body, "\x00\x00\x01\x00")) {
            return 'image/x-icon';
        }

        return null;
    }

    /**
     * @return array<int, string>
     */
    protected function addresses(string $host): array
    {
        $records = @gethostbynamel($host);

        return is_array($records) ? array_values($records) : [];
    }

    /**
     * @param  array<int, string>  $ips
     */
    private function publicAddresses(array $ips): bool
    {
        if ($ips === []) {
            return false;
        }

        foreach ($ips as $ip) {
            if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
                return false;
            }
        }

        return true;
    }
}
