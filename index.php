<?php
declare(strict_types=1);

$dataFile = __DIR__ . '/apps.json';
$langDir = __DIR__ . '/lang';

function send_json($data, int $status = 200): void {
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    exit;
}

function scan_languages(string $langDir): array {
    $result = [];

    if (!is_dir($langDir)) {
        return [['code' => 'de', 'label' => 'Deutsch'], ['code' => 'en', 'label' => 'English']];
    }

    foreach (glob($langDir . '/*.json') ?: [] as $file) {
        $code = basename($file, '.json');
        $json = @file_get_contents($file);
        $data = is_string($json) ? json_decode($json, true) : null;
        $label = is_array($data) && isset($data['_meta']['label']) ? (string)$data['_meta']['label'] : strtoupper($code);
        $result[] = ['code' => $code, 'label' => $label];
    }

    usort($result, static fn(array $a, array $b): int => strcmp($a['label'], $b['label']));

    if (!$result) {
        $result = [['code' => 'de', 'label' => 'Deutsch'], ['code' => 'en', 'label' => 'English']];
    }

    return $result;
}

function load_language_pack(string $langDir, string $code): array {
    $safeCode = preg_replace('/[^a-zA-Z0-9_-]/', '', $code) ?: 'de';
    $file = $langDir . '/' . $safeCode . '.json';

    if (!is_file($file)) {
        $file = $langDir . '/de.json';
    }

    $json = @file_get_contents($file);
    $data = is_string($json) ? json_decode($json, true) : null;

    return is_array($data) ? $data : ['_meta' => ['label' => 'Deutsch']];
}


function resolve_url(string $baseUrl, string $relativeUrl): string {
    if (preg_match('~^https?://~i', $relativeUrl)) {
        return $relativeUrl;
    }

    if (str_starts_with($relativeUrl, '//')) {
        $base = parse_url($baseUrl);
        $scheme = $base['scheme'] ?? 'https';
        return $scheme . ':' . $relativeUrl;
    }

    $base = parse_url($baseUrl);
    if (!$base || !isset($base['scheme'], $base['host'])) {
        return $relativeUrl;
    }

    $scheme = $base['scheme'];
    $host = $base['host'];
    $port = isset($base['port']) ? ':' . $base['port'] : '';
    $basePath = $base['path'] ?? '/';

    if (str_starts_with($relativeUrl, '/')) {
        return $scheme . '://' . $host . $port . $relativeUrl;
    }

    $dir = rtrim(str_replace('\\', '/', dirname($basePath)), '/');
    if ($dir === '') {
        $dir = '/';
    }

    return $scheme . '://' . $host . $port . rtrim($dir, '/') . '/' . ltrim($relativeUrl, '/');
}

function fetch_image_as_data_uri(string $imageUrl): string {
    $context = stream_context_create([
        'http' => [
            'method' => 'GET',
            'timeout' => 5,
            'follow_location' => 1,
            'max_redirects' => 3,
            'header' => "User-Agent: PenguLab/1.0\r\n"
        ],
        'ssl' => [
            'verify_peer' => false,
            'verify_peer_name' => false,
        ]
    ]);

    $binary = @file_get_contents($imageUrl, false, $context, 0, 1048576);
    if (!is_string($binary) || $binary === '') {
        return '';
    }

    $mime = '';

    if (!empty($http_response_header) && is_array($http_response_header)) {
        foreach ($http_response_header as $headerLine) {
            if (stripos($headerLine, 'Content-Type:') === 0) {
                $mime = trim((string)substr($headerLine, strlen('Content-Type:')));
                $mime = strtolower(trim(explode(';', $mime, 2)[0]));
                break;
            }
        }
    }

    if ($mime === '') {
        $imageInfo = @getimagesizefromstring($binary);
        if (is_array($imageInfo) && isset($imageInfo['mime'])) {
            $mime = strtolower((string)$imageInfo['mime']);
        }
    }

    if ($mime === '') {
        $path = parse_url($imageUrl, PHP_URL_PATH);
        $extension = strtolower(pathinfo((string)$path, PATHINFO_EXTENSION));
        $mimeMap = [
            'png' => 'image/png',
            'jpg' => 'image/jpeg',
            'jpeg' => 'image/jpeg',
            'gif' => 'image/gif',
            'webp' => 'image/webp',
            'svg' => 'image/svg+xml',
            'ico' => 'image/x-icon',
            'bmp' => 'image/bmp',
            'avif' => 'image/avif',
        ];
        $mime = $mimeMap[$extension] ?? '';
    }

    $allowed = [
        'image/png',
        'image/jpeg',
        'image/gif',
        'image/webp',
        'image/svg+xml',
        'image/x-icon',
        'image/vnd.microsoft.icon',
        'image/bmp',
        'image/avif',
    ];

    if ($mime === '' || !in_array($mime, $allowed, true)) {
        return '';
    }

    return 'data:' . $mime . ';base64,' . base64_encode($binary);
}


function detect_favicon(string $pageUrl): array {
    $pageUrl = trim($pageUrl);
    if (!preg_match('~^https?://~i', $pageUrl)) {
        return ['ok' => false, 'image' => ''];
    }

    $context = stream_context_create([
        'http' => [
            'method' => 'GET',
            'timeout' => 5,
            'follow_location' => 1,
            'max_redirects' => 3,
            'header' => "User-Agent: PenguLab/1.0\r\n"
        ],
        'ssl' => [
            'verify_peer' => false,
            'verify_peer_name' => false,
        ]
    ]);

    $html = @file_get_contents($pageUrl, false, $context, 0, 524288);
    $favicon = '';

    if (is_string($html) && $html !== '') {
        $patterns = [
            '~<link[^>]*rel=["\'][^"\']*icon[^"\']*["\'][^>]*href=["\']([^"\']+)["\'][^>]*>~i',
            '~<link[^>]*href=["\']([^"\']+)["\'][^>]*rel=["\'][^"\']*icon[^"\']*["\'][^>]*>~i',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $html, $matches)) {
                $favicon = resolve_url($pageUrl, html_entity_decode($matches[1], ENT_QUOTES | ENT_HTML5));
                break;
            }
        }
    }

    if ($favicon === '') {
        $parts = parse_url($pageUrl);
        if ($parts && isset($parts['scheme'], $parts['host'])) {
            $favicon = $parts['scheme'] . '://' . $parts['host'] . (isset($parts['port']) ? ':' . $parts['port'] : '') . '/favicon.ico';
        }
    }

    $dataUri = $favicon !== '' ? fetch_image_as_data_uri($favicon) : '';

    return ['ok' => $dataUri !== '', 'image' => $dataUri];
}


function default_settings(): array {
    return [
        'selectedCategory' => 'all',
        'viewMode' => 'custom',
        'rows' => 3,
        'cols' => 5,
        'theme' => 'light',
        'language' => 'de',
    ];
}

function ensure_data_file(string $dataFile): void {
    if (!file_exists($dataFile)) {
        @file_put_contents(
            $dataFile,
            json_encode(
                ['settings' => default_settings(), 'apps' => []],
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT
            )
        );
    }
}

function sanitize_settings(array $settings, ?array $availableLanguages = null): array {
    $viewMode = (string)($settings['viewMode'] ?? 'custom');
    if (!in_array($viewMode, ['4x3', '6x4', 'custom'], true)) {
        $viewMode = '4x3';
    }

    $rows = max(1, min(12, (int)($settings['rows'] ?? 3)));
    $cols = max(1, min(12, (int)($settings['cols'] ?? 4)));

    if ($viewMode === '4x3') {
        $rows = 3;
        $cols = 4;
    } elseif ($viewMode === '6x4') {
        $rows = 4;
        $cols = 6;
    }

    $theme = (string)($settings['theme'] ?? 'light');
    if (!in_array($theme, ['light', 'dark'], true)) {
        $theme = 'light';
    }

    $language = trim((string)($settings['language'] ?? 'de')) ?: 'de';
    $availableLanguages = $availableLanguages ?? scan_languages($GLOBALS['langDir']);
    $validCodes = array_map(static fn(array $entry): string => (string)$entry['code'], $availableLanguages);
    if (!in_array($language, $validCodes, true)) {
        $language = in_array('de', $validCodes, true) ? 'de' : ($validCodes[0] ?? 'de');
    }

    return [
        'selectedCategory' => trim((string)($settings['selectedCategory'] ?? 'all')) ?: 'all',
        'viewMode' => $viewMode,
        'rows' => $rows,
        'cols' => $cols,
        'theme' => $theme,
        'language' => $language,
    ];
}

