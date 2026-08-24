<?php
declare(strict_types=1);

use PenguLab\Database;

/** @var Database $db */
$pdo = $db->pdo();
$pdo->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS ipm_networks (
    id TEXT PRIMARY KEY,
    name TEXT NOT NULL,
    cidr TEXT NOT NULL,
    vlan TEXT NOT NULL DEFAULT '',
    gateway TEXT NOT NULL DEFAULT '',
    dhcp_start TEXT NOT NULL DEFAULT '',
    dhcp_end TEXT NOT NULL DEFAULT '',
    dns_json TEXT NOT NULL DEFAULT '[]',
    description TEXT NOT NULL DEFAULT '',
    source TEXT NOT NULL DEFAULT 'manual',
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL
);
CREATE UNIQUE INDEX IF NOT EXISTS idx_ipm_network_cidr ON ipm_networks(cidr);
CREATE TABLE IF NOT EXISTS ipm_devices (
    id TEXT PRIMARY KEY,
    network_id TEXT NOT NULL,
    hostname TEXT NOT NULL DEFAULT '',
    ip TEXT NOT NULL,
    mac TEXT NOT NULL DEFAULT '',
    type TEXT NOT NULL DEFAULT 'static',
    description TEXT NOT NULL DEFAULT '',
    source TEXT NOT NULL DEFAULT 'manual',
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL,
    FOREIGN KEY(network_id) REFERENCES ipm_networks(id) ON DELETE CASCADE
);
CREATE UNIQUE INDEX IF NOT EXISTS idx_ipm_device_ip ON ipm_devices(network_id, ip);
CREATE INDEX IF NOT EXISTS idx_ipm_device_host ON ipm_devices(hostname);
SQL);

// Schema upgrades are additive so existing PenguLab 2.0 IP Manager data remains intact.
$columns = [];
foreach ($pdo->query("PRAGMA table_info(ipm_devices)")->fetchAll() as $column) $columns[(string)$column['name']] = true;
if (!isset($columns['gateway'])) $pdo->exec("ALTER TABLE ipm_devices ADD COLUMN gateway TEXT NOT NULL DEFAULT ''");
if (!isset($columns['dns_json'])) $pdo->exec("ALTER TABLE ipm_devices ADD COLUMN dns_json TEXT NOT NULL DEFAULT '[]'");
if (!isset($columns['dhcp_reservation'])) $pdo->exec("ALTER TABLE ipm_devices ADD COLUMN dhcp_reservation INTEGER NOT NULL DEFAULT 0");
$pdo->exec("UPDATE ipm_devices SET dhcp_reservation=1 WHERE type='reservation' AND dhcp_reservation=0");

$stmt = $pdo->prepare("SELECT value FROM addon_kv WHERE addon_id='ipmanager' AND key='legacy_payload'");
$stmt->execute();
$legacyJson = $stmt->fetchColumn();
if ($legacyJson !== false && (int)$pdo->query('SELECT COUNT(*) FROM ipm_networks')->fetchColumn() === 0) {
    $legacy = json_decode((string)$legacyJson, true);
    if (is_array($legacy['networks'] ?? null)) {
        $netInsert = $pdo->prepare('INSERT OR IGNORE INTO ipm_networks(id,name,cidr,vlan,gateway,dhcp_start,dhcp_end,dns_json,description,source,created_at,updated_at) VALUES(:id,:name,:cidr,:vlan,:gateway,:dhcp_start,:dhcp_end,:dns,:description,\'legacy\',:created,:updated)');
        $devInsert = $pdo->prepare('INSERT OR IGNORE INTO ipm_devices(id,network_id,hostname,ip,mac,type,description,source,created_at,updated_at) VALUES(:id,:network_id,:hostname,:ip,:mac,:type,:description,\'legacy\',:created,:updated)');
        foreach ($legacy['networks'] as $network) {
            if (!is_array($network)) continue;
            $base = trim((string)($network['network'] ?? ''));
            $mask = max(1, min(30, (int)($network['mask'] ?? 24)));
            if (!filter_var($base, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) continue;
            $id = trim((string)($network['id'] ?? '')) ?: Database::uuid('net');
            $now = gmdate(DATE_ATOM);
            $netInsert->execute([
                'id'=>$id,'name'=>(string)($network['name'] ?? $base),'cidr'=>$base.'/'.$mask,
                'vlan'=>(string)($network['vlan'] ?? ''),'gateway'=>(string)($network['gateway'] ?? ''),
                'dhcp_start'=>(string)($network['dhcp_start'] ?? ''),'dhcp_end'=>(string)($network['dhcp_end'] ?? ''),
                'dns'=>json_encode($network['dns'] ?? [], JSON_UNESCAPED_SLASHES),'description'=>(string)($network['description'] ?? ''),
                'created'=>$now,'updated'=>$now,
            ]);
            foreach (($network['devices'] ?? []) as $device) {
                if (!is_array($device)) continue;
                $ip = trim((string)($device['ip'] ?? ''));
                if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) continue;
                $devInsert->execute([
                    'id'=>trim((string)($device['id'] ?? '')) ?: Database::uuid('device'),'network_id'=>$id,
                    'hostname'=>(string)($device['hostname'] ?? ''),'ip'=>$ip,'mac'=>(string)($device['mac'] ?? ''),
                    'type'=>!empty($device['is_reservation'])?'reservation':'static','description'=>(string)($device['description'] ?? ''),
                    'created'=>$now,'updated'=>$now,
                ]);
            }
        }
    }
    $pdo->prepare("DELETE FROM addon_kv WHERE addon_id='ipmanager' AND key='legacy_payload'")->execute();
}
