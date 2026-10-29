<?php

return [

    'host' => env('MIKROTIK_HOST', '10.10.10.1'),

    'scheme' => env('MIKROTIK_SCHEME', 'http'),

    'username' => env('MIKROTIK_USERNAME', 'wiki'),

    'password' => env('MIKROTIK_PASSWORD', ''),

    'timeout' => (int) env('MIKROTIK_TIMEOUT', 8),

    'poll_seconds' => (int) env('MIKROTIK_POLL_SECONDS', 5),

    'wan_interface' => env('MIKROTIK_WAN_INTERFACE', 'ether1-ISP'),

    'connection_warning' => (int) env('MIKROTIK_CONNECTION_WARNING', 8000),

    'connection_critical' => (int) env('MIKROTIK_CONNECTION_CRITICAL', 15000),

    'base_url' => rtrim(env('MIKROTIK_SCHEME', 'http').'://'.env('MIKROTIK_HOST', '10.10.10.1').'/rest', '/'),

];