function read_data(string $dataFile): array {
    ensure_data_file($dataFile);

    if (!file_exists($dataFile)) {
        return ['settings' => default_settings(), 'apps' => []];
    }

    $json = @file_get_contents($dataFile);
    if ($json === false || trim($json) === '') {
        return ['settings' => default_settings(), 'apps' => []];
    }

    $data = json_decode($json, true);

    if (is_array($data) && array_is_list($data)) {
        return [
            'settings' => default_settings(),
            'apps' => sanitize_apps($data),
        ];
    }

    if (is_array($data)) {
        $availableLanguages = scan_languages($GLOBALS['langDir']);
        return [
            'settings' => sanitize_settings(is_array($data['settings'] ?? null) ? $data['settings'] : [], $availableLanguages),
            'apps' => sanitize_apps(is_array($data['apps'] ?? null) ? $data['apps'] : []),
        ];
    }

    return ['settings' => default_settings(), 'apps' => []];
}

function sanitize_apps(array $apps): array {
    $result = [];

    foreach ($apps as $app) {
        if (!is_array($app)) {
            continue;
        }

        $name = trim((string)($app['name'] ?? ''));
        $url = trim((string)($app['url'] ?? ''));
        $description = trim((string)($app['description'] ?? ''));
        $category = trim((string)($app['category'] ?? ''));
        $image = (string)($app['image'] ?? '');
        $id = trim((string)($app['id'] ?? ''));

        if ($name === '' || $url === '') {
            continue;
        }

        if ($id === '') {
            $id = bin2hex(random_bytes(8));
        }

        $result[] = [
            'id' => $id,
            'name' => $name,
            'url' => $url,
            'description' => $description,
            'category' => $category,
            'image' => $image,
        ];
    }

    return $result;
}

if (isset($_GET['api']) && $_GET['api'] === 'lang') {
    $code = (string)($_GET['code'] ?? 'de');
    send_json([
        'ok' => true,
        'code' => $code,
        'pack' => load_language_pack($langDir, $code),
    ]);
}

if (isset($_GET['api']) && $_GET['api'] === 'favicon') {
    $url = (string)($_GET['url'] ?? '');
    send_json(detect_favicon($url));
}

if (isset($_GET['api']) && $_GET['api'] === 'apps') {
    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        $payload = read_data($dataFile);
        send_json([
            'ok' => true,
            'apps' => $payload['apps'],
            'settings' => $payload['settings'],
            'availableLanguages' => scan_languages($langDir),
        ]);
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $raw = file_get_contents('php://input');
        $decoded = json_decode($raw ?: '', true);

        if (!is_array($decoded) || !isset($decoded['apps']) || !is_array($decoded['apps'])) {
            send_json(['ok' => false, 'error' => 'Ungültige Nutzdaten.'], 400);
        }

        $availableLanguages = scan_languages($langDir);
        $apps = sanitize_apps($decoded['apps']);
        $settings = sanitize_settings(is_array($decoded['settings'] ?? null) ? $decoded['settings'] : [], $availableLanguages);
        $json = json_encode(
            ['settings' => $settings, 'apps' => $apps],
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT
        );

        if ($json === false) {
            send_json(['ok' => false, 'error' => 'JSON konnte nicht erstellt werden.'], 500);
        }

        $written = @file_put_contents($dataFile, $json);
        if ($written === false) {
            send_json(['ok' => false, 'error' => 'apps.json konnte nicht geschrieben werden.'], 500);
        }

        send_json(['ok' => true, 'apps' => $apps, 'settings' => $settings, 'availableLanguages' => $availableLanguages]);
    }

    send_json(['ok' => false, 'error' => 'Methode nicht erlaubt.'], 405);
}

