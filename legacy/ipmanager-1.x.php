<?php
if (!function_exists('ipm_data_file')) {
    function ipm_data_file(): string {
        global $dataFile;
        return isset($dataFile) ? $dataFile : (__DIR__ . '/../apps.json');
    }

    function ipm_default_data(): array {
        return ['networks' => []];
    }

    function ipm_load_root(): array {
        $file = ipm_data_file();
        $json = @file_get_contents($file);
        $data = is_string($json) ? json_decode($json, true) : null;

        if (!is_array($data) || array_is_list($data)) {
            $data = ['settings' => [], 'apps' => []];
        }

        if (!isset($data['addons']) || !is_array($data['addons'])) {
            $data['addons'] = [];
        }

        return $data;
    }

    function ipm_load_data(): array {
        $root = ipm_load_root();
        $data = $root['addons']['ipmanager'] ?? ipm_default_data();

        if (!is_array($data) || !isset($data['networks']) || !is_array($data['networks'])) {
            return ipm_default_data();
        }

        return $data;
    }

    function ipm_save_data(array $data): bool {
        $root = ipm_load_root();
        $root['addons']['ipmanager'] = $data;

        $json = json_encode($root, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $file = ipm_data_file();
        return $json !== false && @file_put_contents($file, $json) !== false;
    }

    function ipm_uuid(): string {
        return bin2hex(random_bytes(8));
    }

    function ipm_clean_ip(?string $value): string {
        $value = trim((string)$value);
        return filter_var($value, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) ? $value : '';
    }

    function ipm_clean_mac(?string $value): string {
        $value = strtolower(trim((string)$value));
        $value = preg_replace('/[^0-9a-f]/', '', $value) ?? '';
        if (strlen($value) !== 12) {
            return '';
        }
        return implode(':', str_split($value, 2));
    }

    function ipm_clean_dns($value): array {
        if (is_array($value)) {
            $parts = $value;
        } else {
            $parts = preg_split('/[\s,;]+/', (string)$value) ?: [];
        }

        $result = [];
        foreach ($parts as $entry) {
            $ip = ipm_clean_ip((string)$entry);
            if ($ip !== '') {
                $result[] = $ip;
            }
        }
        return array_values(array_unique($result));
    }

    function ipm_clean_dhcp_options($value): array {
        if (is_string($value)) {
            $lines = preg_split('/\r\n|\r|\n/', $value) ?: [];
            $result = [];
            foreach ($lines as $line) {
                $line = trim((string)$line);
                if ($line === '') continue;
                [$code, $data] = array_pad(preg_split('/\s+/', $line, 2) ?: [], 2, '');
                $code = trim((string)$code);
                $data = trim((string)$data);
                if ($code !== '' || $data !== '') {
                    $result[] = ['code' => $code, 'data' => $data];
                }
            }
            return $result;
        }

        $result = [];
        if (is_array($value)) {
            foreach ($value as $entry) {
                if (!is_array($entry)) continue;
                $code = trim((string)($entry['code'] ?? ''));
                $data = trim((string)($entry['data'] ?? ''));
                if ($code !== '' || $data !== '') {
                    $result[] = ['code' => $code, 'data' => $data];
                }
            }
        }
        return $result;
    }

    function ipm_clean_mask($value): int {
        $mask = (int)$value;
        return max(1, min(30, $mask));
    }

    function ipm_current_language(): string {
        global $dataFile;
        if (isset($dataFile) && function_exists('read_data')) {
            $data = read_data($dataFile);
            $language = trim((string)($data['settings']['language'] ?? 'de'));
            return $language !== '' ? $language : 'de';
        }
        return 'de';
    }

    function ipm_load_translations(string $language): array {
        $safe = preg_replace('/[^a-zA-Z0-9_-]/', '', $language) ?: 'de';
        $file = __DIR__ . '/ipmanager_' . $safe . '.json';
        if (!is_file($file)) {
            $file = __DIR__ . '/ipmanager_de.json';
        }
        $json = @file_get_contents($file);
        $data = is_string($json) ? json_decode($json, true) : null;
        return is_array($data) ? $data : [];
    }

    function ipm_t(array $translations, string $key, string $fallback = ''): string {
        return (string)($translations[$key] ?? $fallback);
    }

    function ipm_sanitize_network(array $input): array {
        return [
            'id' => trim((string)($input['id'] ?? '')) ?: ipm_uuid(),
            'name' => trim((string)($input['name'] ?? '')),
            'network' => ipm_clean_ip((string)($input['network'] ?? '')),
            'mask' => ipm_clean_mask($input['mask'] ?? 24),
            'gateway' => ipm_clean_ip((string)($input['gateway'] ?? '')),
            'dns' => ipm_clean_dns($input['dns'] ?? []),
            'dhcp_start' => ipm_clean_ip((string)($input['dhcp_start'] ?? '')),
            'dhcp_end' => ipm_clean_ip((string)($input['dhcp_end'] ?? '')),
            'dhcp_options' => ipm_clean_dhcp_options($input['dhcp_options'] ?? []),
            'vlan' => trim((string)($input['vlan'] ?? '')),
            'description' => trim((string)($input['description'] ?? '')),
            'devices' => isset($input['devices']) && is_array($input['devices']) ? $input['devices'] : [],
        ];
    }

    function ipm_sanitize_device(array $input): array {
        return [
            'id' => trim((string)($input['id'] ?? '')) ?: ipm_uuid(),
            'hostname' => trim((string)($input['hostname'] ?? '')),
            'mac' => ipm_clean_mac((string)($input['mac'] ?? '')),
            'ip' => ipm_clean_ip((string)($input['ip'] ?? '')),
            'description' => trim((string)($input['description'] ?? '')),
            'is_reservation' => !empty($input['is_reservation']),
        ];
    }
}

$ipmTranslations = ipm_load_translations(ipm_current_language());

if (isset($_GET['ipmanager_api'])) {
    $action = (string)($_GET['ipmanager_api'] ?? '');
    $data = ipm_load_data();

    if ($action === 'get') {
        send_json(['ok' => true, 'data' => $data]);
    }

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        send_json(['ok' => false, 'error' => 'Method not allowed'], 405);
    }

    $payload = json_decode(file_get_contents('php://input') ?: '', true);
    if (!is_array($payload)) {
        send_json(['ok' => false, 'error' => 'Invalid payload'], 400);
    }

    if ($action === 'save_network') {
        $network = ipm_sanitize_network($payload);
        if ($network['name'] === '' || $network['network'] === '') {
            send_json(['ok' => false, 'error' => 'Missing required fields'], 400);
        }

        $updated = false;
        foreach ($data['networks'] as &$existing) {
            if (($existing['id'] ?? '') === $network['id']) {
                $network['devices'] = is_array($existing['devices'] ?? null) ? $existing['devices'] : [];
                $existing = $network;
                $updated = true;
                break;
            }
        }
        unset($existing);

        if (!$updated) {
            $data['networks'][] = $network;
        }

        if (!ipm_save_data($data)) {
            send_json(['ok' => false, 'error' => 'Could not save data. Check write permissions for apps.json'], 500);
        }

        send_json(['ok' => true, 'data' => $data]);
    }

    if ($action === 'delete_network') {
        $id = trim((string)($payload['id'] ?? ''));
        $data['networks'] = array_values(array_filter($data['networks'], static fn(array $entry): bool => ($entry['id'] ?? '') !== $id));

        if (!ipm_save_data($data)) {
            send_json(['ok' => false, 'error' => 'Could not save data. Check write permissions for apps.json'], 500);
        }

        send_json(['ok' => true, 'data' => $data]);
    }

    if ($action === 'clone_network') {
        $id = trim((string)($payload['id'] ?? ''));
        foreach ($data['networks'] as $network) {
            if (($network['id'] ?? '') !== $id) {
                continue;
            }

            $copy = $network;
            $copy['id'] = ipm_uuid();
            $copy['name'] = trim(($copy['name'] ?? '')) . ' Copy';
            $copy['devices'] = array_map(static function(array $device): array {
                $device['id'] = ipm_uuid();
                return $device;
            }, is_array($copy['devices'] ?? null) ? $copy['devices'] : []);

            $data['networks'][] = $copy;
            break;
        }

        if (!ipm_save_data($data)) {
            send_json(['ok' => false, 'error' => 'Could not save data. Check write permissions for apps.json'], 500);
        }

        send_json(['ok' => true, 'data' => $data]);
    }

    if ($action === 'save_device') {
        $networkId = trim((string)($payload['network_id'] ?? ''));
        $device = ipm_sanitize_device($payload['device'] ?? []);
        if ($networkId === '' || $device['hostname'] === '' || $device['ip'] === '') {
            send_json(['ok' => false, 'error' => 'Missing required fields'], 400);
        }

        foreach ($data['networks'] as &$network) {
            if (($network['id'] ?? '') !== $networkId) {
                continue;
            }

            $network['devices'] = is_array($network['devices'] ?? null) ? $network['devices'] : [];
            $updated = false;

            foreach ($network['devices'] as &$existingDevice) {
                if (($existingDevice['id'] ?? '') === $device['id']) {
                    $existingDevice = $device;
                    $updated = true;
                    break;
                }
            }
            unset($existingDevice);

            if (!$updated) {
                $network['devices'][] = $device;
            }
            break;
        }
        unset($network);

        if (!ipm_save_data($data)) {
            send_json(['ok' => false, 'error' => 'Could not save data. Check write permissions for apps.json'], 500);
        }

        send_json(['ok' => true, 'data' => $data]);
    }

    if ($action === 'delete_device') {
        $networkId = trim((string)($payload['network_id'] ?? ''));
        $deviceId = trim((string)($payload['device_id'] ?? ''));

        foreach ($data['networks'] as &$network) {
            if (($network['id'] ?? '') !== $networkId) {
                continue;
            }
            $network['devices'] = array_values(array_filter(
                is_array($network['devices'] ?? null) ? $network['devices'] : [],
                static fn(array $entry): bool => ($entry['id'] ?? '') !== $deviceId
            ));
            break;
        }
        unset($network);

        if (!ipm_save_data($data)) {
            send_json(['ok' => false, 'error' => 'Could not save data. Check write permissions for apps.json'], 500);
        }

        send_json(['ok' => true, 'data' => $data]);
    }

    send_json(['ok' => false, 'error' => 'Unknown action'], 400);
}

