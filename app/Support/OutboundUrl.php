<?php

namespace App\Support;

use RuntimeException;

class OutboundUrl
{
    /**
     * Allow a public or LAN console. Reject loopback, link-local, and cloud metadata addresses.
     */
    public static function assertAllowed(string $url): void
    {
        $parts = parse_url($url);
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host = strtolower(trim((string) ($parts['host'] ?? ''), '[]'));

        if (! in_array($scheme, ['http', 'https'], true) || $host === '' || isset($parts['user']) || isset($parts['pass'])) {
            throw new RuntimeException('Alamat harus http atau https, tanpa username di URL.');
        }

        if (self::blockedHost($host)) {
            throw new RuntimeException('Alamat tidak boleh mengarah ke mesin ini atau layanan metadata.');
        }

        if (filter_var($host, FILTER_VALIDATE_IP)) {
            self::assertRoutable($host);

            return;
        }

        $records = @gethostbynamel($host);
        if (! is_array($records)) {
            return;
        }

        foreach ($records as $ip) {
            self::assertRoutable($ip);
        }
    }

    private static function blockedHost(string $host): bool
    {
        return in_array($host, [
            'localhost',
            'localhost.localdomain',
            'metadata.google.internal',
            'metadata.google.com',
        ], true);
    }

    private static function assertRoutable(string $ip): void
    {
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_RES_RANGE) === false) {
            throw new RuntimeException('Alamat tidak boleh mengarah ke loopback, link-local, atau metadata.');
        }
    }
}