?>
<!DOCTYPE html>
<html lang="de">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>PenguLab</title>
  <style>
    :root {
      --bg: #f4f6fb;
      --card: #ffffff;
      --card-border: #dbe4f3;
      --card-border-dashed: #d7e0ef;
      --text: #102349;
      --muted: #6880aa;
      --accent: #4f86ff;
      --shadow: 0 10px 30px rgba(20, 42, 82, 0.08);
      --shadow-hover: 0 18px 40px rgba(20, 42, 82, 0.14);
      --radius: 28px;
      --tile-gap: 18px;
      --title-size: clamp(1.1rem, 0.8vw + 0.9rem, 1.45rem);
      --desc-size: 0.95rem;
      --toolbar-height: 70px;
      --container-max: 1680px;
      --logo-stage-size: clamp(92px, 9.4vh, 132px);
      --placeholder-size: clamp(84px, 8.4vh, 116px);
      --grid-cols: 4;
      --grid-rows: 3;
    }

    * { box-sizing: border-box; }

    html, body {
      margin: 0;
      height: 100%;
      font-family: Inter, "Segoe UI", Roboto, Arial, sans-serif;
      color: var(--text);
      background: var(--bg);
      overflow: hidden;
    }

    body {
      display: flex;
      flex-direction: column;
    }

    body[data-theme='dark'] {
      --bg: #0f172a;
      --card: #172033;
      --card-border: #2a3854;
      --card-border-dashed: #2a3854;
      --text: #edf3ff;
      --muted: #9fb0d1;
      --accent: #7ba3ff;
      --toolbar-bg: rgba(18, 27, 43, 0.9);
      --toolbar-border: rgba(52, 67, 97, 0.8);
      --control-bg: rgba(24, 35, 56, 0.96);
      --control-border: #324160;
      --panel-bg: rgba(18, 27, 43, 0.98);
      --shadow: 0 10px 30px rgba(0, 0, 0, 0.22);
      --shadow-hover: 0 18px 40px rgba(0, 0, 0, 0.3);
    }

    body[data-theme='dark'] .tile {
      background: #232c3a;
      border-color: #334155;
    }

    body[data-theme='dark'] .tile-logo-zone,
    body[data-theme='dark'] .tile-info {
      background: #232c3a;
    }

    body[data-theme='dark'] .tile-title {
      color: #eef4ff;
    }

    body[data-theme='dark'] .tile-desc,
    body[data-theme='dark'] .empty-desc {
      color: #b8c6e6;
    }

    body[data-theme='dark'] .empty-title {
      color: #eef4ff;
    }

    body[data-theme='dark'] .tile.empty {
      background: #2b3444;
      border-color: #3a465a;
    }

    body[data-theme='dark'] .page-indicator {
      color: #c8d5ef;
    }

    body[data-theme='dark'] .settings-panel {
      background: rgba(15, 23, 42, 0.98);
      border-color: #334155;
    }

    body[data-theme='dark'] .category-select,
    body[data-theme='dark'] .custom-grid-inputs,
    body[data-theme='dark'] .grid-number,
    body[data-theme='dark'] .theme-btn,
    body[data-theme='dark'] .btn:not(.primary),
    body[data-theme='dark'] label.btn {
      background: #1f2937;
      border-color: #334155;
      color: #eef4ff;
    }

    body[data-theme='dark'] .grid-number {
      color: #eef4ff;
      background: #1f2937;
    }

    body[data-theme='dark'] .custom-grid-inputs span {
      color: #d8e2f7;
    }

    body[data-theme='dark'] .btn:not(.primary):hover,
    body[data-theme='dark'] label.btn:hover,
    body[data-theme='dark'] .theme-btn:hover {
      border-color: #4b5d7d;
      box-shadow: 0 10px 24px rgba(0, 0, 0, 0.18);
    }

    body[data-theme='dark'] .btn.primary {
      color: #ffffff;
    }

    .topbar {
      min-height: var(--toolbar-height);
      display: flex;
      align-items: center;
      justify-content: space-between;
      gap: 16px;
      padding: 10px 22px;
      background: var(--toolbar-bg);
      backdrop-filter: blur(14px);
      border-bottom: 1px solid var(--toolbar-border);
      flex: 0 0 auto;
    }

    .brand {
      display: flex;
      align-items: center;
      gap: 14px;
      font-weight: 800;
      font-size: 1.22rem;
      letter-spacing: -0.03em;
    }

    .brand-mark {
      width: 38px;
      height: 38px;
      border-radius: 13px;
      background: linear-gradient(135deg, #81a9ff, #8f7bff);
      box-shadow: 0 8px 24px rgba(88, 117, 255, 0.25);
      flex: 0 0 auto;
    }

    .toolbar-actions {
      display: flex;
      gap: 10px;
      align-items: center;
      flex-wrap: wrap;
    }

    .btn {
      appearance: none;
      border: 1px solid #d7e2f4;
      background: #fff;
      color: var(--text);
      border-radius: 16px;
      padding: 10px 14px;
      font: inherit;
      font-weight: 700;
      cursor: pointer;
      transition: transform 0.2s ease, box-shadow 0.2s ease, border-color 0.2s ease;
      box-shadow: 0 6px 18px rgba(16, 35, 73, 0.05);
      text-decoration: none;
    }

    .btn:hover {
      transform: translateY(-1px);
      border-color: #bfd0ef;
      box-shadow: 0 10px 24px rgba(16, 35, 73, 0.08);
    }

    .btn.primary {
      background: linear-gradient(135deg, #7ba3ff, #6a88ff);
      color: #fff;
      border-color: transparent;
    }

    .btn.active {
      background: linear-gradient(135deg, #102349, #1c3972);
      color: #fff;
      border-color: transparent;
    }

    .page {
      height: calc(100vh - var(--toolbar-height));
      padding: 14px 22px 14px;
      display: flex;
      flex-direction: column;
      gap: 12px;
      min-height: 0;
    }

    .page-header,
    .grid,
    .pagination {
      width: min(100%, var(--container-max));
      margin: 0 auto;
    }

    .page-header {
      flex: 0 0 auto;
    }

    .page-header-bar {
      display: flex;
      align-items: center;
      justify-content: space-between;
      gap: 14px;
      flex-wrap: wrap;
    }

    .settings-wrap {
      position: relative;
      margin-left: auto;
    }

    .settings-btn {
      appearance: none;
      width: 50px;
      height: 50px;
      border-radius: 16px;
      border: 1px solid var(--control-border);
      background: linear-gradient(180deg, rgba(255,255,255,0.98), rgba(240,245,255,0.9));
      color: var(--text);
      font-size: 1.45rem;
      cursor: pointer;
      box-shadow: 0 8px 24px rgba(16, 35, 73, 0.08);
      transition: transform 0.18s ease, box-shadow 0.18s ease, border-color 0.18s ease;
    }

    body[data-theme='dark'] .settings-btn {
      background: linear-gradient(180deg, rgba(30,41,59,0.98), rgba(24,35,56,0.95));
    }

    .settings-btn:hover {
      transform: translateY(-1px) rotate(10deg);
      border-color: #b7c8eb;
      box-shadow: 0 12px 28px rgba(16, 35, 73, 0.12);
    }

    .settings-panel {
      position: absolute;
      right: 0;
      top: calc(100% + 10px);
      width: 300px;
      padding: 14px;
      border-radius: 18px;
      border: 1px solid var(--control-border);
      background: var(--panel-bg);
      box-shadow: 0 18px 40px rgba(16, 35, 73, 0.14);
      display: grid;
      gap: 14px;
      z-index: 30;
      backdrop-filter: blur(14px);
    }

    .settings-panel[hidden] {
      display: none;
    }

    .settings-section {
      display: grid;
      gap: 8px;
    }

    .settings-label {
      font-size: 0.9rem;
      font-weight: 700;
      color: var(--muted);
    }

    .page-title {
      margin: 0;
      font-size: clamp(1.55rem, 1vw + 1rem, 2rem);
      letter-spacing: -0.04em;
    }

    .page-header-center {
      flex: 1 1 420px;
      display: flex;
      justify-content: center;
      min-width: 220px;
    }

    .search-input {
      width: min(100%, 560px);
      border: 1px solid var(--control-border);
      background: var(--control-bg);
      color: var(--text);
      border-radius: 18px;
      padding: 12px 18px;
      font: inherit;
      font-weight: 600;
      box-shadow: 0 4px 14px rgba(16, 35, 73, 0.05);
      outline: none;
    }

    .search-input::placeholder {
      color: transparent;
    }

    .search-input:focus {
      border-color: #9fbbff;
      box-shadow: 0 0 0 4px rgba(123, 163, 255, 0.12);
    }

    .category-select {
      appearance: none;
      border: 1px solid var(--control-border);
      background: var(--control-bg);
      color: var(--text);
      border-radius: 13px;
      padding: 7px 12px;
      font: inherit;
      font-weight: 600;
      min-width: 155px;
      box-shadow: 0 2px 10px rgba(16, 35, 73, 0.03);
      outline: none;
      backdrop-filter: blur(12px);
    }

    .view-switch {
      display: inline-flex;
      align-items: center;
      gap: 8px;
      padding: 2px;
      border-radius: 16px;
      background: rgba(255, 255, 255, 0.34);
    }

    .view-preset-btn {
      appearance: none;
      border: 1px solid var(--control-border);
      background: var(--control-bg);
      color: var(--text);
      border-radius: 13px;
      padding: 7px 12px;
      font: inherit;
      font-weight: 700;
      cursor: pointer;
      box-shadow: 0 2px 10px rgba(16, 35, 73, 0.03);
      transition: border-color 0.18s ease, transform 0.18s ease, box-shadow 0.18s ease;
    }

    .view-preset-btn:hover {
      transform: translateY(-1px);
      border-color: #cbd9f1;
    }

    .view-preset-btn.active {
      border-color: #9fbbff;
      background: rgba(123, 163, 255, 0.12);
      box-shadow: 0 4px 12px rgba(79, 134, 255, 0.08);
    }

    .custom-grid-inputs {
      display: inline-flex;
      align-items: center;
      gap: 6px;
      padding: 4px 8px;
      border: 1px solid var(--control-border);
      border-radius: 13px;
      background: var(--control-bg);
      box-shadow: 0 2px 10px rgba(16, 35, 73, 0.03);
      backdrop-filter: blur(12px);
      transition: border-color 0.18s ease, box-shadow 0.18s ease, background 0.18s ease;
    }

    .custom-grid-inputs.active {
      border-color: #9fbbff;
      background: rgba(123, 163, 255, 0.08);
      box-shadow: 0 4px 12px rgba(79, 134, 255, 0.08);
    }

    .grid-number {
      width: 56px;
      border: 1px solid var(--control-border);
      border-radius: 11px;
      padding: 7px 6px;
      font: inherit;
      font-weight: 700;
      color: var(--text);
      background: #fbfcff;
      outline: none;
      text-align: center;
      box-shadow: inset 0 1px 2px rgba(16, 35, 73, 0.02);
    }

    .grid-wrap {
      flex: 1 1 auto;
      min-height: 0;
      display: flex;
      align-items: stretch;
      justify-content: center;
    }

    .grid {
      height: 100%;
      display: grid;
      grid-template-columns: repeat(var(--grid-cols), minmax(0, 1fr));
      grid-template-rows: repeat(var(--grid-rows), minmax(0, 1fr));
      gap: var(--tile-gap);
      align-content: stretch;
    }

    .tile {
      position: relative;
      min-height: 0;
      height: 100%;
      border-radius: var(--radius);
      overflow: hidden;
      border: 1px solid var(--card-border);
      background: var(--card);
      box-shadow: var(--shadow);
      transition: transform 0.26s ease, box-shadow 0.26s ease, border-color 0.26s ease;
      cursor: pointer;
      isolation: isolate;
    }

    .tile:hover {
      transform: translateY(-3px) scale(1.005);
      box-shadow: var(--shadow-hover);
      border-color: #c9d7ef;
    }

    .tile.empty {
      border-style: dashed;
      border-color: var(--card-border-dashed);
      box-shadow: none;
      background: rgba(255,255,255,0.7);
      display: flex;
      align-items: center;
      justify-content: center;
      text-align: center;
      padding: 20px;
    }

    .tile-main {
      position: relative;
      height: 100%;
      display: grid;
      grid-template-rows: 58% 42%;
      text-decoration: none;
      color: inherit;
    }

    .tile-logo-zone {
      display: flex;
      align-items: center;
      justify-content: center;
      padding: 10px 16px 0;
      min-height: 0;
      background: var(--card);
    }

    .tile-logo-stage {
      width: var(--logo-stage-size);
      height: var(--logo-stage-size);
      border-radius: 30px;
      background: #fff;
      display: flex;
      align-items: center;
      justify-content: center;
      box-shadow: 0 12px 26px rgba(16, 35, 73, 0.08);
      overflow: hidden;
      padding: 12px;
      flex: 0 0 auto;
    }

    .tile-logo {
      display: block;
      max-width: 100%;
      max-height: 100%;
      width: auto;
      height: auto;
      object-fit: contain;
      object-position: center;
      image-rendering: auto;
      transform: scale(1.12);
      transform-origin: center center;
    }

    .tile-logo.placeholder {
      width: var(--placeholder-size);
      height: var(--placeholder-size);
      max-width: none;
      max-height: none;
      border-radius: 28px;
      background: linear-gradient(180deg, #9d95ff, #6f83ff);
      display: inline-flex;
      align-items: center;
      justify-content: center;
      color: white;
      font-size: clamp(2rem, 3.4vh, 2.8rem);
      font-weight: 800;
      letter-spacing: -0.05em;
      box-shadow: 0 12px 24px rgba(103, 126, 255, 0.28);
      text-transform: uppercase;
      user-select: none;
    }

    .tile-info {
      display: flex;
      align-items: center;
      justify-content: center;
      padding: 8px 20px 18px;
      background: var(--card);
      overflow: hidden;
    }

    .tile-text-stack {
      width: 100%;
      display: flex;
      flex-direction: column;
      align-items: flex-start;
      justify-content: center;
      text-align: left;
      min-height: 0;
    }

    .tile-title {
      margin: 0;
      width: 100%;
      font-size: var(--title-size);
      font-weight: 800;
      letter-spacing: -0.03em;
      line-height: 1.1;
      text-align: left;
      transform: translateY(12px);
      transition: transform 0.26s ease;
      white-space: nowrap;
      overflow: hidden;
      text-overflow: ellipsis;
    }

    .tile-desc {
      margin: 9px 0 0;
      width: 100%;
      font-size: var(--desc-size);
      line-height: 1.32;
      color: var(--muted);
      text-align: left;
      opacity: 0;
      transform: translateY(16px);
      transition: opacity 0.22s ease, transform 0.26s ease;
      display: -webkit-box;
      -webkit-line-clamp: 2;
      -webkit-box-orient: vertical;
      overflow: hidden;
      min-height: 2.55em;
    }

    .tile:hover .tile-title,
    .tile:focus-visible .tile-title {
      transform: translateY(-4px);
    }

    .tile:hover .tile-desc,
    .tile:focus-visible .tile-desc {
      opacity: 1;
      transform: translateY(0);
    }

    .tile-actions {
      position: absolute;
      top: 12px;
      right: 12px;
      display: flex;
      gap: 8px;
      opacity: 0;
      transform: translateY(-6px);
      transition: opacity 0.18s ease, transform 0.18s ease;
      z-index: 3;
    }

    .tile:hover .tile-actions,
    .tile:focus-within .tile-actions,
    body.edit-mode .tile-actions {
      opacity: 1;
      transform: translateY(0);
    }

    body.edit-mode .tile.draggable {
      cursor: grab;
    }

    body.edit-mode .tile.draggable .tile-main {
      cursor: grab;
    }

    body.edit-mode .tile.dragging {
      opacity: 0.45;
      transform: scale(0.98);
    }

    body.edit-mode .tile.drag-over {
      border-color: #8fb0ff;
      box-shadow: inset 0 0 0 2px rgba(79, 134, 255, 0.35);
    }

    .icon-btn {
      width: 36px;
      height: 36px;
      border: 1px solid rgba(214, 223, 239, 0.95);
      border-radius: 14px;
      background: rgba(255,255,255,0.95);
      color: var(--text);
      font: inherit;
      font-size: 1rem;
      font-weight: 800;
      cursor: pointer;
      box-shadow: 0 8px 18px rgba(16, 35, 73, 0.08);
    }

    .empty-plus-frame {
      width: var(--logo-stage-size);
      height: var(--logo-stage-size);
      border-radius: 30px;
      display: inline-flex;
      align-items: center;
      justify-content: center;
      background: #fff;
      color: var(--accent);
      border: 1px solid #d8e2f2;
      font-size: 2rem;
      font-weight: 700;
      box-shadow: 0 10px 22px rgba(16, 35, 73, 0.06);
    }

    .pagination {
      flex: 0 0 auto;
      display: flex;
      justify-content: center;
      align-items: center;
      gap: 14px;
      padding-top: 2px;
      flex-wrap: wrap;
    }

    .page-indicator {
      font-weight: 700;
      color: var(--muted);
      min-width: 110px;
      text-align: center;
    }

    dialog {
      width: min(720px, calc(100vw - 32px));
      border: none;
      border-radius: 28px;
      padding: 0;
      box-shadow: 0 30px 80px rgba(11, 24, 52, 0.22);
      overflow: hidden;
    }

    dialog::backdrop {
      background: rgba(20, 31, 51, 0.45);
      backdrop-filter: blur(4px);
    }

    .modal-header {
      padding: 22px 24px 14px;
      border-bottom: 1px solid #e7edf8;
      background: #fff;
    }

    .modal-title {
      margin: 0;
      font-size: 1.4rem;
      letter-spacing: -0.03em;
    }

    .modal-body {
      padding: 22px 24px 24px;
      background: #fff;
    }

    .form-grid { display: grid; gap: 16px; }
    .field { display: grid; gap: 8px; }

    .field label {
      font-size: 0.96rem;
      font-weight: 700;
      color: var(--text);
    }

    .field input,
    .field textarea {
      width: 100%;
      border: 1px solid #d9e3f3;
      border-radius: 16px;
      padding: 14px 16px;
      font: inherit;
      color: var(--text);
      background: #fbfcff;
      outline: none;
    }

    .field textarea {
      min-height: 110px;
      resize: vertical;
    }

    .modal-preview {
      margin-top: 10px;
      border: 1px solid #dbe4f3;
      border-radius: 22px;
      background: #fff;
      height: 180px;
      display: flex;
      align-items: center;
      justify-content: center;
      overflow: hidden;
    }

    .modal-preview img {
      max-width: 76%;
      max-height: 76%;
      object-fit: contain;
    }

    .modal-footer {
      display: flex;
      justify-content: space-between;
      gap: 12px;
      margin-top: 20px;
      flex-wrap: wrap;
    }

    .hint {
      color: var(--muted);
      font-size: 0.92rem;
      line-height: 1.4;
      margin: 0;
    }

    @media (max-width: 980px) {
      .page {
        padding: 12px 16px;
      }
    }

    @media (max-width: 640px) {
      html, body {
        overflow: auto;
      }

      .topbar {
        flex-direction: column;
        align-items: flex-start;
      }

      .page {
        height: auto;
      }

      .grid-wrap {
        flex: none;
      }

      .grid {
        height: auto;
        grid-template-columns: 1fr;
        grid-template-rows: repeat(12, minmax(180px, 1fr));
      }
    }
  </style>
</head>
<body>
  <header class="topbar">
    <div class="brand">
      <div class="brand-mark"></div>
      <div id="brandTitle">PenguLab</div>
    </div>
    <div class="toolbar-actions"></div>
  </header>

  <main class="page">
    <section class="page-header">
      <div class="page-header-bar">
        <h1 class="page-title" id="appsTitle">Apps</h1>
        <div class="page-header-center">
          <input class="search-input" id="searchInput" type="text" aria-label="Search apps" />
        </div>
        <div class="settings-wrap">
          <button class="settings-btn" id="settingsBtn" type="button" aria-label="Einstellungen">⚙</button>
          <div class="settings-panel" id="settingsPanel" hidden>
            <div class="settings-section">
              <label class="settings-label" id="settingsLabelLanguage" for="languageSelect">Sprache</label>
              <select class="category-select" id="languageSelect" aria-label="Sprache"></select>
            </div>
            <div class="settings-section">
              <label class="settings-label" id="settingsLabelCategory" for="categoryFilter">Kategorie</label>
              <select class="category-select" id="categoryFilter" aria-label="Kategorie filtern"></select>
            </div>
            <div class="settings-section">
              <label class="settings-label" id="settingsLabelGrid" for="customCols">Raster</label>
              <div class="custom-grid-inputs active" id="customGridInputs" aria-label="Rastergröße">
                <input class="grid-number" id="customCols" type="number" min="1" max="12" step="1" value="5" aria-label="Spalten" />
                <span>×</span>
                <input class="grid-number" id="customRows" type="number" min="1" max="12" step="1" value="3" aria-label="Zeilen" />
              </div>
            </div>
            <div class="settings-section">
              <label class="settings-label" id="settingsLabelDesign">Design</label>
              <div class="theme-switch">
                <button class="theme-btn" id="lightModeBtn" type="button" aria-label="Lightmode">☀</button>
                <button class="theme-btn" id="darkModeBtn" type="button" aria-label="Darkmode">☾</button>
              </div>
            </div>
            <div class="settings-section">
              <label class="settings-label" id="settingsLabelActions">Aktionen</label>
              <div class="panel-actions">
                <button class="btn" id="editModeBtn" type="button">Bearbeiten</button>
                <button class="btn primary" id="addBtn" type="button">Neue App</button>
              </div>
            </div>
            <div class="settings-section">
              <label class="settings-label" id="settingsLabelConfig">Konfiguration</label>
              <div class="panel-actions">
                <button class="btn" id="exportBtn" type="button">Export</button>
                <label class="btn" for="importFile" style="cursor:pointer;">Import</label>
                <input id="importFile" type="file" accept="application/json" hidden />
              </div>
            </div>
          </div>
        </div>
      </div>
    </section>

    <section class="grid-wrap">
      <div class="grid" id="grid"></div>
    </section>

    <section class="pagination">
      <button class="btn" id="prevPage" type="button">← Zurück</button>
      <div class="page-indicator" id="pageIndicator">Seite 1 / 1</div>
      <button class="btn" id="nextPage" type="button">Weiter →</button>
    </section>
  </main>

  <dialog id="appDialog">
    <form method="dialog" id="appForm">
      <div class="modal-header">
        <h2 class="modal-title" id="dialogTitle">App anlegen</h2>
      </div>
      <div class="modal-body">
        <div class="form-grid">
          <div class="field">
            <label id="labelAppName" for="appName">App-Name</label>
            <input id="appName" name="appName" type="text" maxlength="80" required />
          </div>
          <div class="field">
            <label id="labelAppUrl" for="appUrl">URL</label>
            <input id="appUrl" name="appUrl" type="url" placeholder="https://..." required />
          </div>
          <div class="field">
            <label id="labelAppDescription" for="appDescription">Beschreibung</label>
            <textarea id="appDescription" name="appDescription" maxlength="240" placeholder="Kurze Beschreibung..."></textarea>
          </div>
          <div class="field">
            <label id="labelAppCategory" for="appCategory">Kategorie</label>
            <input id="appCategory" name="appCategory" type="text" maxlength="60" list="categoryOptions" placeholder="z. B. Smart Home" />
            <datalist id="categoryOptions"></datalist>
          </div>
          <div class="field">
            <label id="labelAppImage" for="appImage">Logo / Bild</label>
            <input id="appImage" name="appImage" type="file" accept="image/*" />
            <p class="hint" id="imageHint">Logos werden beim Hochladen automatisch auf transparente Ränder geprüft, damit sie größer und einheitlicher wirken.</p>
          </div>
          <div class="modal-preview" id="modalPreview"></div>
        </div>

        <div class="modal-footer">
          <div style="display:flex; gap:12px; flex-wrap:wrap;">
            <button class="btn primary" id="saveBtn" value="default" type="submit">Speichern</button>
            <button class="btn" id="cancelBtn" value="cancel" type="button">Abbrechen</button>
            <button class="btn" id="removeImageBtn" type="button">Logo entfernen</button>
            <button class="btn" id="cloneBtn" type="button" style="display:none;">Klonen</button>
          </div>
          <button class="btn" id="deleteBtn" type="button" style="margin-left:auto; display:none;">App löschen</button>
        </div>
      </div>
    </form>
  </dialog>

  <script>
    const API_URL = '<?php echo htmlspecialchars(basename(__FILE__), ENT_QUOTES, 'UTF-8'); ?>?api=apps';
    const DEFAULT_SETTINGS = {
      selectedCategory: 'all',
      viewMode: 'custom',
      rows: 3,
      cols: 5,
      theme: 'light',
      language: 'de'
    };

    let apps = [];
    let settings = { ...DEFAULT_SETTINGS };
    let availableLanguages = [];
    let translations = {};
    let urlCategoryOverride = (() => {
      const raw = new URLSearchParams(window.location.search).get('category') || '';
      const cleaned = raw.trim();
      return cleaned.length <= 120 ? cleaned : '';
    })();
    let searchQuery = '';
    let suggestedImage = '';
    let faviconLookupTimer = null;
    let currentPage = 0;
    let editingId = null;
    let pendingImage = null;
    let keepExistingImage = true;
    let editMode = false;
    let draggedId = null;
    let cloneSourceId = null;

    const grid = document.getElementById('grid');
    const pageIndicator = document.getElementById('pageIndicator');
    const prevPageBtn = document.getElementById('prevPage');
    const nextPageBtn = document.getElementById('nextPage');
    const appDialog = document.getElementById('appDialog');
    const appForm = document.getElementById('appForm');
    const searchInput = document.getElementById('searchInput');
    const settingsBtn = document.getElementById('settingsBtn');
    const settingsPanel = document.getElementById('settingsPanel');
    const languageSelect = document.getElementById('languageSelect');
    const categoryFilter = document.getElementById('categoryFilter');
    const customGridInputs = document.getElementById('customGridInputs');
    const customCols = document.getElementById('customCols');
    const customRows = document.getElementById('customRows');
    const lightModeBtn = document.getElementById('lightModeBtn');
    const darkModeBtn = document.getElementById('darkModeBtn');
    const dialogTitle = document.getElementById('dialogTitle');
    const appName = document.getElementById('appName');
    const appUrl = document.getElementById('appUrl');
    const appDescription = document.getElementById('appDescription');
    const appCategory = document.getElementById('appCategory');
    const appImage = document.getElementById('appImage');
    const modalPreview = document.getElementById('modalPreview');
    const deleteBtn = document.getElementById('deleteBtn');
    const cloneBtn = document.getElementById('cloneBtn');
    const addBtn = document.getElementById('addBtn');
    const editModeBtn = document.getElementById('editModeBtn');
    const cancelBtn = document.getElementById('cancelBtn');
    const removeImageBtn = document.getElementById('removeImageBtn');
    const exportBtn = document.getElementById('exportBtn');
    const importFile = document.getElementById('importFile');
    const categoryOptions = document.getElementById('categoryOptions');

    settingsPanel.hidden = true;

    function normalizeApps(input) {
      if (!Array.isArray(input)) return [];
      return input.map((entry) => ({
        id: String(entry.id || (crypto.randomUUID ? crypto.randomUUID() : Date.now())),
        name: String(entry.name || '').trim(),
        url: String(entry.url || '').trim(),
        description: String(entry.description || '').trim(),
        category: String(entry.category || '').trim(),
        image: String(entry.image || '')
      })).filter((entry) => entry.name && entry.url);
    }

    function clampInt(value, fallback) {
      const parsed = parseInt(value, 10);
      if (Number.isNaN(parsed)) return fallback;
      return Math.max(1, Math.min(12, parsed));
    }

    function normalizeSettings(input) {
      const next = {
        ...DEFAULT_SETTINGS,
        ...(input && typeof input === 'object' ? input : {})
      };

      if (!['4x3', '6x4', 'custom'].includes(next.viewMode)) {
        next.viewMode = '4x3';
      }

      next.rows = clampInt(next.rows, DEFAULT_SETTINGS.rows);
      next.cols = clampInt(next.cols, DEFAULT_SETTINGS.cols);
      next.selectedCategory = String(next.selectedCategory || 'all');
      next.theme = next.theme === 'dark' ? 'dark' : 'light';
      next.language = String(next.language || 'de');

      if (next.viewMode === '4x3') {
        next.rows = 3;
        next.cols = 4;
      } else if (next.viewMode === '6x4') {
        next.rows = 4;
        next.cols = 6;
      }

      return next;
    }

    function t(key, vars = {}) {
      let value = translations[key] || key;
      Object.entries(vars).forEach(([name, replacement]) => {
        value = value.replaceAll(`{${name}}`, String(replacement));
      });
      return value;
    }

    async function loadLanguage(code) {
      try {
        const endpoint = `${API_URL.replace('api=apps', 'api=lang')}&code=${encodeURIComponent(code)}`;
        const response = await fetch(endpoint, { cache: 'no-store' });
        const data = await response.json();
        if (response.ok && data.ok && data.pack) {
          translations = data.pack;
        }
      } catch (error) {
        console.error(error);
      }

      try {
        applyTranslations();
      } catch (error) {
        console.error(error);
      }
    }

    function applyTranslations() {
      const setText = (id, value) => {
        const element = document.getElementById(id);
        if (element) element.textContent = value;
      };

      setText('brandTitle', t('brand'));
      setText('appsTitle', t('apps'));
      setText('settingsLabelLanguage', t('settings_language'));
      setText('settingsLabelCategory', t('settings_category'));
      setText('settingsLabelGrid', t('settings_grid'));
      setText('settingsLabelDesign', t('settings_design'));
      setText('settingsLabelActions', t('settings_actions'));
      setText('settingsLabelConfig', t('settings_configuration'));

      if (settingsBtn) {
        settingsBtn.title = t('settings');
        settingsBtn.setAttribute('aria-label', t('settings'));
      }

      if (editModeBtn) editModeBtn.textContent = editMode ? t('done') : t('edit');
      if (addBtn) addBtn.textContent = t('new_app');
      if (exportBtn) exportBtn.textContent = t('export');
      if (importLabel) importLabel.textContent = t('import');
      if (prevPageBtn) prevPageBtn.textContent = t('prev');
      if (nextPageBtn) nextPageBtn.textContent = t('next');

      if (lightModeBtn) {
        lightModeBtn.title = t('light');
        lightModeBtn.setAttribute('aria-label', t('light'));
      }
      if (darkModeBtn) {
        darkModeBtn.title = t('dark');
        darkModeBtn.setAttribute('aria-label', t('dark'));
      }

      setText('labelAppName', t('field_name'));
      setText('labelAppUrl', t('field_url'));
      setText('labelAppDescription', t('field_description'));
      setText('labelAppCategory', t('field_category'));
      setText('labelAppImage', t('field_image'));
      setText('imageHint', t('image_hint'));

      if (saveBtn) saveBtn.textContent = t('save');
      if (cancelBtn) cancelBtn.textContent = t('cancel');
      if (removeImageBtn) removeImageBtn.textContent = t('remove_image');
      if (cloneBtn) cloneBtn.textContent = t('clone');
      if (deleteBtn) deleteBtn.textContent = t('delete');
    }

    function getCategories() {
      return [...new Set(
        apps
          .map((entry) => (entry.category || '').trim())
          .filter(Boolean)
      )].sort((a, b) => a.localeCompare(b, 'de', { sensitivity: 'base' }));
    }

    function getSelectedCategory() {
      return urlCategoryOverride || settings.selectedCategory || 'all';
    }

    function getFilteredApps() {
      const selected = getSelectedCategory();
      let filtered = apps;

      if (selected === '__uncategorized__') {
        filtered = filtered.filter((entry) => !(entry.category || '').trim());
      } else if (selected !== 'all') {
        filtered = filtered.filter((entry) => (entry.category || '').trim() === selected);
      }

      const needle = searchQuery.trim().toLowerCase();
      if (!needle) {
        return filtered;
      }

      return filtered.filter((entry) => {
        const haystack = [
          entry.name || '',
          entry.description || '',
          entry.category || ''
        ].join(' ').toLowerCase();

        return haystack.includes(needle);
      });
    }

    function getTilesPerPage() {
      return Math.max(1, settings.rows * settings.cols);
    }

    function applyThemeSettings() {
      document.body.dataset.theme = settings.theme === 'dark' ? 'dark' : 'light';
      lightModeBtn.classList.toggle('active', settings.theme !== 'dark');
      darkModeBtn.classList.toggle('active', settings.theme === 'dark');
    }

    function applyGridSettings() {
      settings = normalizeSettings(settings);
      grid.style.setProperty('--grid-cols', String(settings.cols));
      grid.style.setProperty('--grid-rows', String(settings.rows));

      customCols.value = String(settings.cols);
      customRows.value = String(settings.rows);
      customGridInputs.classList.add('active');
    }

    function updateCategoryControls() {
      const categories = getCategories();
      const hasUncategorized = apps.some((entry) => !(entry.category || '').trim());

      const options = [
        { value: 'all', label: t('all_categories') },
        ...categories.map((category) => ({ value: category, label: category })),
      ];

      if (hasUncategorized) {
        options.push({ value: '__uncategorized__', label: t('uncategorized') });
      }

      categoryFilter.innerHTML = options
        .map((option) => `<option value="${escapeAttribute(option.value)}">${escapeHtml(option.label)}</option>`)
        .join('');

      const validValues = new Set(options.map((option) => option.value));

      if (urlCategoryOverride && validValues.has(urlCategoryOverride)) {
        categoryFilter.value = urlCategoryOverride;
      } else {
        urlCategoryOverride = '';
        settings.selectedCategory = validValues.has(settings.selectedCategory) ? settings.selectedCategory : 'all';
        categoryFilter.value = settings.selectedCategory;
      }

      categoryOptions.innerHTML = categories
        .map((category) => `<option value="${escapeAttribute(category)}"></option>`)
        .join('');

      languageSelect.innerHTML = availableLanguages
        .map((entry) => `<option value="${escapeAttribute(entry.code)}">${escapeHtml(entry.label)}</option>`)
        .join('');
      languageSelect.value = settings.language || 'de';
    }

    async function loadApps() {
      try {
        const response = await fetch(API_URL, { cache: 'no-store' });
        const data = await response.json();
        if (!response.ok || !data.ok) throw new Error(data.error || 'Apps konnten nicht geladen werden.');
        apps = normalizeApps(data.apps);
        settings = normalizeSettings(data.settings);
        availableLanguages = Array.isArray(data.availableLanguages) ? data.availableLanguages : [];
        applyThemeSettings();
        applyGridSettings();
        updateCategoryControls();
        await loadLanguage(settings.language || 'de');
        render();
      } catch (error) {
        console.error(error);
        apps = [];
        settings = normalizeSettings(DEFAULT_SETTINGS);
        availableLanguages = [{ code: 'de', label: 'Deutsch' }, { code: 'en', label: 'English' }];
        applyThemeSettings();
        applyGridSettings();
        updateCategoryControls();
        await loadLanguage(settings.language || 'de');
        render();
      }
    }

    async function saveApps() {
      const response = await fetch(API_URL, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ apps, settings })
      });

      const data = await response.json().catch(() => ({}));
      if (!response.ok || !data.ok) {
        throw new Error(data.error || 'Speichern fehlgeschlagen.');
      }
      apps = normalizeApps(data.apps);
      settings = normalizeSettings(data.settings);
      availableLanguages = Array.isArray(data.availableLanguages) ? data.availableLanguages : availableLanguages;
      applyThemeSettings();
      applyGridSettings();
      updateCategoryControls();
      await loadLanguage(settings.language || 'de');
      render();
    }

    function totalPages() {
      return Math.max(1, Math.ceil(getFilteredApps().length / getTilesPerPage()));
    }

    function getInitial(name = '') {
      const cleaned = String(name).trim();
      return cleaned ? cleaned[0].toUpperCase() : '+';
    }

    function setEditMode(enabled) {
      editMode = enabled;
      document.body.classList.toggle('edit-mode', editMode);
      editModeBtn.classList.toggle('active', editMode);
      editModeBtn.textContent = editMode ? t('done') : t('edit');
      render();
    }

    async function moveApp(dragId, targetId) {
      if (!dragId || !targetId || dragId === targetId) return;

      const fromIndex = apps.findIndex((entry) => entry.id === dragId);
      const toIndex = apps.findIndex((entry) => entry.id === targetId);

      if (fromIndex === -1 || toIndex === -1) return;

      const updated = [...apps];
      const [moved] = updated.splice(fromIndex, 1);
      updated.splice(toIndex, 0, moved);
      apps = updated;

      try {
        await saveApps();
      } catch (error) {
        console.error(error);
      }
    }

    function createLogoMarkup(app) {
      const initial = escapeHtml(getInitial(app.name));

      if (app.image) {
        return `
          <div class="tile-logo-stage">
            <img
              class="tile-logo"
              src="${escapeHtml(app.image)}"
              alt="${escapeHtml(app.name)}"
              onerror="this.style.display='none'; this.nextElementSibling.style.display='inline-flex';"
            />
            <div class="tile-logo placeholder" aria-hidden="true" style="display:none;">${initial}</div>
          </div>
        `;
      }

      return `<div class="tile-logo placeholder" aria-hidden="true">${initial}</div>`;
    }

    function render() {
      const pages = totalPages();
      if (currentPage >= pages) currentPage = pages - 1;

      applyGridSettings();
      const filteredApps = getFilteredApps();
      const tilesPerPage = getTilesPerPage();
      const start = currentPage * tilesPerPage;
      const pageItems = filteredApps.slice(start, start + tilesPerPage);
      const empties = tilesPerPage - pageItems.length;

      grid.innerHTML = '';

      pageItems.forEach((app) => {
        const tile = document.createElement('article');
        tile.className = 'tile';
        tile.innerHTML = `
          <div class="tile-actions">
            <button class="icon-btn" type="button" data-action="edit" data-id="${app.id}" title="Bearbeiten">✎</button>
            <button class="icon-btn" type="button" data-action="delete" data-id="${app.id}" title="Löschen">×</button>
          </div>
          <a class="tile-main" href="${editMode ? '#' : escapeAttribute(app.url)}" target="${editMode ? '_self' : '_blank'}" rel="noreferrer noopener" aria-label="${escapeAttribute(app.name)} öffnen">
            <div class="tile-logo-zone">
              ${createLogoMarkup(app)}
            </div>
            <div class="tile-info">
              <div class="tile-text-stack">
                <h3 class="tile-title">${escapeHtml(app.name)}</h3>
                <p class="tile-desc">${escapeHtml(app.description || t('no_description'))}</p>
              </div>
            </div>
          </a>
        `;
        if (editMode) {
          tile.classList.add('draggable');
          tile.draggable = true;

          tile.addEventListener('dragstart', (event) => {
            draggedId = app.id;
            tile.classList.add('dragging');
            if (event.dataTransfer) {
              event.dataTransfer.effectAllowed = 'move';
              event.dataTransfer.setData('text/plain', app.id);
            }
          });

          tile.addEventListener('dragend', () => {
            draggedId = null;
            tile.classList.remove('dragging');
            document.querySelectorAll('.tile.drag-over').forEach((el) => el.classList.remove('drag-over'));
          });

          tile.addEventListener('dragover', (event) => {
            event.preventDefault();
            if (draggedId && draggedId !== app.id) {
              tile.classList.add('drag-over');
            }
          });

          tile.addEventListener('dragleave', () => {
            tile.classList.remove('drag-over');
          });

          tile.addEventListener('drop', async (event) => {
            event.preventDefault();
            tile.classList.remove('drag-over');
            const sourceId = draggedId || event.dataTransfer?.getData('text/plain');
            await moveApp(sourceId, app.id);
          });
        }

        grid.appendChild(tile);
      });

      for (let i = 0; i < empties; i++) {
        const tile = document.createElement('button');
        tile.className = 'tile empty';
        tile.type = 'button';
        tile.innerHTML = `
          <div class="empty-plus-frame">+</div>
        `;
        tile.addEventListener('click', openCreateDialog);
        grid.appendChild(tile);
      }

      pageIndicator.textContent = t('page_of', { current: currentPage + 1, total: pages });
      prevPageBtn.disabled = currentPage === 0;
      nextPageBtn.disabled = currentPage >= pages - 1;

      if (editMode) {
        document.querySelectorAll('.tile-main').forEach((link) => {
          link.addEventListener('click', (event) => {
            event.preventDefault();
          });
        });
      }

      document.querySelectorAll('[data-action="edit"]').forEach((btn) => {
        btn.addEventListener('click', (event) => {
          event.preventDefault();
          event.stopPropagation();
          openEditDialog(btn.dataset.id);
        });
      });

      document.querySelectorAll('[data-action="delete"]').forEach((btn) => {
        btn.addEventListener('click', async (event) => {
          event.preventDefault();
          event.stopPropagation();
          await removeApp(btn.dataset.id);
        });
      });
    }

    function openCreateDialog() {
      editingId = null;
      pendingImage = null;
      suggestedImage = '';
      keepExistingImage = false;
      dialogTitle.textContent = t('dialog_create');
      deleteBtn.style.display = 'none';
      cloneBtn.style.display = 'none';
      cloneSourceId = null;
      appForm.reset();
      const selectedCategory = getSelectedCategory();
      appCategory.value = (selectedCategory !== 'all' && selectedCategory !== '__uncategorized__') ? selectedCategory : '';
      updateModalPreview();
      appDialog.showModal();
      appName.focus();
    }

    function openEditDialog(id) {
      const app = apps.find((entry) => entry.id === id);
      if (!app) return;

      editingId = id;
      pendingImage = null;
      suggestedImage = '';
      keepExistingImage = true;
      dialogTitle.textContent = t('dialog_edit');
      deleteBtn.style.display = 'inline-flex';
      cloneBtn.style.display = 'inline-flex';
      cloneSourceId = id;
      appName.value = app.name || '';
      appUrl.value = app.url || '';
      appDescription.value = app.description || '';
      appCategory.value = app.category || '';
      appImage.value = '';
      updateModalPreview();
      appDialog.showModal();
      appName.focus();
    }

    function closeDialog() {
      appDialog.close();
      appForm.reset();
      editingId = null;
      pendingImage = null;
      suggestedImage = '';
      keepExistingImage = true;
      cloneSourceId = null;
    }

    async function removeApp(id) {
      const app = apps.find((entry) => entry.id === id);
      if (!app) return;
      const ok = confirm(t('confirm_delete', { name: app.name }));
      if (!ok) return;
      apps = apps.filter((entry) => entry.id !== id);
      try {
        await saveApps();
      } catch (error) {
        console.error(error);
      }
    }

    function fileToDataUrl(file) {
      return new Promise((resolve, reject) => {
        const reader = new FileReader();
        reader.onload = () => resolve(reader.result);
        reader.onerror = reject;
        reader.readAsDataURL(file);
      });
    }

    async function trimTransparentPadding(dataUrl) {
      return new Promise((resolve) => {
        const img = new Image();
        img.onload = () => {
          const canvas = document.createElement('canvas');
          canvas.width = img.width;
          canvas.height = img.height;
          const ctx = canvas.getContext('2d', { willReadFrequently: true });
          ctx.drawImage(img, 0, 0);

          const { data, width, height } = ctx.getImageData(0, 0, canvas.width, canvas.height);
          let top = height, left = width, right = -1, bottom = -1;

          for (let y = 0; y < height; y++) {
            for (let x = 0; x < width; x++) {
              const i = (y * width + x) * 4;
              const alpha = data[i + 3];
              if (alpha > 12) {
                if (x < left) left = x;
                if (x > right) right = x;
                if (y < top) top = y;
                if (y > bottom) bottom = y;
              }
            }
          }

          if (right === -1 || bottom === -1) {
            resolve(dataUrl);
            return;
          }

          const cropWidth = right - left + 1;
          const cropHeight = bottom - top + 1;
          const size = Math.max(cropWidth, cropHeight);
          const pad = Math.max(18, Math.round(size * 0.14));
          const outSize = size + pad * 2;

          const out = document.createElement('canvas');
          out.width = outSize;
          out.height = outSize;
          const outCtx = out.getContext('2d');
          outCtx.clearRect(0, 0, outSize, outSize);

          const dx = Math.round((outSize - cropWidth) / 2);
          const dy = Math.round((outSize - cropHeight) / 2);
          outCtx.drawImage(canvas, left, top, cropWidth, cropHeight, dx, dy, cropWidth, cropHeight);

          resolve(out.toDataURL('image/png'));
        };
        img.onerror = () => resolve(dataUrl);
        img.src = dataUrl;
      });
    }

    async function lookupSuggestedLogo(url) {
      const trimmed = String(url || '').trim();
      if (!trimmed || !/^https?:\/\//i.test(trimmed)) {
        suggestedImage = '';
        updateModalPreview();
        return;
      }

      try {
        const endpoint = `${API_URL.replace('api=apps', 'api=favicon')}&url=${encodeURIComponent(trimmed)}`;
        const response = await fetch(endpoint, { cache: 'no-store' });
        const data = await response.json();

        suggestedImage = data.ok ? String(data.image || '') : '';
      } catch (error) {
        console.error(error);
        suggestedImage = '';
      }

      updateModalPreview();
    }

    function updateModalPreview() {
      const existingImage = editingId && keepExistingImage ? apps.find((entry) => entry.id === editingId)?.image || '' : '';
      const imageToPreview = pendingImage || existingImage || suggestedImage || '';
      renderModalPreview(imageToPreview, appName.value);
    }

    function renderModalPreview(image, name = '') {
      const initial = escapeHtml(getInitial(name));

      if (image) {
        modalPreview.innerHTML = `
          <div class="tile-logo-stage">
            <img
              src="${escapeHtml(image)}"
              alt="${escapeHtml(name || 'Vorschau')}"
              style="max-width:76%; max-height:76%; object-fit:contain;"
              onerror="this.style.display='none'; this.nextElementSibling.style.display='inline-flex';"
            />
            <div class="tile-logo placeholder" style="display:none;">${initial}</div>
          </div>
        `;
      } else {
        modalPreview.innerHTML = `<div class="tile-logo placeholder">${initial}</div>`;
      }
    }

    function escapeHtml(value) {
      return String(value ?? '')
        .replaceAll('&', '&amp;')
        .replaceAll('<', '&lt;')
        .replaceAll('>', '&gt;')
        .replaceAll('"', '&quot;')
        .replaceAll("'", '&#39;');
    }

    function escapeAttribute(value) {
      return escapeHtml(value);
    }

    addBtn.addEventListener('click', openCreateDialog);

    editModeBtn.addEventListener('click', () => {
      setEditMode(!editMode);
    });

    settingsBtn.addEventListener('click', (event) => {
      event.stopPropagation();
      settingsPanel.hidden = !settingsPanel.hidden;
    });

    document.addEventListener('click', (event) => {
      if (!settingsWrapContains(event.target)) {
        settingsPanel.hidden = true;
      }
    });

    function settingsWrapContains(target) {
      return settingsBtn.contains(target) || settingsPanel.contains(target);
    }

    lightModeBtn.addEventListener('click', async () => {
      settings.theme = 'light';
      applyThemeSettings();
      try {
        await saveApps();
      } catch (error) {
        console.error(error);
      }
    });

    darkModeBtn.addEventListener('click', async () => {
      settings.theme = 'dark';
      applyThemeSettings();
      try {
        await saveApps();
      } catch (error) {
        console.error(error);
      }
    });

    languageSelect.addEventListener('change', async () => {
      settings.language = languageSelect.value || 'de';
      try {
        await saveApps();
      } catch (error) {
        console.error(error);
      }
    });

    categoryFilter.addEventListener('change', async () => {
      urlCategoryOverride = '';
      settings.selectedCategory = categoryFilter.value || 'all';
      currentPage = 0;
      render();
      try {
        await saveApps();
      } catch (error) {
        console.error(error);
      }
    });

    async function saveCustomGridSettings() {
      settings.viewMode = 'custom';
      settings.cols = clampInt(customCols.value, settings.cols || 5);
      settings.rows = clampInt(customRows.value, settings.rows || 3);
      currentPage = 0;
      applyGridSettings();
      render();
      try {
        await saveApps();
      } catch (error) {
        console.error(error);
      }
    }

    [customCols, customRows].forEach((input) => {
      input.addEventListener('focus', () => {
        settings.viewMode = 'custom';
      });

      input.addEventListener('change', saveCustomGridSettings);

      input.addEventListener('keydown', (event) => {
        if (event.key === 'Enter') {
          event.preventDefault();
          saveCustomGridSettings();
          input.blur();
        }
      });
    });

    customGridInputs.addEventListener('focusout', () => {
      setTimeout(() => {
        if (!customGridInputs.contains(document.activeElement)) {
          saveCustomGridSettings();
        }
      }, 0);
    });

    searchInput.addEventListener('input', () => {
      searchQuery = searchInput.value || '';
      currentPage = 0;
      render();
    });

    prevPageBtn.addEventListener('click', () => {
      if (currentPage > 0) {
        currentPage -= 1;
        render();
      }
    });

    nextPageBtn.addEventListener('click', () => {
      if (currentPage < totalPages() - 1) {
        currentPage += 1;
        render();
      }
    });

    cancelBtn.addEventListener('click', closeDialog);

    deleteBtn.addEventListener('click', async () => {
      if (editingId) {
        const id = editingId;
        closeDialog();
        await removeApp(id);
      }
    });

    cloneBtn.addEventListener('click', () => {
      const sourceId = cloneSourceId || editingId;
      const sourceApp = apps.find((entry) => entry.id === sourceId);

      pendingImage = pendingImage || sourceApp?.image || '';
      keepExistingImage = false;
      editingId = null;
      cloneSourceId = null;
      dialogTitle.textContent = t('dialog_clone');
      deleteBtn.style.display = 'none';
      cloneBtn.style.display = 'none';
      appName.focus();
      appName.select();
    });

    removeImageBtn.addEventListener('click', () => {
      pendingImage = null;
      keepExistingImage = false;
      appImage.value = '';
      updateModalPreview();
    });

    appName.addEventListener('input', () => {
      updateModalPreview();
    });

    appUrl.addEventListener('input', () => {
      clearTimeout(faviconLookupTimer);
      faviconLookupTimer = setTimeout(() => {
        if (pendingImage || (editingId && keepExistingImage)) {
          return;
        }
        lookupSuggestedLogo(appUrl.value);
      }, 350);
    });

    appImage.addEventListener('change', async () => {
      const file = appImage.files?.[0];
      if (!file) return;
      const raw = await fileToDataUrl(file);
      pendingImage = await trimTransparentPadding(raw);
      keepExistingImage = false;
      updateModalPreview();
    });

    appForm.addEventListener('submit', async (event) => {
      event.preventDefault();

      const name = appName.value.trim();
      const url = appUrl.value.trim();
      const description = appDescription.value.trim();
      const category = appCategory.value.trim();

      if (!name || !url) return;

      let image = '';
      if (pendingImage) {
        image = pendingImage;
      } else if (editingId && keepExistingImage) {
        image = apps.find((entry) => entry.id === editingId)?.image || '';
      } else if (suggestedImage) {
        image = suggestedImage;
      }

      if (editingId) {
        apps = apps.map((entry) => entry.id === editingId
          ? { ...entry, name, url, description, category, image }
          : entry
        );
      } else {
        apps.push({
          id: (crypto.randomUUID ? crypto.randomUUID() : String(Date.now())),
          name,
          url,
          description,
          category,
          image
        });
        currentPage = totalPages() - 1;
      }

      try {
        await saveApps();
        closeDialog();
      } catch (error) {
        console.error(error);
      }
    });

    exportBtn.addEventListener('click', () => {
      const blob = new Blob([JSON.stringify({ settings, apps }, null, 2)], { type: 'application/json' });
      const url = URL.createObjectURL(blob);
      const a = document.createElement('a');
      a.href = url;
      a.download = 'pengulab-apps.json';
      a.click();
      URL.revokeObjectURL(url);
    });

    importFile.addEventListener('change', async () => {
      const file = importFile.files?.[0];
      if (!file) return;
      try {
        const text = await file.text();
        const parsed = JSON.parse(text);
        if (Array.isArray(parsed)) {
          apps = normalizeApps(parsed);
          settings = normalizeSettings(DEFAULT_SETTINGS);
        } else {
          apps = normalizeApps(parsed.apps);
          settings = normalizeSettings(parsed.settings);
        }
        currentPage = 0;
        await saveApps();
      } catch (error) {
        console.error(error);
      } finally {
        importFile.value = '';
      }
    });

    window.addEventListener('keydown', (event) => {
      if (event.key === 'Escape') {
        if (appDialog.open) {
          closeDialog();
        }
        settingsPanel.hidden = true;
      }
    });

    loadApps();
  </script>
</body>
</html>