?>
<style>
  .ipm-root {
    display: grid;
    gap: 20px;
    max-height: calc(100vh - 230px);
    overflow: auto;
    padding-right: 6px;
    scrollbar-width: thin;
  }

  .ipm-root::-webkit-scrollbar {
    width: 10px;
    height: 10px;
  }

  .ipm-root::-webkit-scrollbar-thumb {
    background: rgba(123, 163, 255, 0.35);
    border-radius: 999px;
  }

  .ipm-card {
    border: 1px solid var(--card-border);
    border-radius: 24px;
    background: var(--card);
    box-shadow: var(--shadow);
    padding: 20px;
  }

  .ipm-card h2,
  .ipm-card h3 {
    margin: 0 0 16px;
    color: var(--text);
    letter-spacing: -0.03em;
  }

  .ipm-grid {
    display: grid;
    gap: 14px;
    grid-template-columns: repeat(12, minmax(0, 1fr));
  }

  .ipm-field {
    display: grid;
    gap: 8px;
    align-content: start;
  }

  .ipm-field label {
    font-weight: 700;
    color: var(--text);
    font-size: 0.95rem;
  }

  .ipm-field input,
  .ipm-field select,
  .ipm-field textarea {
    width: 100%;
    border: 1px solid var(--card-border);
    border-radius: 16px;
    padding: 12px 14px;
    font: inherit;
    color: var(--text);
    background: rgba(123, 163, 255, 0.08);
    outline: none;
    box-shadow: inset 0 1px 2px rgba(16, 35, 73, 0.04);
  }

  .ipm-field input::placeholder,
  .ipm-field textarea::placeholder {
    color: var(--muted);
  }

  .ipm-field textarea {
    min-height: 64px;
    resize: vertical;
  }

  .ipm-dhcp-options {
    display: grid;
    gap: 10px;
  }

  .ipm-dns-servers {
    display: grid;
    gap: 10px;
  }

  .ipm-dns-row {
    display: grid;
    grid-template-columns: minmax(0, 1fr) auto;
    gap: 10px;
    align-items: center;
  }

  .ipm-dns-row .ipm-remove-option {
    width: 38px;
    height: 38px;
    border-radius: 12px;
    border: 1px solid var(--card-border);
    background: rgba(255, 109, 109, 0.10);
    color: var(--text);
    cursor: pointer;
    font: inherit;
    font-weight: 700;
  }

  .ipm-dhcp-option-row {
    display: grid;
    grid-template-columns: minmax(140px, 220px) minmax(0, 1fr) auto;
    gap: 10px;
    align-items: center;
  }

  .ipm-dhcp-option-row .ipm-remove-option {
    width: 38px;
    height: 38px;
    border-radius: 12px;
    border: 1px solid var(--card-border);
    background: rgba(255, 109, 109, 0.10);
    color: var(--text);
    cursor: pointer;
    font: inherit;
    font-weight: 700;
  }

  @media (max-width: 780px) {
    .ipm-dhcp-option-row {
      grid-template-columns: 1fr;
    }

    .ipm-dhcp-option-row .ipm-remove-option {
      width: 100%;
    }
  }

  .ipm-col-12 { grid-column: span 12; }
  .ipm-col-6 { grid-column: span 6; }
  .ipm-col-4 { grid-column: span 4; }
  .ipm-col-3 { grid-column: span 3; }

  .ipm-actions {
    display: flex;
    gap: 10px;
    flex-wrap: wrap;
  }

  .ipm-status {
    display: none;
    padding: 12px 14px;
    border-radius: 16px;
    font-weight: 700;
    border: 1px solid var(--card-border);
  }

  .ipm-status.show {
    display: block;
  }

  .ipm-status.success {
    background: rgba(88, 200, 124, 0.12);
  }

  .ipm-status.error {
    background: rgba(255, 109, 109, 0.12);
  }

  .ipm-btn {
    appearance: none;
    border: 1px solid var(--control-border);
    background: var(--control-bg);
    color: var(--text);
    border-radius: 14px;
    padding: 10px 14px;
    font: inherit;
    font-weight: 700;
    cursor: pointer;
  }

  .ipm-btn.primary {
    background: linear-gradient(135deg, #7ba3ff, #6a88ff);
    color: #fff;
    border-color: transparent;
  }

  .ipm-stats {
    display: grid;
    gap: 12px;
    grid-template-columns: repeat(auto-fit, minmax(140px, 1fr));
    margin-bottom: 16px;
  }

  .ipm-stat {
    border: 1px solid var(--card-border);
    border-radius: 18px;
    padding: 12px 14px;
    background: rgba(123, 163, 255, 0.06);
  }

  .ipm-stat strong {
    display: block;
    font-size: 1.25rem;
    color: var(--text);
  }

  .ipm-stat span {
    color: var(--muted);
    font-size: 0.9rem;
  }

  .ipm-network-list {
    display: grid;
    gap: 16px;
  }

  .ipm-network-list,
  .ipm-network-detail {
    max-height: 62vh;
    overflow: auto;
    scrollbar-width: thin;
  }

  .ipm-network-list::-webkit-scrollbar,
  .ipm-network-detail::-webkit-scrollbar {
    width: 10px;
    height: 10px;
  }

  .ipm-network-list::-webkit-scrollbar-thumb,
  .ipm-network-detail::-webkit-scrollbar-thumb {
    background: rgba(123, 163, 255, 0.35);
    border-radius: 999px;
  }

  .ipm-search {
    width: 100%;
    border: 1px solid var(--card-border);
    border-radius: 16px;
    padding: 12px 14px;
    font: inherit;
    color: var(--text);
    background: rgba(123, 163, 255, 0.08);
    outline: none;
    box-shadow: inset 0 1px 2px rgba(16, 35, 73, 0.04);
  }

  .ipm-free-groups {
    display: grid;
    gap: 14px;
  }

  .ipm-free-group {
    display: grid;
    gap: 10px;
    border: 1px solid var(--card-border);
    border-radius: 18px;
    padding: 12px;
    background: rgba(123, 163, 255, 0.04);
  }

  .ipm-free-group h4 {
    margin: 0;
    font-size: 0.95rem;
    color: var(--text);
  }

  .ipm-free-list {
    max-height: 170px;
    overflow: auto;
    scrollbar-width: thin;
  }

  .ipm-free-list::-webkit-scrollbar {
    width: 8px;
    height: 8px;
  }

  .ipm-free-list::-webkit-scrollbar-thumb {
    background: rgba(123, 163, 255, 0.35);
    border-radius: 999px;
  }

  .ipm-split {
    display: grid;
    grid-template-columns: 320px minmax(0, 1fr);
    gap: 18px;
    align-items: start;
  }

  .ipm-network-item {
    border: 1px solid var(--card-border);
    border-radius: 20px;
    background: var(--card);
    padding: 14px;
    display: grid;
    gap: 12px;
    cursor: pointer;
  }

  .ipm-network-item.active {
    border-color: rgba(123, 163, 255, 0.45);
    box-shadow: 0 0 0 3px rgba(123, 163, 255, 0.10);
    background: rgba(123, 163, 255, 0.06);
  }

  .ipm-network-item-top {
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    gap: 10px;
  }

  .ipm-network-item strong {
    color: var(--text);
    font-size: 1rem;
  }

  .ipm-network-item span {
    color: var(--muted);
    font-size: 0.9rem;
  }

  .ipm-network-item-stats {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 8px;
  }

  .ipm-network-item-stat {
    border: 1px solid var(--card-border);
    border-radius: 14px;
    padding: 8px 10px;
    background: rgba(123, 163, 255, 0.06);
  }

  .ipm-network-item-stat strong {
    display: block;
    font-size: 1rem;
  }

  .ipm-network-detail {
    border: 1px solid var(--card-border);
    border-radius: 22px;
    background: var(--card);
    padding: 18px;
    min-height: 100%;
  }

  .ipm-network-detail-header {
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    gap: 12px;
    margin-bottom: 16px;
  }

  .ipm-network-detail-header strong {
    font-size: 1.25rem;
    color: var(--text);
  }

  .ipm-network-section {
    display: grid;
    gap: 14px;
    margin-top: 18px;
  }

  .ipm-network-section h3 {
    margin: 0;
  }

  @media (max-width: 1100px) {
    .ipm-split {
      grid-template-columns: 1fr;
    }
  }

  .ipm-network {
    border: 1px solid var(--card-border);
    border-radius: 22px;
    background: var(--card);
    overflow: hidden;
  }

  .ipm-network-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 12px;
    padding: 16px 18px;
    cursor: pointer;
    background: rgba(123, 163, 255, 0.06);
  }

  .ipm-network-title {
    display: grid;
    gap: 4px;
  }

  .ipm-network-title strong {
    font-size: 1.15rem;
    color: var(--text);
  }

  .ipm-network-title span {
    color: var(--muted);
    font-size: 0.92rem;
  }

  .ipm-network-body {
    padding: 18px;
    display: none;
  }

  .ipm-network.open .ipm-network-body {
    display: grid;
    gap: 18px;
  }

  .ipm-badges {
    display: flex;
    gap: 8px;
    flex-wrap: wrap;
  }

  .ipm-badge {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 6px 10px;
    border-radius: 999px;
    background: rgba(123, 163, 255, 0.12);
    color: var(--text);
    font-weight: 700;
    font-size: 0.85rem;
  }

  .ipm-badge.warn {
    background: rgba(255, 186, 90, 0.18);
  }

  .ipm-badge.error {
    background: rgba(255, 109, 109, 0.18);
  }

  .ipm-devices {
    display: grid;
    gap: 10px;
  }

  .ipm-device {
    display: grid;
    gap: 10px;
    border: 1px solid var(--card-border);
    border-radius: 18px;
    padding: 14px;
  }

  .ipm-device-top {
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    gap: 12px;
  }

  .ipm-device-top strong {
    color: var(--text);
  }

  .ipm-device-meta {
    color: var(--muted);
    font-size: 0.92rem;
    display: grid;
    gap: 4px;
  }

  .ipm-inline-actions {
    display: flex;
    gap: 8px;
    flex-wrap: wrap;
  }

  .ipm-helper {
    color: var(--muted);
    font-size: 0.9rem;
  }

  .ipm-free-list {
    display: flex;
    gap: 8px;
    flex-wrap: wrap;
  }

  .ipm-free-ip {
    padding: 6px 10px;
    border-radius: 999px;
    border: 1px solid var(--card-border);
    color: var(--text);
    font-size: 0.85rem;
  }

  .ipm-free-ip.dhcp {
    background: rgba(123, 163, 255, 0.12);
  }

  .ipm-empty {
    color: var(--muted);
    text-align: center;
    padding: 18px;
    border: 1px dashed var(--card-border);
    border-radius: 18px;
  }

  .ipm-checkbox {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    font-weight: 700;
    color: var(--text);
  }

  @media (max-width: 980px) {
    .ipm-col-6,
    .ipm-col-4,
    .ipm-col-3 {
      grid-column: span 12;
    }
  }

  /* Strong visual override for compact IP overview */
  .ipm-ip-list {
    gap: 8px !important;
  }

  .ipm-ip-row {
    padding: 10px 12px !important;
    border-radius: 14px !important;
    background: rgba(123, 163, 255, 0.03) !important;
    border: 1px solid rgba(123, 163, 255, 0.18) !important;
    box-shadow: none !important;
  }

  .ipm-ip-row + .ipm-ip-row {
    margin-top: 2px;
  }

  .ipm-ip-main {
    gap: 4px !important;
  }

  .ipm-ip-main strong {
    font-size: 0.95rem !important;
    line-height: 1.15 !important;
  }

  .ipm-ip-extra {
    font-size: 0.83rem !important;
    line-height: 1.3 !important;
    color: var(--muted) !important;
  }

  .ipm-badges {
    gap: 6px !important;
  }

  .ipm-badge {
    padding: 4px 8px !important;
    font-size: 0.78rem !important;
    line-height: 1.1 !important;
  }

  .ipm-ip-actions .ipm-btn.compact {
    padding: 6px 9px !important;
    font-size: 0.84rem !important;
    border-radius: 11px !important;
  }

  .ipm-add-ip-btn {
    width: 34px !important;
    height: 34px !important;
    padding: 0 !important;
    font-size: 1rem !important;
    border-radius: 11px !important;
    background: var(--control-bg) !important;
    border: 1px solid var(--card-border) !important;
    color: var(--text) !important;
  }


  /* Strong visual table redesign */
  .ipm-ip-list {
    padding: 0 !important;
    border: none !important;
    background: transparent !important;
    max-height: 440px !important;
  }

  .ipm-ip-table {
    border-collapse: separate !important;
    border-spacing: 0 12px !important;
    width: 100% !important;
  }

  .ipm-ip-table thead th {
    padding: 0 18px 10px !important;
    background: transparent !important;
    color: rgba(29, 58, 119, 0.58) !important;
    font-size: 0.76rem !important;
    letter-spacing: 0.08em !important;
    text-transform: uppercase !important;
  }

  .ipm-ip-table tbody td {
    padding: 14px 18px !important;
    border-top: 1px solid rgba(123, 163, 255, 0.16) !important;
    border-bottom: 1px solid rgba(123, 163, 255, 0.16) !important;
    color: var(--text) !important;
    box-shadow: 0 12px 24px rgba(16, 35, 73, 0.03) !important;
    transition: background 0.18s ease, border-color 0.18s ease, transform 0.18s ease, box-shadow 0.18s ease !important;
  }

  .ipm-ip-table tbody tr.row-even td {
    background: linear-gradient(180deg, rgba(250, 252, 255, 0.98), rgba(245, 249, 255, 0.98)) !important;
  }

  .ipm-ip-table tbody tr.row-odd td {
    background: linear-gradient(180deg, rgba(244, 248, 255, 0.98), rgba(239, 245, 255, 0.98)) !important;
  }

  .ipm-ip-table tbody tr.dhcp-row td {
    background: linear-gradient(180deg, rgba(221, 234, 255, 0.98), rgba(213, 229, 255, 0.98)) !important;
    border-color: rgba(123, 163, 255, 0.26) !important;
  }

  .ipm-ip-table tbody tr:hover td {
    transform: translateY(-1px) !important;
    box-shadow: 0 16px 28px rgba(16, 35, 73, 0.06) !important;
    border-color: rgba(123, 163, 255, 0.26) !important;
  }

  .ipm-ip-table tbody td:first-child {
    border-left: 1px solid rgba(123, 163, 255, 0.16) !important;
    border-top-left-radius: 18px !important;
    border-bottom-left-radius: 18px !important;
    font-weight: 800 !important;
  }

  .ipm-ip-table tbody td:last-child {
    border-right: 1px solid rgba(123, 163, 255, 0.16) !important;
    border-top-right-radius: 18px !important;
    border-bottom-right-radius: 18px !important;
  }

  .ipm-ip-table tbody td.muted {
    color: var(--muted) !important;
  }

  .ipm-ip-table .actions-cell {
    width: 118px !important;
  }

  .ipm-icon-btn {
    width: 36px !important;
    height: 36px !important;
    border-radius: 14px !important;
    border: 1px solid rgba(123, 163, 255, 0.2) !important;
    background: linear-gradient(180deg, rgba(255, 255, 255, 0.96), rgba(242, 247, 255, 0.98)) !important;
    box-shadow: 0 8px 16px rgba(16, 35, 73, 0.05) !important;
  }

  .ipm-icon-btn:hover {
    background: linear-gradient(180deg, rgba(123, 163, 255, 0.18), rgba(123, 163, 255, 0.10)) !important;
    border-color: rgba(123, 163, 255, 0.30) !important;
    box-shadow: 0 12px 20px rgba(16, 35, 73, 0.08) !important;
  }

