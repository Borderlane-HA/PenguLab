<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

$storageDir = __DIR__ . '/data';
$storageFile = $storageDir . '/apps.json';

if (!is_dir($storageDir) && !mkdir($storageDir, 0775, true) && !is_dir($storageDir)) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Ordner data/ konnte nicht erstellt werden.'], JSON_UNESCAPED_UNICODE);
    exit;
}

if (!file_exists($storageFile)) {
    file_put_contents($storageFile, "[]\n");
}

function respond(array $payload, int $status = 200): void
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    exit;
}

function readJsonBody(): array
{
    $raw = file_get_contents('php://input') ?: '';
    if ($raw === '') {
        return [];
    }

    $decoded = json_decode($raw, true);
    return is_array($decoded) ? $decoded : [];
}

function readItems(string $file): array
{
    $fp = fopen($file, 'c+');
    if (!$fp) {
        respond(['success' => false, 'message' => 'JSON-Datei konnte nicht gelesen werden.'], 500);
    }

    flock($fp, LOCK_SH);
    rewind($fp);
    $content = stream_get_contents($fp) ?: '[]';
    flock($fp, LOCK_UN);
    fclose($fp);

    $decoded = json_decode($content, true);
    return is_array($decoded) ? $decoded : [];
}

function writeItems(string $file, array $items): void
{
    $json = json_encode(array_values($items), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    if ($json === false) {
        respond(['success' => false, 'message' => 'Einträge konnten nicht serialisiert werden.'], 500);
    }

    $fp = fopen($file, 'c+');
    if (!$fp) {
        respond(['success' => false, 'message' => 'JSON-Datei konnte nicht geschrieben werden.'], 500);
    }

    flock($fp, LOCK_EX);
    ftruncate($fp, 0);
    rewind($fp);
    fwrite($fp, $json . PHP_EOL);
    fflush($fp);
    flock($fp, LOCK_UN);
    fclose($fp);
}

function cleanText(string $value, int $maxLen): string
{
    $value = trim(strip_tags($value));
    if (function_exists('mb_substr')) {
        return mb_substr($value, 0, $maxLen);
    }
    return substr($value, 0, $maxLen);
}

function ensureUrl(string $value): string
{
    $value = trim($value);
    if ($value === '') {
        return '';
    }

    if (!preg_match('~^https?://~i', $value)) {
        $value = 'https://' . $value;
    }

    return filter_var($value, FILTER_VALIDATE_URL) ? $value : '';
}

function cleanImage(?string $image): string
{
    if (!is_string($image)) {
        return '';
    }

    $image = trim($image);
    if ($image === '') {
        return '';
    }

    if (!preg_match('~^data:image/(png|jpe?g|webp|gif);base64,~i', $image)) {
        return '';
    }

    if (strlen($image) > 5_000_000) {
        return '';
    }

    return $image;
}

function generateId(): string
{
    return 'app-' . bin2hex(random_bytes(6));
}

function normalizeItem(array $item): array
{
    $id = isset($item['id']) && is_string($item['id']) && trim($item['id']) !== ''
        ? cleanText($item['id'], 80)
        : generateId();

    return [
        'id' => $id,
        'title' => cleanText((string)($item['title'] ?? ''), 80),
        'description' => cleanText((string)($item['description'] ?? ''), 180),
        'url' => ensureUrl((string)($item['url'] ?? '')),
        'image' => cleanImage($item['image'] ?? ''),
        'updatedAt' => cleanText((string)($item['updatedAt'] ?? date(DATE_ATOM)), 40),
    ];
}

function validateItem(array $item): array
{
    $item = normalizeItem($item);
    if ($item['title'] === '') {
        respond(['success' => false, 'message' => 'Titel fehlt.'], 422);
    }
    if ($item['url'] === '') {
        respond(['success' => false, 'message' => 'Ungültige URL.'], 422);
    }
    return $item;
}

$action = $_GET['action'] ?? 'list';
$items = readItems($storageFile);

switch ($action) {
    case 'list':
        respond(['success' => true, 'items' => array_values($items)]);
        break;

    case 'save':
        $body = readJsonBody();
        $item = validateItem((array)($body['item'] ?? []));
        $updated = false;

        foreach ($items as $index => $existing) {
            if (($existing['id'] ?? '') === $item['id']) {
                $items[$index] = $item;
                $updated = true;
                break;
            }
        }

        if (!$updated) {
            $items[] = $item;
        }

        writeItems($storageFile, $items);
        respond(['success' => true, 'item' => $item, 'items' => array_values($items)]);
        break;

    case 'delete':
        $body = readJsonBody();
        $id = cleanText((string)($body['id'] ?? ''), 80);
        if ($id === '') {
            respond(['success' => false, 'message' => 'ID fehlt.'], 422);
        }

        $items = array_values(array_filter($items, static fn(array $entry): bool => ($entry['id'] ?? '') !== $id));
        writeItems($storageFile, $items);
        respond(['success' => true, 'items' => $items]);
        break;

    case 'replace':
        $body = readJsonBody();
        $rawItems = isset($body['items']) && is_array($body['items']) ? $body['items'] : [];
        $normalized = [];
        $seen = [];

        foreach ($rawItems as $rawItem) {
            if (!is_array($rawItem)) {
                continue;
            }
            $item = validateItem($rawItem);
            if (isset($seen[$item['id']])) {
                continue;
            }
            $seen[$item['id']] = true;
            $normalized[] = $item;
        }

        writeItems($storageFile, $normalized);
        respond(['success' => true, 'items' => $normalized]);
        break;

    default:
        respond(['success' => false, 'message' => 'Unbekannte Aktion.'], 400);
}
