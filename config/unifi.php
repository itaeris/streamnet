<?php

return [
    'url' => rtrim((string) env('UNIFI_URL', ''), '/'),
    'api_key' => (string) env('UNIFI_API_KEY', ''),
    'site' => (string) env('UNIFI_SITE', ''),
    'verify_tls' => (bool) env('UNIFI_VERIFY_TLS', true),
    'timeout' => (int) env('UNIFI_TIMEOUT', 8),
    'cache_seconds' => (int) env('UNIFI_CACHE_SECONDS', 10),
];
