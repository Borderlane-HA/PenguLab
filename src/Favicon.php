<?php
declare(strict_types=1);

namespace PenguLab;

use RuntimeException;

final class Favicon
{
    private const MAX_HTML_BYTES = 524288;
    private const MAX_ICON_BYTES = 1048576;

    public static function detect(string $pageUrl, bool $verifyTls = true): string
    {
        $pageUrl = self::normalizeUrl($pageUrl);
        $image = self::detectOnce($pageUrl, $verifyTls);
        // Internal homelab services commonly use self-signed certificates. Retry only
        // for local/private targets so public HTTPS verification remains strict.
        if ($image === '' && $verifyTls && self::allowInsecureRetry($pageUrl)) {
            $image = self::detectOnce($pageUrl, false);
        }
        return $image;
    }

    private static function detectOnce(string $pageUrl, bool $verifyTls): string
    {
        $html = self::fetch($pageUrl, self::MAX_HTML_BYTES, $verifyTls, 'text/html,application/xhtml+xml,*/*;q=0.2');
        $favicon = '';

        if ($html['status'] >= 200 && $html['status'] < 400 && $html['body'] !== '') {
            $patterns = [
                '~<link[^>]*rel=["\'][^"\']*(?:shortcut\s+icon|icon|apple-touch-icon)[^"\']*["\'][^>]*href=["\']([^"\']+)["\'][^>]*>~i',
                '~<link[^>]*href=["\']([^"\']+)["\'][^>]*rel=["\'][^"\']*(?:shortcut\s+icon|icon|apple-touch-icon)[^"\']*["\'][^>]*>~i',
            ];
            foreach ($patterns as $pattern) {
                if (preg_match($pattern, $html['body'], $matches)) {
                    $favicon = self::resolveUrl($html['effective_url'] ?: $pageUrl, html_entity_decode($matches[1], ENT_QUOTES | ENT_HTML5));
                    break;
                }
            }
        }

        if ($favicon === '') {
            $parts = parse_url($html['effective_url'] ?: $pageUrl);
            if ($parts && isset($parts['scheme'], $parts['host'])) {
                $favicon = $parts['scheme'] . '://' . $parts['host'] . (isset($parts['port']) ? ':' . $parts['port'] : '') . '/favicon.ico';
            }
        }

        if ($favicon === '') return '';
        $icon = self::fetch($favicon, self::MAX_ICON_BYTES, $verifyTls, 'image/avif,image/webp,image/png,image/svg+xml,image/*,*/*;q=0.1');
        if ($icon['status'] < 200 || $icon['status'] >= 400 || $icon['body'] === '') return '';

        $mime = strtolower(trim(explode(';', (string)$icon['content_type'], 2)[0]));
        $allowed = [
            'image/png', 'image/jpeg', 'image/gif', 'image/webp', 'image/svg+xml',
            'image/x-icon', 'image/vnd.microsoft.icon', 'image/bmp', 'image/avif',
        ];
        if (!in_array($mime, $allowed, true)) {
            $path = (string)(parse_url($favicon, PHP_URL_PATH) ?? '');
            $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
            $mime = [
                'png'=>'image/png','jpg'=>'image/jpeg','jpeg'=>'image/jpeg','gif'=>'image/gif',
                'webp'=>'image/webp','svg'=>'image/svg+xml','ico'=>'image/x-icon','bmp'=>'image/bmp','avif'=>'image/avif',
            ][$ext] ?? '';
        }
        if ($mime === '' || !in_array($mime, $allowed, true)) return '';

        return 'data:' . $mime . ';base64,' . base64_encode($icon['body']);
    }

    private static function allowInsecureRetry(string $url): bool
    {
        $host = strtolower((string)(parse_url($url, PHP_URL_HOST) ?? ''));
        if ($host === '') return false;
        if ($host === 'localhost' || !str_contains($host, '.')) return true;
        foreach (['.local','.lan','.internal','.home','.home.arpa'] as $suffix) {
            if (str_ends_with($host, $suffix)) return true;
        }
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            return filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false;
        }
        // Internal DNS names may use a normal-looking suffix. If they resolve only to
        // private/reserved IPv4 addresses, the relaxed retry is still local-only.
        $addresses = @gethostbynamel($host) ?: [];
        if ($addresses !== []) {
            foreach ($addresses as $address) {
                if (filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false) return false;
            }
            return true;
        }
        return false;
    }

    private static function normalizeUrl(string $url): string
    {
        $url = trim($url);
        if ($url !== '' && !preg_match('~^https?://~i', $url)) $url = 'https://' . $url;
        $parts = parse_url($url);
        if (!$parts || !in_array(strtolower((string)($parts['scheme'] ?? '')), ['http','https'], true) || empty($parts['host'])) {
            throw new RuntimeException('Please enter a valid HTTP or HTTPS URL.');
        }
        return $url;
    }

    private static function fetch(string $url, int $maxBytes, bool $verifyTls, string $accept): array
    {
        if (!extension_loaded('curl')) throw new RuntimeException('The PHP curl extension is required for favicon lookup.');
        $body = '';
        $ch = curl_init($url);
        if ($ch === false) return ['status'=>0,'body'=>'','content_type'=>'','effective_url'=>$url];
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => false,
            CURLOPT_FOLLOWLOCATION => $verifyTls,
            CURLOPT_MAXREDIRS => 3,
            CURLOPT_CONNECTTIMEOUT => 3,
            CURLOPT_TIMEOUT => 6,
            CURLOPT_USERAGENT => 'PenguLab/2.0',
            CURLOPT_HTTPHEADER => ['Accept: ' . $accept],
            CURLOPT_SSL_VERIFYPEER => $verifyTls,
            CURLOPT_SSL_VERIFYHOST => $verifyTls ? 2 : 0,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_WRITEFUNCTION => static function($ch, string $chunk) use (&$body, $maxBytes): int {
                $remaining = $maxBytes - strlen($body);
                if ($remaining <= 0) return 0;
                if (strlen($chunk) > $remaining) {
                    $body .= substr($chunk, 0, $remaining);
                    return 0;
                }
                $body .= $chunk;
                return strlen($chunk);
            },
        ]);
        $ok = curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $contentType = (string)curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
        $effective = (string)curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
        $errno = curl_errno($ch);
        curl_close($ch);
        if ($ok === false && $errno !== CURLE_WRITE_ERROR) return ['status'=>0,'body'=>'','content_type'=>'','effective_url'=>$effective ?: $url];
        return ['status'=>$status,'body'=>$body,'content_type'=>$contentType,'effective_url'=>$effective ?: $url];
    }

    private static function resolveUrl(string $base, string $href): string
    {
        $href = trim($href);
        if ($href === '') return '';
        if (preg_match('~^https?://~i', $href)) return $href;
        $parts = parse_url($base);
        if (!$parts || empty($parts['scheme']) || empty($parts['host'])) return '';
        $origin = $parts['scheme'] . '://' . $parts['host'] . (isset($parts['port']) ? ':' . $parts['port'] : '');
        if (str_starts_with($href, '//')) return $parts['scheme'] . ':' . $href;
        if (str_starts_with($href, '/')) return $origin . $href;
        $path = (string)($parts['path'] ?? '/');
        $dir = rtrim(str_replace('\\', '/', dirname($path)), '/');
        if ($dir === '.' || $dir === '/') $dir = '';
        return $origin . $dir . '/' . $href;
    }
}
