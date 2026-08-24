<?php
declare(strict_types=1);

namespace PenguLab;

use RuntimeException;

final class Secrets
{
    private string $keyFile;
    private string $key;

    public function __construct(string $dataDir)
    {
        if (!function_exists('sodium_crypto_secretbox')) {
            throw new RuntimeException('PenguLab 2.0 requires the PHP sodium extension.');
        }
        $this->keyFile = rtrim($dataDir, '/') . '/secret.key';
        $this->key = $this->loadOrCreateKey();
    }

    public function encrypt(array $payload): string
    {
        if ($payload === []) return '';
        $plain = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($plain === false) throw new RuntimeException('Could not encode secret payload.');
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $cipher = sodium_crypto_secretbox($plain, $nonce, $this->key);
        return base64_encode($nonce . $cipher);
    }

    public function decrypt(string $payload): array
    {
        if ($payload === '') return [];
        $raw = base64_decode($payload, true);
        if ($raw === false || strlen($raw) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) return [];
        $nonce = substr($raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $cipher = substr($raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $plain = sodium_crypto_secretbox_open($cipher, $nonce, $this->key);
        if ($plain === false) return [];
        $decoded = json_decode($plain, true);
        return is_array($decoded) ? $decoded : [];
    }

    private function loadOrCreateKey(): string
    {
        if (is_file($this->keyFile)) {
            $raw = base64_decode(trim((string)file_get_contents($this->keyFile)), true);
            if (is_string($raw) && strlen($raw) === SODIUM_CRYPTO_SECRETBOX_KEYBYTES) {
                return $raw;
            }
        }
        $key = random_bytes(SODIUM_CRYPTO_SECRETBOX_KEYBYTES);
        if (file_put_contents($this->keyFile, base64_encode($key) . PHP_EOL, LOCK_EX) === false) {
            throw new RuntimeException('Could not create PenguLab secret key.');
        }
        @chmod($this->keyFile, 0600);
        return $key;
    }
}
