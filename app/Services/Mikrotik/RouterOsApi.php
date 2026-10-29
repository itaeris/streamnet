<?php

namespace App\Services\Mikrotik;

use RuntimeException;

class RouterOsApi
{
    /**
     * @param  resource  $socket
     */
    public function __construct(private $socket) {}

    public static function connect(string $host, int $port, bool $tls, int $timeout): self
    {
        $target = ($tls ? 'tls' : 'tcp').'://'.$host.':'.$port;
        $context = stream_context_create([
            'ssl' => [
                'verify_peer' => false,
                'verify_peer_name' => false,
                'allow_self_signed' => true,
            ],
        ]);
        $errno = 0;
        $error = '';
        $socket = @stream_socket_client($target, $errno, $error, max(1, $timeout), STREAM_CLIENT_CONNECT, $context);

        if (! is_resource($socket)) {
            $detail = $error !== '' ? ' ('.$error.')' : '';

            throw new RuntimeException('Tidak bisa terhubung ke API '.$host.':'.$port.$detail);
        }

        stream_set_timeout($socket, max(1, $timeout));

        return new self($socket);
    }

    public function login(string $username, string $password): void
    {
        $result = $this->talk(['/login', '=name='.$username, '=password='.$password]);
        $challenge = $result['done']['ret'] ?? null;

        if (! is_string($challenge) || $challenge === '') {
            return;
        }

        $hashed = '00'.md5(chr(0).$password.pack('H*', $challenge));
        $this->talk(['/login', '=name='.$username, '=response='.$hashed]);
    }

    /**
     * @param  array<string, mixed>  $body
     * @return array<string, mixed>|array<int, array<string, mixed>>
     */
    public function query(string $method, string $path, array $body): array
    {
        $words = [$this->command($method, $path)];

        foreach ($body as $key => $value) {
            $words[] = '='.$key.'='.(string) $value;
        }

        $result = $this->talk($words);

        if ($result['replies'] === [] && array_key_exists('ret', $result['done'])) {
            return ['ret' => $result['done']['ret']];
        }

        if (count($result['replies']) === 1 && ! array_key_exists('.id', $result['replies'][0])) {
            return $result['replies'][0];
        }

        return $result['replies'];
    }

    public function close(): void
    {
        if (is_resource($this->socket)) {
            fclose($this->socket);
        }
    }

    /**
     * @param  array<int, string>  $words
     */
    public static function encodeSentence(array $words): string
    {
        $out = '';

        foreach ($words as $word) {
            $out .= self::encodeWord($word);
        }

        return $out."\x00";
    }

    /**
     * @param  array<int, string>  $words
     * @return array{replies: array<int, array<string, string>>, done: array<string, string>}
     */
    private function talk(array $words): array
    {
        $this->writeSentence($words);
        $replies = [];
        $trap = null;

        while (true) {
            $sentence = $this->readSentence();
            $tag = $sentence[0] ?? '';
            $attributes = $this->attributes($sentence);

            if ($tag === '!re') {
                $replies[] = $attributes;

                continue;
            }

            if ($tag === '!trap') {
                $trap = $attributes['message'] ?? 'Permintaan API ditolak.';

                continue;
            }

            if ($tag === '!done') {
                if ($trap !== null) {
                    throw new RuntimeException($trap);
                }

                return ['replies' => $replies, 'done' => $attributes];
            }

            if ($tag === '!fatal') {
                throw new RuntimeException($attributes['message'] ?? 'Koneksi API MikroTik ditutup.');
            }

            throw new RuntimeException('Jawaban API MikroTik tidak dikenal.');
        }
    }

    private function command(string $method, string $path): string
    {
        $command = '/'.trim($path, '/');

        if (strtolower($method) !== 'post') {
            $command .= '/print';
        }

        return $command;
    }

