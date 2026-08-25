<?php
declare(strict_types=1);

namespace PenguLab;

use PDO;
use RuntimeException;

final class Database
{
    private PDO $pdo;
    private string $dataDir;
    private string $rootDir;

    public function __construct(string $dataDir, string $rootDir)
    {
        if (!extension_loaded('pdo_sqlite')) {
            throw new RuntimeException('PenguLab 2.0 requires the PHP extension pdo_sqlite.');
        }

        $this->dataDir = rtrim($dataDir, '/');
        $this->rootDir = rtrim($rootDir, '/');
        if (!is_dir($this->dataDir) && !mkdir($this->dataDir, 0770, true) && !is_dir($this->dataDir)) {
            throw new RuntimeException('Could not create PenguLab data directory.');
        }

        $path = $this->dataDir . '/pengulab.sqlite';
        $this->pdo = new PDO('sqlite:' . $path, null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
        $this->pdo->exec('PRAGMA foreign_keys = ON');
        $this->pdo->exec('PRAGMA journal_mode = WAL');
        $this->pdo->exec('PRAGMA busy_timeout = 5000');

        $this->migrateSchema();
        $this->migrateLegacy();
        $this->migrateDashboardGrid();
        $this->ensureDefaults();
    }

    public function pdo(): PDO
    {
        return $this->pdo;
    }

    public function transaction(callable $callback): mixed
    {
        $this->pdo->beginTransaction();
        try {
            $result = $callback($this->pdo);
            $this->pdo->commit();
            return $result;
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }

    public function setting(string $key, mixed $default = null): mixed
    {
        $stmt = $this->pdo->prepare('SELECT value FROM settings WHERE key = :key');
        $stmt->execute(['key' => $key]);
        $value = $stmt->fetchColumn();
        if ($value === false) {
            return $default;
        }
        $decoded = json_decode((string)$value, true);
        return json_last_error() === JSON_ERROR_NONE ? $decoded : $default;
    }

    public function setSetting(string $key, mixed $value): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO settings(key, value) VALUES(:key, :value) ON CONFLICT(key) DO UPDATE SET value = excluded.value'
        );
        $stmt->execute([
            'key' => $key,
            'value' => json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ]);
    }

    public function meta(string $key, ?string $default = null): ?string
    {
        $stmt = $this->pdo->prepare('SELECT value FROM meta WHERE key = :key');
        $stmt->execute(['key' => $key]);
        $value = $stmt->fetchColumn();
        return $value === false ? $default : (string)$value;
    }

