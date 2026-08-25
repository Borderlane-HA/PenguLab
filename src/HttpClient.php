<?php
declare(strict_types=1);

namespace PenguLab;

use RuntimeException;

final class HttpClient
{
    public function request(string $method, string $url, array $options = []): array
    {
        if (!extension_loaded('curl')) {
            throw new RuntimeException('The PHP curl extension is required for integrations.');
        }
        $parts = parse_url($url);
        if (!$parts || !in_array(strtolower((string)($parts['scheme'] ?? '')), ['http', 'https'], true)) {
            throw new RuntimeException('Only HTTP and HTTPS URLs are allowed.');
        }

        $ch = curl_init($url);
        if ($ch === false) throw new RuntimeException('Could not initialize HTTP client.');
        $headers = ['Accept: application/json', 'User-Agent: PenguLab/2.8.0'];
        foreach (($options['headers'] ?? []) as $key => $value) {
            $headers[] = is_int($key) ? (string)$value : ($key . ': ' . $value);
        }
        if (isset($options['json'])) {
            $headers[] = 'Content-Type: application/json';
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($options['json'], JSON_UNESCAPED_SLASHES));
        } elseif (isset($options['body'])) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, (string)$options['body']);
        }

        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST => strtoupper($method),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT => (int)($options['connect_timeout'] ?? 4),
            CURLOPT_TIMEOUT => (int)($options['timeout'] ?? 8),
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_SSL_VERIFYPEER => (bool)($options['verify_tls'] ?? true),
            CURLOPT_SSL_VERIFYHOST => ($options['verify_tls'] ?? true) ? 2 : 0,
        ]);

        if (isset($options['basic'])) {
            curl_setopt($ch, CURLOPT_HTTPAUTH, CURLAUTH_BASIC);
            curl_setopt($ch, CURLOPT_USERPWD, (string)$options['basic']);
        }

        $body = curl_exec($ch);
        if ($body === false) {
            $error = curl_error($ch);
            curl_close($ch);
            throw new RuntimeException($error !== '' ? $error : 'HTTP request failed.');
        }
        $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $contentType = (string)curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
        curl_close($ch);

        $json = null;
        if (str_contains(strtolower($contentType), 'json') || str_starts_with(ltrim((string)$body), '{') || str_starts_with(ltrim((string)$body), '[')) {
            $decoded = json_decode((string)$body, true);
            if (json_last_error() === JSON_ERROR_NONE) $json = $decoded;
        }

        return ['status' => $status, 'body' => (string)$body, 'json' => $json];
    }
}