</style>

<div class="ipm-root">
  <div class="ipm-status" id="ipmStatus"></div>
  <section class="ipm-card">
    <h2 id="ipmNetworkFormTitle"><?php echo htmlspecialchars(ipm_t($ipmTranslations, 'network_form_title', 'Add network')); ?></h2>
    <div class="ipm-grid">
      <div class="ipm-field ipm-col-4">
        <label for="ipmName"><?php echo htmlspecialchars(ipm_t($ipmTranslations, 'network_name', 'Network name')); ?></label>
        <input id="ipmName" type="text" />
      </div>
      <div class="ipm-field ipm-col-3">
        <label for="ipmNetwork"><?php echo htmlspecialchars(ipm_t($ipmTranslations, 'network_address', 'Network')); ?></label>
        <input id="ipmNetwork" type="text" placeholder="10.10.1.0" />
      </div>
      <div class="ipm-field ipm-col-3">
        <label for="ipmMask"><?php echo htmlspecialchars(ipm_t($ipmTranslations, 'subnet_mask', 'Subnet mask')); ?></label>
        <select id="ipmMask"></select>
      </div>
      <div class="ipm-field ipm-col-2">
        <label><?php echo htmlspecialchars(ipm_t($ipmTranslations, 'network_size', 'Hosts')); ?></label>
        <input id="ipmSize" type="text" readonly />
      </div>

      <div class="ipm-field ipm-col-3">
        <label for="ipmGateway"><?php echo htmlspecialchars(ipm_t($ipmTranslations, 'gateway', 'Gateway')); ?></label>
        <input id="ipmGateway" type="text" placeholder="10.10.1.1" />
      </div>
      <div class="ipm-field ipm-col-3">
        <label for="ipmDhcpStart"><?php echo htmlspecialchars(ipm_t($ipmTranslations, 'dhcp_start', 'DHCP start')); ?></label>
        <input id="ipmDhcpStart" type="text" placeholder="10.10.1.100" />
      </div>
      <div class="ipm-field ipm-col-3">
        <label for="ipmDhcpEnd"><?php echo htmlspecialchars(ipm_t($ipmTranslations, 'dhcp_end', 'DHCP end')); ?></label>
        <input id="ipmDhcpEnd" type="text" placeholder="10.10.1.199" />
      </div>
      <div class="ipm-field ipm-col-3">
        <label for="ipmVlan"><?php echo htmlspecialchars(ipm_t($ipmTranslations, 'vlan', 'VLAN')); ?></label>
        <input id="ipmVlan" type="text" />
      </div>

      <div class="ipm-field ipm-col-6">
        <label><?php echo htmlspecialchars(ipm_t($ipmTranslations, 'dns_servers', 'DNS servers')); ?></label>
        <div class="ipm-dns-servers" id="ipmDns"></div>
        <div class="ipm-actions">
          <button class="ipm-btn" id="ipmAddDnsBtn" type="button">+</button>
        </div>
      </div>
      <div class="ipm-field ipm-col-6">
        <label for="ipmDescription"><?php echo htmlspecialchars(ipm_t($ipmTranslations, 'description', 'Description')); ?></label>
        <input id="ipmDescription" type="text" />
      </div>

      <div class="ipm-field ipm-col-12">
        <label><?php echo htmlspecialchars(ipm_t($ipmTranslations, 'dhcp_options', 'DHCP options')); ?></label>
        <div class="ipm-dhcp-options" id="ipmDhcpOptions"></div>
        <div class="ipm-actions">
          <button class="ipm-btn" id="ipmAddDhcpOptionBtn" type="button">+</button>
        </div>
        <div class="ipm-helper"><?php echo htmlspecialchars(ipm_t($ipmTranslations, 'dhcp_options_hint', 'Example: timeserver / pool.ntp.org')); ?></div>
      </div>

      <div class="ipm-col-12 ipm-actions">
        <button class="ipm-btn primary" id="ipmSaveNetworkBtn" type="button"><?php echo htmlspecialchars(ipm_t($ipmTranslations, 'save_network', 'Save network')); ?></button>
        <button class="ipm-btn" id="ipmResetNetworkBtn" type="button"><?php echo htmlspecialchars(ipm_t($ipmTranslations, 'reset', 'Reset')); ?></button>
      </div>
    </div>
  </section>

  <section class="ipm-card">
    <h2><?php echo htmlspecialchars(ipm_t($ipmTranslations, 'network_overview', 'Networks')); ?></h2>
    <div class="ipm-split"><div class="ipm-network-list" id="ipmNetworkList"></div><div class="ipm-network-detail" id="ipmNetworkDetail"></div></div>
  </section>