    /**
     * @param  array<int, string>  $words
     */
    private function writeSentence(array $words): void
    {
        $payload = self::encodeSentence($words);
        $written = 0;
        $length = strlen($payload);

        while ($written < $length) {
            $chunk = fwrite($this->socket, substr($payload, $written));

            if ($chunk === false || $chunk === 0) {
                throw new RuntimeException('Koneksi API MikroTik terputus.');
            }

            $written += $chunk;
        }
    }

    /**
     * @return array<int, string>
     */
    private function readSentence(): array
    {
        $words = [];

        while (true) {
            $word = $this->readWord();

            if ($word === '') {
                return $words;
            }

            $words[] = $word;
        }
    }

    private function readWord(): string
    {
        $length = $this->readLength();

        if ($length === 0) {
            return '';
        }

        return $this->readBytes($length);
    }

    private function readLength(): int
    {
        $first = ord($this->readBytes(1));

        if (($first & 0x80) === 0) {
            return $first;
        }

        if (($first & 0xC0) === 0x80) {
            $first &= ~0xC0;
            $first <<= 8;

            return $first + ord($this->readBytes(1));
        }

        if (($first & 0xE0) === 0xC0) {
            $first &= ~0xE0;
            $first <<= 8;
            $first += ord($this->readBytes(1));
            $first <<= 8;

            return $first + ord($this->readBytes(1));
        }

        if (($first & 0xF0) === 0xE0) {
            $first &= ~0xF0;
            $first <<= 8;
            $first += ord($this->readBytes(1));
            $first <<= 8;
            $first += ord($this->readBytes(1));
            $first <<= 8;

            return $first + ord($this->readBytes(1));
        }

        if (($first & 0xF8) === 0xF0) {
            $length = ord($this->readBytes(1));
            $length <<= 8;
            $length += ord($this->readBytes(1));
            $length <<= 8;
            $length += ord($this->readBytes(1));
            $length <<= 8;

            return $length + ord($this->readBytes(1));
        }

        throw new RuntimeException('Panjang kata API tidak dikenal.');
    }

    private function readBytes(int $length): string
    {
        $data = '';

        while (strlen($data) < $length) {
            $chunk = fread($this->socket, $length - strlen($data));

            if ($chunk === false || $chunk === '') {
                $meta = stream_get_meta_data($this->socket);

                if ($meta['timed_out'] ?? false) {
                    throw new RuntimeException('API MikroTik tidak menjawab tepat waktu.');
                }

                throw new RuntimeException('Koneksi API MikroTik terputus.');
            }

            $data .= $chunk;
        }

        return $data;
    }

    /**
     * @param  array<int, string>  $words
     * @return array<string, string>
     */
    private function attributes(array $words): array
    {
        $attributes = [];

        foreach (array_slice($words, 1) as $word) {
            if (! str_starts_with($word, '=')) {
                continue;
            }

            $pair = substr($word, 1);
            $split = strpos($pair, '=');

            if ($split === false) {
                $attributes[$pair] = '';

                continue;
            }

            $attributes[substr($pair, 0, $split)] = substr($pair, $split + 1);
        }

        return $attributes;
    }

    private static function encodeWord(string $word): string
    {
        return self::encodeLength(strlen($word)).$word;
    }

    private static function encodeLength(int $length): string
    {
        if ($length < 0x80) {
            return chr($length);
        }

        if ($length < 0x4000) {
            $length |= 0x8000;

            return chr(($length >> 8) & 0xFF).chr($length & 0xFF);
        }

        if ($length < 0x200000) {
            $length |= 0xC00000;

            return chr(($length >> 16) & 0xFF).chr(($length >> 8) & 0xFF).chr($length & 0xFF);
        }

        if ($length < 0x10000000) {
            $length |= 0xE0000000;

            return chr(($length >> 24) & 0xFF).chr(($length >> 16) & 0xFF).chr(($length >> 8) & 0xFF).chr($length & 0xFF);
        }

        return chr(0xF0).chr(($length >> 24) & 0xFF).chr(($length >> 16) & 0xFF).chr(($length >> 8) & 0xFF).chr($length & 0xFF);
    }
}
