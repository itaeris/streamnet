<?php

namespace App\Services\Mikrotik;

use Illuminate\Http\Client\Pool;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Throwable;

class MikrotikClient
{
    /**
     * @param  array<string, array{method?: string, path: string, body?: array<string, mixed>}>  $calls
     * @param  array{username?: string, password?: string, base_url?: string, timeout?: int, transport?: string, host?: string, port?: int, tls?: bool}|null  $connection
     * @return array<string, mixed>
     */
    public function gather(array $calls, ?array $connection = null): array
    {
        if (($connection['transport'] ?? 'rest') === 'api') {
            return $this->gatherApi($calls, $connection ?? []);
        }

        $username = (string) ($connection['username'] ?? config('mikrotik.username'));
        $password = (string) ($connection['password'] ?? config('mikrotik.password'));
        $baseUrl = (string) ($connection['base_url'] ?? config('mikrotik.base_url'));
        $timeout = (int) ($connection['timeout'] ?? config('mikrotik.timeout', 8));

        $responses = Http::pool(function (Pool $pool) use ($calls, $username, $password, $baseUrl, $timeout) {
            foreach ($calls as $name => $call) {
                $pending = $pool->as($name)
                    ->withBasicAuth($username, $password)
                    ->acceptJson()
                    ->connectTimeout(4)
                    ->timeout($timeout)
                    ->baseUrl(rtrim($baseUrl, '/').'/');

                if (strtolower($call['method'] ?? 'get') === 'post') {
                    $pending->post($call['path'], $call['body'] ?? []);
                } else {
                    $pending->get($call['path'], $call['body'] ?? []);
                }
            }
        });

        $out = [];

        foreach (array_keys($calls) as $name) {
            $response = $responses[$name] ?? null;
            $out[$name] = $response instanceof Response && $response->successful()
                ? $response->json()
                : null;
        }

        return $out;
    }

    /**
     * @param  array<string, array{method?: string, path: string, body?: array<string, mixed>}>  $calls
     * @param  array{username?: string, password?: string, timeout?: int, host?: string, port?: int, tls?: bool}  $connection
     * @return array<string, mixed>
     */
    private function gatherApi(array $calls, array $connection): array
    {
        $api = RouterOsApi::connect(
            (string) ($connection['host'] ?? ''),
            (int) ($connection['port'] ?? 8728),
            (bool) ($connection['tls'] ?? false),
            (int) ($connection['timeout'] ?? config('mikrotik.timeout', 8)),
        );

        try {
            $api->login((string) ($connection['username'] ?? ''), (string) ($connection['password'] ?? ''));
            $out = [];
            $closed = false;

            foreach ($calls as $name => $call) {
                if ($closed) {
                    $out[$name] = null;

                    continue;
                }

                try {
                    $out[$name] = $api->query($call['method'] ?? 'get', $call['path'], $call['body'] ?? []);
                } catch (Throwable $exception) {
                    $out[$name] = null;
                    $closed = str_contains($exception->getMessage(), 'terputus')
                        || str_contains($exception->getMessage(), 'tepat waktu')
                        || str_contains($exception->getMessage(), 'ditutup');
                }
            }

            return $out;
        } finally {
            $api->close();
        }
    }
}