</div>

<script>
(() => {
  const t = <?php echo json_encode($ipmTranslations, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;
  const state = {
    networks: [],
    editingNetworkId: null,
    selectedNetworkId: null,
    detailSearch: '',
    editingDevice: {},
    prefillDeviceIp: {}
  };

  const apiBase = `${window.location.pathname}?addon=ipmanager&ipmanager_api=`;
  const el = {
    name: document.getElementById('ipmName'),
    network: document.getElementById('ipmNetwork'),
    mask: document.getElementById('ipmMask'),
    size: document.getElementById('ipmSize'),
    gateway: document.getElementById('ipmGateway'),
    dhcpStart: document.getElementById('ipmDhcpStart'),
    dhcpEnd: document.getElementById('ipmDhcpEnd'),
    vlan: document.getElementById('ipmVlan'),
    dns: document.getElementById('ipmDns'),
    addDnsBtn: document.getElementById('ipmAddDnsBtn'),
    description: document.getElementById('ipmDescription'),
    dhcpOptions: document.getElementById('ipmDhcpOptions'),
    addDhcpOptionBtn: document.getElementById('ipmAddDhcpOptionBtn'),
    saveNetworkBtn: document.getElementById('ipmSaveNetworkBtn'),
    resetNetworkBtn: document.getElementById('ipmResetNetworkBtn'),
    networkList: document.getElementById('ipmNetworkList'),
    networkDetail: document.getElementById('ipmNetworkDetail'),
    networkFormTitle: document.getElementById('ipmNetworkFormTitle'),
    status: document.getElementById('ipmStatus')
  };

  function showStatus(message, type = 'success') {
    el.status.textContent = message;
    el.status.className = `ipm-status show ${type}`;
    clearTimeout(showStatus._timer);
    showStatus._timer = setTimeout(() => {
      el.status.className = 'ipm-status';
      el.status.textContent = '';
    }, 3200);
  }

  function tr(key, fallback = '') {
    return t[key] || fallback || key;
  }

  function ipToLong(ip) {
    const parts = String(ip || '').trim().split('.');
    if (parts.length !== 4) return null;
    const nums = parts.map((part) => Number(part));
    if (nums.some((n) => !Number.isInteger(n) || n < 0 || n > 255)) return null;
    return ((nums[0] * 256 + nums[1]) * 256 + nums[2]) * 256 + nums[3];
  }

  function longToIp(long) {
    return [
      (long >>> 24) & 255,
      (long >>> 16) & 255,
      (long >>> 8) & 255,
      long & 255
    ].join('.');
  }

  function hostCapacity(mask) {
    if (mask >= 31) return 0;
    return Math.max(0, Math.pow(2, 32 - Number(mask)) - 2);
  }

  function networkRange(network, mask) {
    const ipLong = ipToLong(network);
    if (ipLong === null) return null;
    const blockSize = Math.pow(2, 32 - Number(mask));
    const start = Math.floor(ipLong / blockSize) * blockSize;
    const end = start + blockSize - 1;
    return {
      network: start,
      first: mask >= 31 ? start : start + 1,
      last: mask >= 31 ? end : end - 1,
      broadcast: end,
      size: hostCapacity(mask)
    };
  }

  function inRange(ip, start, end) {
    const value = ipToLong(ip);
    const s = ipToLong(start);
    const e = ipToLong(end);
    if (value === null || s === null || e === null) return false;
    return value >= s && value <= e;
  }

  function normalizeDns(input) {
    return String(input || '')
      .split(/[\s,;]+/)
      .map((entry) => entry.trim())
      .filter(Boolean);
  }

  function renderDnsServers(servers = ['']) {
    const normalized = Array.isArray(servers) && servers.length ? servers : [''];

    el.dns.innerHTML = normalized.map((entry, index) => `
      <div class="ipm-dns-row">
        <input type="text" data-ipm-dns value="${escapeHtml(entry || '')}" placeholder="1.1.1.1" />
        <button class="ipm-remove-option" type="button" data-ipm-remove-dns="${index}">−</button>
      </div>
    `).join('');

    el.dns.querySelectorAll('[data-ipm-remove-dns]').forEach((button) => {
      button.addEventListener('click', () => {
        const rows = [...el.dns.querySelectorAll('.ipm-dns-row')];
        if (rows.length <= 1) {
          renderDnsServers(['']);
          return;
        }
        rows[Number(button.dataset.ipmRemoveDns)]?.remove();
      });
    });
  }

  function renderDhcpOptions(options = [{ code: '', data: '' }]) {
    const normalized = Array.isArray(options) && options.length
      ? options
      : [{ code: '', data: '' }];

    el.dhcpOptions.innerHTML = normalized.map((entry, index) => `
      <div class="ipm-dhcp-option-row">
        <input type="text" data-ipm-dhcp-code value="${escapeHtml(entry.code || '')}" placeholder="${escapeHtml(tr('dhcp_option_code_placeholder', 'timeserver'))}" />
        <input type="text" data-ipm-dhcp-data value="${escapeHtml(entry.data || '')}" placeholder="${escapeHtml(tr('dhcp_option_data_placeholder', 'pool.ntp.org'))}" />
        <button class="ipm-remove-option" type="button" data-ipm-remove-dhcp="${index}">−</button>
      </div>
    `).join('');

    el.dhcpOptions.querySelectorAll('[data-ipm-remove-dhcp]').forEach((button) => {
      button.addEventListener('click', () => {
        const rows = [...el.dhcpOptions.querySelectorAll('.ipm-dhcp-option-row')];
        if (rows.length <= 1) {
          renderDhcpOptions([{ code: '', data: '' }]);
          return;
        }
        rows[Number(button.dataset.ipmRemoveDhcp)]?.remove();
      });
    });
  }

  function maskOptions() {
    const result = [];
    for (let i = 30; i >= 1; i--) {
      result.push(`<option value="${i}">/${i}</option>`);
    }
    return result.join('');
  }

  function refreshSize() {
    el.size.value = hostCapacity(Number(el.mask.value || 24));
  }

  async function apiGet() {
    const response = await fetch(apiBase + 'get', { cache: 'no-store' });
    const data = await response.json();
    if (!response.ok || !data.ok) throw new Error(data.error || 'API error');
    return data.data;
  }

  async function apiPost(action, payload) {
    const response = await fetch(apiBase + action, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(payload)
    });
    const data = await response.json();
    if (!response.ok || !data.ok) throw new Error(data.error || 'API error');
    return data.data;
  }

  function resetNetworkForm() {
    state.editingNetworkId = null;
    el.networkFormTitle.textContent = tr('network_form_title', 'Add network');
    el.name.value = '';
    el.network.value = '';
    el.mask.value = '24';
    el.gateway.value = '';
    el.dhcpStart.value = '';
    el.dhcpEnd.value = '';
    el.vlan.value = '';
    renderDnsServers(['']);
    el.description.value = '';
    renderDhcpOptions([{ code: '', data: '' }]);
    refreshSize();
  }

  function fillNetworkForm(network) {
    state.editingNetworkId = network.id;
    el.networkFormTitle.textContent = tr('edit_network', 'Edit network');
    el.name.value = network.name || '';
    el.network.value = network.network || '';
    el.mask.value = String(network.mask || 24);
    el.gateway.value = network.gateway || '';
    el.dhcpStart.value = network.dhcp_start || '';
    el.dhcpEnd.value = network.dhcp_end || '';
    el.vlan.value = network.vlan || '';
    renderDnsServers(Array.isArray(network.dns) ? network.dns : ['']);
    el.description.value = network.description || '';
    renderDhcpOptions(Array.isArray(network.dhcp_options) ? network.dhcp_options : []);
    refreshSize();
    window.scrollTo({ top: 0, behavior: 'smooth' });
  }

  function getDeviceWarnings(network, device, allDevices) {
    const warnings = [];
    if (!device.is_reservation && network.dhcp_start && network.dhcp_end && inRange(device.ip, network.dhcp_start, network.dhcp_end)) {
      warnings.push({ type: 'warn', text: tr('warning_static_in_dhcp', 'Static host inside DHCP range') });
    }

    const duplicates = allDevices.filter((entry) => entry.id !== device.id && entry.ip && entry.ip === device.ip);
    if (duplicates.length) {
      warnings.push({ type: 'error', text: tr('warning_duplicate_ip', 'Duplicate IP') });
    }

    const duplicateMacs = allDevices.filter((entry) => entry.id !== device.id && entry.mac && entry.mac === device.mac);
    if (duplicateMacs.length) {
      warnings.push({ type: 'error', text: tr('warning_duplicate_mac', 'Duplicate MAC') });
    }

    return warnings;
  }

  function getAllUsableIps(network) {
    const range = networkRange(network.network, network.mask);
    if (!range || range.size <= 0) return [];
    const ips = [];
    for (let current = range.first; current <= range.last; current++) {
      ips.push(longToIp(current));
    }
    return ips;
  }

  function getFreeIps(network) {
    const range = networkRange(network.network, network.mask);
    if (!range || range.size <= 0) return [];

    const used = new Set(
      (network.devices || [])
        .map((entry) => entry.ip)
        .filter(Boolean)
        .map((ip) => ipToLong(ip))
        .filter((value) => value !== null)
    );

    if (network.gateway) {
      const gatewayLong = ipToLong(network.gateway);
      if (gatewayLong !== null) used.add(gatewayLong);
    }

    const free = [];
    for (let current = range.first; current <= range.last; current++) {
      if (!used.has(current)) {
        free.push(longToIp(current));
      }
      if (free.length >= 24) break;
    }
    return free;
  }

  function networkWarnings(network) {
    const warnings = [];
    const range = networkRange(network.network, network.mask);
    if (!range) {
      warnings.push({ type: 'error', text: tr('warning_invalid_network', 'Invalid network definition') });
      return warnings;
    }

    if (network.gateway && !inRange(network.gateway, longToIp(range.first), longToIp(range.last))) {
      warnings.push({ type: 'warn', text: tr('warning_gateway_outside', 'Gateway outside usable range') });
    }

    if (network.dhcp_start && network.dhcp_end) {
      const startLong = ipToLong(network.dhcp_start);
      const endLong = ipToLong(network.dhcp_end);
      if (startLong === null || endLong === null || startLong > endLong) {
        warnings.push({ type: 'error', text: tr('warning_invalid_dhcp', 'Invalid DHCP range') });
      }
    }

    return warnings;
  }

  function statBox(value, label) {
    return `<div class="ipm-stat"><strong>${value}</strong><span>${label}</span></div>`;
  }

  function renderDeviceForm(networkId, device = null) {
    const prefillIp = !device && state.prefillDeviceIp[networkId] ? state.prefillDeviceIp[networkId] : '';
    return `
      <div class="ipm-card" style="padding:16px;">
        <h3>${device ? tr('edit_device', 'Edit device') : tr('add_device', 'Add device')}</h3>
        <div class="ipm-grid">
          <div class="ipm-field ipm-col-3">
            <label>${tr('hostname', 'Hostname')}</label>
            <input type="text" data-ipm-device-field="hostname" data-network-id="${networkId}" data-device-id="${device?.id || ''}" value="${escapeHtml(device?.hostname || '')}" />
          </div>
          <div class="ipm-field ipm-col-3">
            <label>${tr('ip_address', 'IP address')}</label>
            <input type="text" data-ipm-device-field="ip" data-network-id="${networkId}" data-device-id="${device?.id || ''}" value="${escapeHtml(device?.ip || prefillIp || '')}" />
          </div>
          <div class="ipm-field ipm-col-3">
            <label>${tr('mac_address', 'MAC address')}</label>
            <input type="text" data-ipm-device-field="mac" data-network-id="${networkId}" data-device-id="${device?.id || ''}" value="${escapeHtml(device?.mac || '')}" />
          </div>
          <div class="ipm-field ipm-col-3">
            <label>${tr('description', 'Description')}</label>
            <input type="text" data-ipm-device-field="description" data-network-id="${networkId}" data-device-id="${device?.id || ''}" value="${escapeHtml(device?.description || '')}" />
          </div>
          <div class="ipm-col-12">
            <label class="ipm-checkbox">
              <input type="checkbox" data-ipm-device-field="is_reservation" data-network-id="${networkId}" data-device-id="${device?.id || ''}" ${device?.is_reservation ? 'checked' : ''} />
              <span>${tr('dhcp_reservation', 'DHCP reservation')}</span>
            </label>
          </div>
          <div class="ipm-col-12 ipm-actions">
            <button class="ipm-btn primary" type="button" data-ipm-save-device data-network-id="${networkId}" data-device-id="${device?.id || ''}">${tr('save_device', 'Save device')}</button>
            <button class="ipm-btn" type="button" data-ipm-cancel-device="${networkId}">${tr('cancel', 'Cancel')}</button>
          </div>
        </div>
      </div>
    `;
  }

  function renderNetworks() {
    if (!state.networks.length) {
      el.networkList.innerHTML = `<div class="ipm-empty">${tr('no_networks', 'No networks configured yet.')}</div>`;
      el.networkDetail.innerHTML = `<div class="ipm-empty">${tr('select_network_hint', 'Select or create a network to see details.')}</div>`;
      return;
    }

    if (!state.selectedNetworkId || !state.networks.some((entry) => entry.id === state.selectedNetworkId)) {
      state.selectedNetworkId = state.networks[0].id;
    }

    const selected = state.networks.find((entry) => entry.id === state.selectedNetworkId) || state.networks[0];

    el.networkList.innerHTML = state.networks.map((network) => {
      const range = networkRange(network.network, network.mask);
      const devices = Array.isArray(network.devices) ? network.devices : [];
      const reservations = devices.filter((entry) => entry.is_reservation).length;

      return `
        <article class="ipm-network-item ${network.id === state.selectedNetworkId ? 'active' : ''}" data-ipm-select-network="${network.id}">
          <div class="ipm-network-item-top">
            <div>
              <strong>${escapeHtml(network.name || network.network)}</strong>
              <span>${escapeHtml(network.network)}/${network.mask}</span>
            </div>
          </div>
          <div class="ipm-network-item-stats">
            <div class="ipm-network-item-stat">
              <strong>${range ? range.size : 0}</strong>
              <span>${tr('usable_hosts', 'usable hosts')}</span>
            </div>
            <div class="ipm-network-item-stat">
              <strong>${devices.length}</strong>
              <span>${tr('devices', 'Devices')}</span>
            </div>
            <div class="ipm-network-item-stat">
              <strong>${reservations}</strong>
              <span>${tr('reservations', 'Reservations')}</span>
            </div>
            <div class="ipm-network-item-stat">
              <strong>${network.vlan || '-'}</strong>
              <span>VLAN</span>
            </div>
          </div>
        </article>
      `;
    }).join('');

    const range = networkRange(selected.network, selected.mask);
    const warnings = networkWarnings(selected);
    const devices = Array.isArray(selected.devices) ? selected.devices : [];
    const freeIps = getFreeIps(selected);
    const dhcpFreeIps = freeIps.filter((ip) => selected.dhcp_start && selected.dhcp_end && inRange(ip, selected.dhcp_start, selected.dhcp_end));
    const outsideFreeIps = freeIps.filter((ip) => !(selected.dhcp_start && selected.dhcp_end && inRange(ip, selected.dhcp_start, selected.dhcp_end)));
    const dhcpPool = selected.dhcp_start && selected.dhcp_end
      ? Math.max(0, (ipToLong(selected.dhcp_end) ?? 0) - (ipToLong(selected.dhcp_start) ?? 0) + 1)
      : 0;
    const reservations = devices.filter((entry) => entry.is_reservation).length;
    const searchNeedle = state.detailSearch.trim().toLowerCase();
    const filteredDevices = searchNeedle
      ? devices.filter((device) => [device.hostname, device.ip, device.mac, device.description].join(' ').toLowerCase().includes(searchNeedle))
      : devices;
    const filteredDhcpFreeIps = searchNeedle ? dhcpFreeIps.filter((ip) => ip.toLowerCase().includes(searchNeedle)) : dhcpFreeIps;
    const filteredOutsideFreeIps = searchNeedle ? outsideFreeIps.filter((ip) => ip.toLowerCase().includes(searchNeedle)) : outsideFreeIps;

    el.networkDetail.innerHTML = `
      <div class="ipm-network-detail-header">
        <div class="ipm-network-title">
          <strong>${escapeHtml(selected.name || selected.network)}</strong>
          <span>${escapeHtml(selected.network)}/${selected.mask} · ${range ? range.size : 0} ${tr('usable_hosts', 'usable hosts')}</span>
        </div>
        <div class="ipm-inline-actions">
          <button class="ipm-btn" type="button" data-ipm-edit-network="${selected.id}">${tr('edit', 'Edit')}</button>
          <button class="ipm-btn" type="button" data-ipm-clone-network="${selected.id}">${tr('clone', 'Clone')}</button>
          <button class="ipm-btn" type="button" data-ipm-delete-network="${selected.id}">${tr('delete', 'Delete')}</button>
        </div>
      </div>

      <div class="ipm-stats">
        ${statBox(range ? range.size : 0, tr('usable_hosts', 'Usable hosts'))}
        ${statBox(devices.length, tr('devices', 'Devices'))}
        ${statBox(reservations, tr('reservations', 'Reservations'))}
        ${statBox(dhcpPool, tr('dhcp_pool', 'DHCP pool'))}
      </div>

      <div class="ipm-badges">
        ${selected.gateway ? `<span class="ipm-badge">${tr('gateway', 'Gateway')}: ${escapeHtml(selected.gateway)}</span>` : ''}
        ${selected.vlan ? `<span class="ipm-badge">VLAN ${escapeHtml(selected.vlan)}</span>` : ''}
        ${selected.dns && selected.dns.length ? `<span class="ipm-badge">${tr('dns_servers', 'DNS')}: ${escapeHtml(selected.dns.join(', '))}</span>` : ''}
        ${warnings.map((warning) => `<span class="ipm-badge ${warning.type}">${escapeHtml(warning.text)}</span>`).join('')}
      </div>

      ${selected.description ? `<div class="ipm-helper">${escapeHtml(selected.description)}</div>` : ''}
      ${Array.isArray(selected.dhcp_options) && selected.dhcp_options.length ? `<div class="ipm-helper"><strong>${tr('dhcp_options', 'DHCP options')}:</strong><br>${selected.dhcp_options.map((entry) => `${escapeHtml(entry.code || '')}: ${escapeHtml(entry.data || '')}`).join('<br>')}</div>` : ''}

      <section class="ipm-network-section">
        <h3>${(state.editingDevice[selected.id] || 'new').startsWith('new') ? tr('add_device', 'Add device') : tr('edit_device', 'Edit device')}</h3>
        ${renderDeviceForm(selected.id, devices.find((entry) => entry.id === state.editingDevice[selected.id]) || null)}
      </section>

      <section class="ipm-network-section">
        <div class="ipm-inline-actions">
          <h3 style="margin-right:auto;">${tr('ip_overview', 'IP overview')}</h3>
        </div>
        <div class="ipm-ip-list">
          <table class="ipm-ip-table">
            <thead>
              <tr>
                <th>${tr('ip_address', 'IP')}</th>
                <th>${tr('hostname', 'Hostname')}</th>
                <th>${tr('description', 'Description')}</th>
                <th class="actions-cell">${tr('actions', 'Actions')}</th>
              </tr>
            </thead>
            <tbody>
              ${getAllUsableIps(selected).map((ip, index) => {
                const device = devices.find((entry) => entry.ip === ip);
                const rowClass = index % 2 === 0 ? 'row-even' : 'row-odd';
                const isDhcp = selected.dhcp_start && selected.dhcp_end && inRange(ip, selected.dhcp_start, selected.dhcp_end);
                const descriptionParts = [];
                if (device?.description) descriptionParts.push(device.description);
                if (isDhcp) descriptionParts.push(tr('dhcp_range_label', 'DHCP Bereich'));
                const descriptionText = descriptionParts.length ? descriptionParts.join(' · ') : '—';

                return `
                  <tr class="${rowClass} ${isDhcp ? 'dhcp-row' : ''}" data-ip-row="${ip}">
                    <td><strong>${ip}</strong></td>
                    <td class="${device ? '' : 'muted'}">${device ? escapeHtml(device.hostname || tr('unnamed_device', 'Unnamed device')) : '—'}</td>
                    <td class="${descriptionText === '—' ? 'muted' : ''}">${escapeHtml(descriptionText)}</td>
                    <td class="actions-cell">
                      <div class="ipm-ip-actions">
                        ${device
                          ? `
                            <button class="ipm-icon-btn" type="button" title="${tr('edit', 'Edit')}" aria-label="${tr('edit', 'Edit')}" data-ipm-edit-device="${selected.id}:${device.id}">🔧</button>
                            <button class="ipm-icon-btn" type="button" title="${tr('delete', 'Delete')}" aria-label="${tr('delete', 'Delete')}" data-ipm-delete-device="${selected.id}:${device.id}">✕</button>
                          `
                          : `<button class="ipm-icon-btn" type="button" title="${tr('add_device', 'Add device')}" aria-label="${tr('add_device', 'Add device')}" data-ipm-assign-ip="${selected.id}:${ip}">+</button>`}
                      </div>
                    </td>
                  </tr>
                `;
              }).join('')}
            </tbody>
          </table>
        </div>
      </section>
    `;

    bindNetworkEvents();
  }

  function bindNetworkEvents() {
    const detailSearch = document.getElementById('ipmDetailSearch');
    if (detailSearch) {
      detailSearch.addEventListener('input', () => {
        state.detailSearch = detailSearch.value || '';
        renderNetworks();
      });
    }

    document.querySelectorAll('[data-ipm-select-network]').forEach((element) => {
      element.addEventListener('click', () => {
        state.selectedNetworkId = element.dataset.ipmSelectNetwork;
        state.detailSearch = '';
        renderNetworks();
      });
    });

    document.querySelectorAll('[data-ipm-edit-network]').forEach((button) => {
      button.addEventListener('click', () => {
        const network = state.networks.find((entry) => entry.id === button.dataset.ipmEditNetwork);
        if (network) fillNetworkForm(network);
      });
    });

    document.querySelectorAll('[data-ipm-clone-network]').forEach((button) => {
      button.addEventListener('click', async () => {
        try {
          const data = await apiPost('clone_network', { id: button.dataset.ipmCloneNetwork });
          state.networks = data.networks || [];
          state.selectedNetworkId = state.networks[state.networks.length - 1]?.id || state.selectedNetworkId;
          state.detailSearch = '';
          renderNetworks();
          showStatus(tr('network_cloned', 'Network cloned'), 'success');
        } catch (error) {
          showStatus(error.message || 'Error', 'error');
        }
      });
    });

    document.querySelectorAll('[data-ipm-delete-network]').forEach((button) => {
      button.addEventListener('click', async () => {
        const ok = confirm(tr('confirm_delete_network', 'Delete this network?'));
        if (!ok) return;
        try {
          const deletingId = button.dataset.ipmDeleteNetwork;
          const data = await apiPost('delete_network', { id: deletingId });
          state.networks = data.networks || [];
          if (state.selectedNetworkId === deletingId) {
            state.selectedNetworkId = state.networks[0]?.id || null;
            state.detailSearch = '';
          }
          renderNetworks();
          resetNetworkForm();
          showStatus(tr('network_deleted', 'Network deleted'), 'success');
        } catch (error) {
          showStatus(error.message || 'Error', 'error');
        }
      });
    });

    document.querySelectorAll('[data-ipm-assign-ip]').forEach((button) => {
      button.addEventListener('click', () => {
        const [networkId, ip] = button.dataset.ipmAssignIp.split(':');
        state.selectedNetworkId = networkId;
        state.detailSearch = '';
        state.prefillDeviceIp[networkId] = ip;
        state.editingDevice[networkId] = `new:${ip}`;
        renderNetworks();
        requestAnimationFrame(() => {
          document.querySelector('[data-ipm-save-device]')?.scrollIntoView({ block: 'nearest', behavior: 'smooth' });
          document.querySelector('[data-ipm-device-field="hostname"]')?.focus();
        });
      });
    });

    document.querySelectorAll('[data-ipm-edit-device]').forEach((button) => {
      button.addEventListener('click', () => {
        const [networkId, deviceId] = button.dataset.ipmEditDevice.split(':');
        state.selectedNetworkId = networkId;
        state.detailSearch = '';
        state.prefillDeviceIp[networkId] = '';
        state.editingDevice[networkId] = deviceId;
        renderNetworks();
        requestAnimationFrame(() => {
          document.querySelector('[data-ipm-save-device]')?.scrollIntoView({ block: 'nearest', behavior: 'smooth' });
          document.querySelector('[data-ipm-device-field="hostname"]')?.focus();
        });
      });
    });

    document.querySelectorAll('[data-ipm-delete-device]').forEach((button) => {
      button.addEventListener('click', async () => {
        const [networkId, deviceId] = button.dataset.ipmDeleteDevice.split(':');
        const ok = confirm(tr('confirm_delete_device', 'Delete this device?'));
        if (!ok) return;
        try {
          const data = await apiPost('delete_device', { network_id: networkId, device_id: deviceId });
          state.networks = data.networks || [];
          state.selectedNetworkId = networkId;
          state.detailSearch = '';
          renderNetworks();
          showStatus(tr('device_deleted', 'Device deleted'), 'success');
        } catch (error) {
          showStatus(error.message || 'Error', 'error');
        }
      });
    });

    document.querySelectorAll('[data-ipm-cancel-device]').forEach((button) => {
      button.addEventListener('click', () => {
        const networkId = button.dataset.ipmCancelDevice;
        state.prefillDeviceIp[networkId] = '';
        state.editingDevice[networkId] = 'new';
        renderNetworks();
        requestAnimationFrame(() => {
          document.querySelector('[data-ipm-device-field="hostname"]')?.focus();
        });
      });
    });

    document.querySelectorAll('[data-ipm-save-device]').forEach((button) => {
      button.addEventListener('click', async () => {
        const networkId = button.dataset.networkId;
        const deviceId = button.dataset.deviceId || '';
        const scope = `[data-network-id="${networkId}"][data-device-id="${deviceId}"], [data-network-id="${networkId}"][data-device-id=""]`;

        const fields = document.querySelectorAll(scope);
        const device = { id: deviceId };

        fields.forEach((field) => {
          const name = field.dataset.ipmDeviceField;
          if (!name) return;
          if (field.type === 'checkbox') {
            device[name] = field.checked;
          } else {
            device[name] = field.value;
          }
        });

        try {
          const data = await apiPost('save_device', { network_id: networkId, device });
          state.networks = data.networks || [];
          state.selectedNetworkId = networkId;
          state.detailSearch = '';
          state.prefillDeviceIp[networkId] = '';
          state.editingDevice[networkId] = 'new';
          renderNetworks();
          requestAnimationFrame(() => {
            document.querySelector('[data-ipm-device-field="hostname"]')?.focus();
          });
          showStatus(tr('device_saved', 'Device saved'), 'success');
        } catch (error) {
          showStatus(error.message || 'Error', 'error');
        }
      });
    });
  }

  function collectNetworkForm() {
    return {
      id: state.editingNetworkId || '',
      name: el.name.value.trim(),
      network: el.network.value.trim(),
      mask: Number(el.mask.value || 24),
      gateway: el.gateway.value.trim(),
      dhcp_start: el.dhcpStart.value.trim(),
      dhcp_end: el.dhcpEnd.value.trim(),
      vlan: el.vlan.value.trim(),
      dns: [...el.dns.querySelectorAll('[data-ipm-dns]')].map((input) => input.value.trim()).filter(Boolean),
      description: el.description.value.trim(),
      dhcp_options: [...el.dhcpOptions.querySelectorAll('.ipm-dhcp-option-row')].map((row) => ({
        code: row.querySelector('[data-ipm-dhcp-code]')?.value.trim() || '',
        data: row.querySelector('[data-ipm-dhcp-data]')?.value.trim() || ''
      })).filter((entry) => entry.code || entry.data)
    };
  }

  el.mask.innerHTML = maskOptions();
  el.mask.value = '24';
  renderDnsServers(['']);
  renderDhcpOptions([{ code: '', data: '' }]);
  refreshSize();

  [el.mask, el.network].forEach((field) => {
    field.addEventListener('input', refreshSize);
    field.addEventListener('change', refreshSize);
  });

  el.addDnsBtn.addEventListener('click', () => {
    const current = [...el.dns.querySelectorAll('[data-ipm-dns]')].map((input) => input.value || '');
    current.push('');
    renderDnsServers(current);
  });

  el.addDhcpOptionBtn.addEventListener('click', () => {
    const current = [...el.dhcpOptions.querySelectorAll('.ipm-dhcp-option-row')].map((row) => ({
      code: row.querySelector('[data-ipm-dhcp-code]')?.value || '',
      data: row.querySelector('[data-ipm-dhcp-data]')?.value || ''
    }));
    current.push({ code: '', data: '' });
    renderDhcpOptions(current);
  });

  el.resetNetworkBtn.addEventListener('click', resetNetworkForm);
  el.saveNetworkBtn.addEventListener('click', async () => {
    try {
      const previousId = state.editingNetworkId;
      const data = await apiPost('save_network', collectNetworkForm());
      state.networks = data.networks || [];
      if (previousId) {
        state.selectedNetworkId = previousId;
      } else {
        state.selectedNetworkId = state.networks[state.networks.length - 1]?.id || state.selectedNetworkId;
      }
      state.detailSearch = '';
      resetNetworkForm();
      renderNetworks();
      showStatus(tr('network_saved', 'Network saved'), 'success');
    } catch (error) {
      showStatus(error.message || 'Error', 'error');
    }
  });

  async function init() {
    const data = await apiGet();
    state.networks = data.networks || [];
    state.selectedNetworkId = state.networks[0]?.id || null;
    state.detailSearch = '';
    state.prefillDeviceIp = {};
    if (state.selectedNetworkId) {
      state.editingDevice[state.selectedNetworkId] = 'new';
    }
    renderNetworks();
  }

  function escapeHtml(value) {
    return String(value ?? '')
      .replaceAll('&', '&amp;')
      .replaceAll('<', '&lt;')
      .replaceAll('>', '&gt;')
      .replaceAll('"', '&quot;')
      .replaceAll("'", '&#39;');
  }

  init().catch((error) => {
    console.error(error);
    el.networkList.innerHTML = `<div class="ipm-empty">${escapeHtml(error.message || 'Error')}</div>`;
  });
})();
</script>