    public function setMeta(string $key, string $value): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO meta(key, value) VALUES(:key, :value) ON CONFLICT(key) DO UPDATE SET value = excluded.value'
        );
        $stmt->execute(['key' => $key, 'value' => $value]);
    }

    public function apps(): array
    {
        return $this->pdo->query('SELECT * FROM apps ORDER BY position ASC, name COLLATE NOCASE ASC')->fetchAll();
    }

    public function widgets(?string $dashboardId = null): array
    {
        $dashboardId ??= $this->defaultDashboardId();
        $stmt = $this->pdo->prepare('SELECT * FROM widgets WHERE dashboard_id = :dashboard ORDER BY y ASC, x ASC, id ASC');
        $stmt->execute(['dashboard' => $dashboardId]);
        $rows = $stmt->fetchAll();
        foreach ($rows as &$row) {
            $row['config'] = json_decode((string)$row['config_json'], true) ?: [];
            unset($row['config_json']);
            $row['x'] = (int)$row['x'];
            $row['y'] = (int)$row['y'];
            $row['w'] = (int)$row['w'];
            $row['h'] = (int)$row['h'];
        }
        unset($row);
        return $rows;
    }

    public function defaultDashboardId(): string
    {
        $id = $this->pdo->query('SELECT id FROM dashboards WHERE is_default = 1 ORDER BY created_at ASC LIMIT 1')->fetchColumn();
        if ($id !== false) {
            return (string)$id;
        }
        $id = self::uuid('dash');
        $stmt = $this->pdo->prepare('INSERT INTO dashboards(id, name, is_default, created_at) VALUES(:id, :name, 1, :created)');
        $stmt->execute(['id' => $id, 'name' => 'Dashboard', 'created' => gmdate(DATE_ATOM)]);
        return $id;
    }

    public static function uuid(string $prefix = 'id'): string
    {
        return $prefix . '-' . bin2hex(random_bytes(8));
    }

    private function migrateSchema(): void
    {
        $sql = <<<'SQL'
CREATE TABLE IF NOT EXISTS meta (
    key TEXT PRIMARY KEY,
    value TEXT NOT NULL
);
CREATE TABLE IF NOT EXISTS settings (
    key TEXT PRIMARY KEY,
    value TEXT NOT NULL
);
CREATE TABLE IF NOT EXISTS apps (
    id TEXT PRIMARY KEY,
    name TEXT NOT NULL,
    url TEXT NOT NULL,
    description TEXT NOT NULL DEFAULT '',
    category TEXT NOT NULL DEFAULT '',
    image TEXT NOT NULL DEFAULT '',
    position INTEGER NOT NULL DEFAULT 0,
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL
);
CREATE TABLE IF NOT EXISTS dashboards (
    id TEXT PRIMARY KEY,
    name TEXT NOT NULL,
    is_default INTEGER NOT NULL DEFAULT 0,
    created_at TEXT NOT NULL
);
CREATE TABLE IF NOT EXISTS widgets (
    id TEXT PRIMARY KEY,
    dashboard_id TEXT NOT NULL,
    type TEXT NOT NULL,
    title TEXT NOT NULL DEFAULT '',
    x INTEGER NOT NULL DEFAULT 0,
    y INTEGER NOT NULL DEFAULT 0,
    w INTEGER NOT NULL DEFAULT 3,
    h INTEGER NOT NULL DEFAULT 2,
    config_json TEXT NOT NULL DEFAULT '{}',
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL,
    FOREIGN KEY(dashboard_id) REFERENCES dashboards(id) ON DELETE CASCADE
);
CREATE INDEX IF NOT EXISTS idx_widgets_dashboard ON widgets(dashboard_id);
CREATE TABLE IF NOT EXISTS addons (
    id TEXT PRIMARY KEY,
    version TEXT NOT NULL,
    enabled INTEGER NOT NULL DEFAULT 1,
    installed_at TEXT NOT NULL,
    updated_at TEXT NOT NULL
);
CREATE TABLE IF NOT EXISTS addon_kv (
    addon_id TEXT NOT NULL,
    key TEXT NOT NULL,
    value TEXT NOT NULL,
    PRIMARY KEY(addon_id, key)
);
CREATE TABLE IF NOT EXISTS integrations (
    id TEXT PRIMARY KEY,
    type TEXT NOT NULL,
    name TEXT NOT NULL,
    base_url TEXT NOT NULL DEFAULT '',
    username TEXT NOT NULL DEFAULT '',
    secret_enc TEXT NOT NULL DEFAULT '',
    verify_tls INTEGER NOT NULL DEFAULT 1,
    config_json TEXT NOT NULL DEFAULT '{}',
    enabled INTEGER NOT NULL DEFAULT 1,
    last_status TEXT NOT NULL DEFAULT 'unknown',
    last_error TEXT NOT NULL DEFAULT '',
    last_checked_at TEXT,
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL
);
CREATE INDEX IF NOT EXISTS idx_integrations_type ON integrations(type);
CREATE TABLE IF NOT EXISTS integration_widget_cache (
    integration_id TEXT PRIMARY KEY,
    summary_json TEXT NOT NULL DEFAULT '{}',
    fetched_at INTEGER NOT NULL,
    FOREIGN KEY(integration_id) REFERENCES integrations(id) ON DELETE CASCADE
);
CREATE TABLE IF NOT EXISTS integration_metric_samples (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    integration_id TEXT NOT NULL,
    metric TEXT NOT NULL,
    sampled_at INTEGER NOT NULL,
    value_a REAL NOT NULL,
    value_b REAL NOT NULL,
    FOREIGN KEY(integration_id) REFERENCES integrations(id) ON DELETE CASCADE
);
CREATE INDEX IF NOT EXISTS idx_integration_metric_samples_lookup ON integration_metric_samples(integration_id, metric, sampled_at DESC);
CREATE TABLE IF NOT EXISTS widget_data_cache (
    widget_id TEXT PRIMARY KEY,
    data_json TEXT NOT NULL DEFAULT '{}',
    fetched_at INTEGER NOT NULL,
    FOREIGN KEY(widget_id) REFERENCES widgets(id) ON DELETE CASCADE
);
CREATE TABLE IF NOT EXISTS users (
    id TEXT PRIMARY KEY,
    username TEXT NOT NULL UNIQUE,
    password_hash TEXT NOT NULL,
    role TEXT NOT NULL DEFAULT 'user',
    permissions_json TEXT NOT NULL DEFAULT '{}',
    preferences_json TEXT NOT NULL DEFAULT '{}',
    enabled INTEGER NOT NULL DEFAULT 1,
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL,
    last_login_at TEXT
);
CREATE TABLE IF NOT EXISTS remember_tokens (
    selector TEXT PRIMARY KEY,
    user_id TEXT NOT NULL,
    token_hash TEXT NOT NULL,
    expires_at INTEGER NOT NULL,
    created_at INTEGER NOT NULL,
    FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE
);
CREATE INDEX IF NOT EXISTS idx_remember_tokens_user ON remember_tokens(user_id);
SQL;
        $this->pdo->exec($sql);
        $this->setMeta('schema_version', '5');
    }

    private function migrateDashboardGrid(): void
    {
        // 2.6 introduced a four-times finer vertical grid. 2.6.1 doubles the
        // horizontal grid from 12 to 24 columns. Multiplying x/w by two preserves
        // the exact pixel geometry because the column/gap ratio scales evenly.
        $verticalDone = $this->meta('dashboard_grid_scale') === '4';
        $horizontalDone = $this->meta('dashboard_grid_columns') === '24';
        if ($verticalDone && $horizontalDone) {
            return;
        }
        $this->transaction(function (PDO $pdo) use ($verticalDone, $horizontalDone): void {
            if (!$verticalDone) {
                $pdo->exec('UPDATE widgets SET y = y * 4, h = h * 4');
            }
            if (!$horizontalDone) {
                $pdo->exec('UPDATE widgets SET x = x * 2, w = w * 2');
            }
        });
        if (!$verticalDone) $this->setMeta('dashboard_grid_scale', '4');
        if (!$horizontalDone) $this->setMeta('dashboard_grid_columns', '24');
    }

    private function ensureDefaults(): void
    {
        $defaults = [
            'theme' => 'system',
            'language' => 'de',
            'sidebar_compact' => false,
            'dashboard_title' => 'My Homelab',
        ];
        foreach ($defaults as $key => $value) {
            if ($this->setting($key, '__missing__') === '__missing__') {
                $this->setSetting($key, $value);
            }
        }
        // New/empty installations start directly on the 8px canvas. Existing
        // dashboards stay on the legacy grid until the browser can measure and
        // migrate their real pixel geometry without changing the visible layout.
        if ($this->setting('layout_engine', '__missing__') === '__missing__') {
            $count = (int)$this->pdo->query('SELECT COUNT(*) FROM widgets')->fetchColumn();
            $this->setSetting('layout_engine', $count === 0 ? 'canvas8' : 'legacy24');
        }
        $this->defaultDashboardId();
    }

    private function migrateLegacy(): void
    {
        if ($this->meta('legacy_migrated') === '1') {
            return;
        }

        $legacyCandidates = [
            $this->rootDir . '/apps.json',
            $this->dataDir . '/apps.json',
        ];
        $legacyFile = null;
        foreach ($legacyCandidates as $candidate) {
            if (is_file($candidate) && filesize($candidate) > 0) {
                $legacyFile = $candidate;
                break;
            }
        }

        if ($legacyFile === null) {
            $this->setMeta('legacy_migrated', '1');
            return;
        }

        $decoded = json_decode((string)file_get_contents($legacyFile), true);
        if (!is_array($decoded)) {
            $this->setMeta('legacy_migrated', '1');
            return;
        }

        $apps = array_is_list($decoded) ? $decoded : (is_array($decoded['apps'] ?? null) ? $decoded['apps'] : []);
        $legacySettings = array_is_list($decoded) ? [] : (is_array($decoded['settings'] ?? null) ? $decoded['settings'] : []);
        $legacyIp = array_is_list($decoded) ? null : ($decoded['addons']['ipmanager'] ?? null);

        $this->transaction(function (PDO $pdo) use ($apps, $legacySettings, $legacyIp): void {
            $count = (int)$pdo->query('SELECT COUNT(*) FROM apps')->fetchColumn();
            if ($count === 0 && $apps !== []) {
                $insert = $pdo->prepare(
                    'INSERT INTO apps(id,name,url,description,category,image,position,created_at,updated_at) VALUES(:id,:name,:url,:description,:category,:image,:position,:created,:updated)'
                );
                $widgetInsert = $pdo->prepare(
                    'INSERT INTO widgets(id,dashboard_id,type,title,x,y,w,h,config_json,created_at,updated_at) VALUES(:id,:dashboard,:type,:title,:x,:y,:w,:h,:config,:created,:updated)'
                );
                $dashboardId = $this->defaultDashboardId();
                foreach ($apps as $i => $app) {
                    if (!is_array($app)) continue;
                    $name = trim((string)($app['name'] ?? $app['title'] ?? ''));
                    $url = trim((string)($app['url'] ?? ''));
                    if ($name === '' || $url === '') continue;
                    $id = trim((string)($app['id'] ?? '')) ?: self::uuid('app');
                    $now = gmdate(DATE_ATOM);
                    $insert->execute([
                        'id' => $id,
                        'name' => mb_substr($name, 0, 100),
                        'url' => $url,
                        'description' => mb_substr(trim((string)($app['description'] ?? '')), 0, 300),
                        'category' => mb_substr(trim((string)($app['category'] ?? '')), 0, 80),
                        'image' => (string)($app['image'] ?? ''),
                        'position' => $i,
                        'created' => $now,
                        'updated' => $now,
                    ]);
                    $widgetInsert->execute([
                        'id' => self::uuid('widget'),
                        'dashboard' => $dashboardId,
                        'type' => 'app',
                        'title' => '',
                        'x' => ($i % 4) * 3,
                        'y' => intdiv($i, 4) * 2,
                        'w' => 3,
                        'h' => 2,
                        'config' => json_encode(['app_id' => $id], JSON_UNESCAPED_SLASHES),
                        'created' => $now,
                        'updated' => $now,
                    ]);
                }
            }

            if ($legacySettings !== []) {
                foreach (['theme', 'language'] as $key) {
                    if (isset($legacySettings[$key])) {
                        $this->setSetting($key, $legacySettings[$key]);
                    }
                }
            }

            $legacyHasIpManagerShortcut = false;
            foreach ($apps as $legacyApp) {
                if (!is_array($legacyApp)) continue;
                $legacyUrl = strtolower((string)($legacyApp['url'] ?? ''));
                if (str_contains($legacyUrl, 'addon=ipmanager')) {
                    $legacyHasIpManagerShortcut = true;
                    break;
                }
            }

            if (is_array($legacyIp) && is_array($legacyIp['networks'] ?? null)) {
                $stmt = $pdo->prepare('INSERT OR REPLACE INTO addon_kv(addon_id,key,value) VALUES(:addon,:key,:value)');
                $stmt->execute([
                    'addon' => 'ipmanager',
                    'key' => 'legacy_payload',
                    'value' => json_encode($legacyIp, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                ]);
                $now = gmdate(DATE_ATOM);
                $pdo->prepare('INSERT OR REPLACE INTO addons(id,version,enabled,installed_at,updated_at) VALUES(?,?,?,?,?)')
                    ->execute(['ipmanager', '2.1.0', 1, $now, $now]);
            } elseif ($legacyHasIpManagerShortcut) {
                $now = gmdate(DATE_ATOM);
                $pdo->prepare('INSERT OR IGNORE INTO addons(id,version,enabled,installed_at,updated_at) VALUES(?,?,?,?,?)')
                    ->execute(['ipmanager', '2.1.0', 1, $now, $now]);
            }
        });

        $this->setMeta('legacy_migrated', '1');
    }
}
