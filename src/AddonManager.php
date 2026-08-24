<?php
declare(strict_types=1);

namespace PenguLab;

use RuntimeException;
use ZipArchive;

final class AddonManager
{
    public function __construct(
        private Database $db,
        private string $addonsDir,
        private ?string $customAddonsDir = null,
    ) {
        if ($this->customAddonsDir !== null && !is_dir($this->customAddonsDir)) {
            @mkdir($this->customAddonsDir, 0770, true);
        }
        $this->syncInstalledVersions();
        $this->runPendingInstalls();
    }

    public function available(): array
    {
        $items = [];
        foreach ($this->scanDir($this->addonsDir, 'bundled') as $id => $manifest) {
            $items[$id] = $manifest;
        }
        if ($this->customAddonsDir !== null) {
            foreach ($this->scanDir($this->customAddonsDir, 'custom') as $id => $manifest) {
                // Uploaded packages are never allowed to shadow bundled packages.
                if (!isset($items[$id])) $items[$id] = $manifest;
            }
        }
        ksort($items, SORT_NATURAL | SORT_FLAG_CASE);
        return $items;
    }

    private function scanDir(string $dir, string $source): array
    {
        $items = [];
        foreach (glob(rtrim($dir, '/') . '/*/manifest.json') ?: [] as $file) {
            $manifest = json_decode((string)file_get_contents($file), true);
            if (!is_array($manifest)) continue;
            $id = trim((string)($manifest['id'] ?? ''));
            if ($id === '' || !preg_match('/^[a-z0-9][a-z0-9_-]*$/', $id)) continue;
            $manifest['_path'] = dirname($file);
            $manifest['_source'] = $source;
            $items[$id] = $manifest;
        }
        return $items;
    }

    public function all(): array
    {
        $available = $this->available();
        $installedRows = $this->db->pdo()->query('SELECT * FROM addons')->fetchAll();
        $installed = [];
        foreach ($installedRows as $row) $installed[(string)$row['id']] = $row;

        $out = [];
        foreach ($available as $id => $manifest) {
            $row = $installed[$id] ?? null;
            $public = $manifest;
            $source = (string)($public['_source'] ?? 'bundled');
            unset($public['_path'], $public['_source'], $public['entrypoint'], $public['api'], $public['connector']);
            $public['installed'] = $row !== null;
            $public['enabled'] = $row !== null && (int)$row['enabled'] === 1;
            $public['installed_version'] = $row['version'] ?? null;
            $public['source'] = $source;
            $public['uploaded'] = $source === 'custom';
            $out[] = $public;
        }
        return $out;
    }

    public function enabled(string $id): bool
    {
        $stmt = $this->db->pdo()->prepare('SELECT enabled FROM addons WHERE id = :id');
        $stmt->execute(['id' => $id]);
        return (int)($stmt->fetchColumn() ?: 0) === 1;
    }

    public function manifest(string $id): ?array
    {
        return $this->available()[$id] ?? null;
    }

    public function install(string $id): array
    {
        $manifest = $this->manifest($id);
        if (!$manifest) throw new RuntimeException('Unknown PenguHub package.');
        $now = gmdate(DATE_ATOM);
        $version = (string)($manifest['version'] ?? '0.0.0');
        $stmt = $this->db->pdo()->prepare(
            'INSERT INTO addons(id,version,enabled,installed_at,updated_at) VALUES(:id,:version,1,:now,:now) '
            . 'ON CONFLICT(id) DO UPDATE SET version=excluded.version, enabled=1, updated_at=excluded.updated_at'
        );
        $stmt->execute(['id' => $id, 'version' => $version, 'now' => $now]);
        $this->runInstallScript($manifest);
        return $this->publicManifest($manifest, true, true);
    }

    public function uninstall(string $id): void
    {
        $stmt = $this->db->pdo()->prepare('UPDATE addons SET enabled = 0, updated_at = :now WHERE id = :id');
        $stmt->execute(['now' => gmdate(DATE_ATOM), 'id' => $id]);
    }

    public function uploadPackage(string $zipPath, string $originalName = ''): array
    {
        if ($this->customAddonsDir === null) throw new RuntimeException('Custom PenguHub packages are not enabled.');
        if (!class_exists(ZipArchive::class)) throw new RuntimeException('ZIP support is not available in this PenguLab image.');
        if (!is_file($zipPath)) throw new RuntimeException('Uploaded package is missing.');
        $size = filesize($zipPath);
        if ($size === false || $size < 1) throw new RuntimeException('Uploaded package is empty.');
        if ($size > 8 * 1024 * 1024) throw new RuntimeException('PenguHub package is too large (maximum 8 MB).');

        $zip = new ZipArchive();
        if ($zip->open($zipPath) !== true) throw new RuntimeException('The uploaded file is not a readable ZIP package.');
        try {
            if ($zip->numFiles < 1 || $zip->numFiles > 120) throw new RuntimeException('Invalid package file count.');
            $names = [];
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $stat = $zip->statIndex($i);
                if (!is_array($stat)) continue;
                $name = str_replace('\\', '/', (string)($stat['name'] ?? ''));
                if ($name === '' || str_starts_with($name, '/') || preg_match('#(^|/)\.\.(/|$)#', $name)) {
                    throw new RuntimeException('Unsafe path found in ZIP package.');
                }
                $names[] = $name;
            }

            $prefix = '';
            if (!in_array('manifest.json', $names, true)) {
                $candidates = array_values(array_filter($names, static fn(string $n): bool => preg_match('#^[^/]+/manifest\.json$#', $n) === 1));
                if (count($candidates) !== 1) throw new RuntimeException('Package must contain exactly one manifest.json at its root.');
                $prefix = dirname($candidates[0]) . '/';
            }
            $manifestEntry = $prefix . 'manifest.json';
            $manifestRaw = $zip->getFromName($manifestEntry);
            $manifest = is_string($manifestRaw) ? json_decode($manifestRaw, true) : null;
            if (!is_array($manifest)) throw new RuntimeException('manifest.json is invalid JSON.');
            $id = trim((string)($manifest['id'] ?? ''));
            $name = trim((string)($manifest['name'] ?? ''));
            $version = trim((string)($manifest['version'] ?? ''));
            if (!preg_match('/^[a-z0-9][a-z0-9_-]*$/', $id)) throw new RuntimeException('Invalid package id. Use lowercase letters, numbers, dash or underscore.');
            if ($name === '' || mb_strlen($name) > 100) throw new RuntimeException('Package name is missing or too long.');
            if ($version === '' || mb_strlen($version) > 40) throw new RuntimeException('Package version is missing or too long.');
            $integrationType = '';
            if (is_array($manifest['integration'] ?? null)) {
                $integrationType = trim((string)($manifest['integration']['type'] ?? ''));
                if ($integrationType === '' || !preg_match('/^[a-z0-9][a-z0-9_-]*$/', $integrationType)) {
                    throw new RuntimeException('Uploaded integration has an invalid integration type.');
                }
                foreach ($this->available() as $existingId => $existingManifest) {
                    $existingType = (string)($existingManifest['integration']['type'] ?? '');
                    if ($existingId === $id) {
                        if (($existingManifest['_source'] ?? '') === 'custom' && $existingType !== '' && $existingType !== $integrationType) {
                            throw new RuntimeException('An uploaded package update may not change its integration type.');
                        }
                        continue;
                    }
                    if ($existingType === $integrationType) {
                        throw new RuntimeException('Another PenguHub package already provides integration type ' . $integrationType . '.');
                    }
                }
            }

            // Built-in packages may not be replaced by an uploaded package.
            foreach ($this->scanDir($this->addonsDir, 'bundled') as $bundledId => $_) {
                if ($bundledId === $id) throw new RuntimeException('A bundled PenguHub package with this id already exists.');
            }

            $staging = rtrim($this->customAddonsDir, '/') . '/.upload-' . bin2hex(random_bytes(6));
            if (!@mkdir($staging, 0770, true) && !is_dir($staging)) throw new RuntimeException('Could not create package staging directory.');
            $allowedExt = ['php','json','md','txt','css','js','png','jpg','jpeg','svg','webp'];
            $total = 0;
            try {
                for ($i = 0; $i < $zip->numFiles; $i++) {
                    $stat = $zip->statIndex($i);
                    if (!is_array($stat)) continue;
                    $nameInZip = str_replace('\\', '/', (string)($stat['name'] ?? ''));
                    if ($prefix !== '' && !str_starts_with($nameInZip, $prefix)) continue;
                    $relative = $prefix === '' ? $nameInZip : substr($nameInZip, strlen($prefix));
                    $relative = ltrim($relative, '/');
                    if ($relative === '') continue;
                    if (str_ends_with($relative, '/')) {
                        @mkdir($staging . '/' . rtrim($relative, '/'), 0770, true);
                        continue;
                    }
                    if (preg_match('#(^|/)\.#', $relative)) throw new RuntimeException('Hidden files are not allowed in uploaded packages.');
                    $ext = strtolower(pathinfo($relative, PATHINFO_EXTENSION));
                    if (!in_array($ext, $allowedExt, true)) throw new RuntimeException('File type .' . $ext . ' is not allowed in uploaded packages.');
                    $fileSize = (int)($stat['size'] ?? 0);
                    if ($fileSize > 2 * 1024 * 1024) throw new RuntimeException('A package file exceeds the 2 MB per-file limit.');
                    $total += $fileSize;
                    if ($total > 12 * 1024 * 1024) throw new RuntimeException('Uncompressed package is too large.');
                    $content = $zip->getFromIndex($i);
                    if (!is_string($content)) throw new RuntimeException('Could not read package file ' . $relative . '.');
                    $dest = $staging . '/' . $relative;
                    @mkdir(dirname($dest), 0770, true);
                    if (file_put_contents($dest, $content, LOCK_EX) === false) throw new RuntimeException('Could not write package file ' . $relative . '.');
                }

                foreach (['connector','entrypoint','api'] as $key) {
                    $file = trim((string)($manifest[$key] ?? ''));
                    if ($file !== '' && (!preg_match('/^[A-Za-z0-9_\.\/-]+$/', $file) || str_contains($file, '..') || !is_file($staging . '/' . ltrim($file, '/')))) {
                        throw new RuntimeException('Manifest references missing or invalid ' . $key . ' file.');
                    }
                }
                if (!is_file($staging . '/manifest.json')) throw new RuntimeException('Extracted package is missing manifest.json.');

                $target = rtrim($this->customAddonsDir, '/') . '/' . $id;
                $backup = $target . '.old-' . bin2hex(random_bytes(4));
                if (is_dir($target)) {
                    if (!@rename($target, $backup)) throw new RuntimeException('Could not replace existing uploaded package.');
                }
                if (!@rename($staging, $target)) {
                    if (is_dir($backup)) @rename($backup, $target);
                    throw new RuntimeException('Could not activate uploaded package.');
                }
                try {
                    $installed = $this->install($id);
                    if (is_dir($backup)) $this->removeTree($backup);
                    $installed['uploaded_from'] = mb_substr($originalName, 0, 180);
                    return $installed;
                } catch (\Throwable $installError) {
                    if (is_dir($target)) $this->removeTree($target);
                    if (is_dir($backup)) @rename($backup, $target);
                    $this->syncInstalledVersions();
                    throw new RuntimeException('Package install failed: ' . $installError->getMessage(), 0, $installError);
                }
            } catch (\Throwable $e) {
                if (is_dir($staging)) $this->removeTree($staging);
                throw $e;
            }
        } finally {
            $zip->close();
        }
    }

    public function deleteUploadedPackage(string $id): void
    {
        $manifest = $this->manifest($id);
        if (!$manifest || ($manifest['_source'] ?? '') !== 'custom') throw new RuntimeException('Only uploaded PenguHub packages can be deleted.');
        $this->uninstall($id);
        $path = (string)$manifest['_path'];
        if ($path !== '' && is_dir($path)) $this->removeTree($path);
    }

    public function enabledManifests(): array
    {
        return array_filter($this->available(), fn(array $m): bool => $this->enabled((string)$m['id']));
    }

    public function entrypoint(string $id): ?string
    {
        if (!$this->enabled($id)) return null;
        $manifest = $this->manifest($id);
        if (!$manifest) return null;
        $entry = trim((string)($manifest['entrypoint'] ?? ''));
        if ($entry === '') return null;
        $path = $manifest['_path'] . '/' . ltrim($entry, '/');
        return is_file($path) ? $path : null;
    }

    public function apiFile(string $id): ?string
    {
        if (!$this->enabled($id)) return null;
        $manifest = $this->manifest($id);
        if (!$manifest) return null;
        $api = trim((string)($manifest['api'] ?? ''));
        if ($api === '') return null;
        $path = $manifest['_path'] . '/' . ltrim($api, '/');
        return is_file($path) ? $path : null;
    }

    public function widgetCatalog(): array
    {
        $items = [
            ['type' => 'clock', 'name' => 'Clock', 'description' => 'Time and date', 'icon' => 'clock', 'defaultSize' => [3,2]],
            ['type' => 'note', 'name' => 'Note', 'description' => 'A simple dashboard note', 'icon' => 'note', 'defaultSize' => [3,2]],
        ];
        foreach ($this->enabledManifests() as $manifest) {
            foreach (($manifest['widgets'] ?? []) as $widget) {
                if (!is_array($widget)) continue;
                $widget['addon_id'] = $manifest['id'];
                $items[] = $widget;
            }
        }
        return $items;
    }

    public function integrationTypes(): array
    {
        $types = [];
        foreach ($this->enabledManifests() as $manifest) {
            $integration = $manifest['integration'] ?? null;
            if (!is_array($integration)) continue;
            $integration['addon_id'] = $manifest['id'];
            $types[] = $integration;
        }
        return $types;
    }

    private function syncInstalledVersions(): void
    {
        foreach ($this->available() as $id => $manifest) {
            $stmt = $this->db->pdo()->prepare('SELECT version FROM addons WHERE id = :id');
            $stmt->execute(['id' => $id]);
            $installed = $stmt->fetchColumn();
            if ($installed !== false && (string)$installed !== (string)($manifest['version'] ?? '')) {
                $this->db->pdo()->prepare('UPDATE addons SET version = :version, updated_at = :now WHERE id = :id')
                    ->execute(['version' => (string)$manifest['version'], 'now' => gmdate(DATE_ATOM), 'id' => $id]);
            }
        }
    }

    private function runPendingInstalls(): void
    {
        foreach ($this->enabledManifests() as $manifest) $this->runInstallScript($manifest);
    }

    private function runInstallScript(array $manifest): void
    {
        $install = $manifest['_path'] . '/install.php';
        if (is_file($install)) {
            $db = $this->db;
            require $install;
        }
    }

    private function publicManifest(array $manifest, bool $installed, bool $enabled): array
    {
        $source = (string)($manifest['_source'] ?? 'bundled');
        unset($manifest['_path'], $manifest['_source'], $manifest['entrypoint'], $manifest['api'], $manifest['connector']);
        $manifest['installed'] = $installed;
        $manifest['enabled'] = $enabled;
        $manifest['source'] = $source;
        $manifest['uploaded'] = $source === 'custom';
        return $manifest;
    }

    private function removeTree(string $path): void
    {
        if (!is_dir($path)) return;
        $items = scandir($path);
        if (!is_array($items)) return;
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') continue;
            $full = $path . '/' . $item;
            if (is_dir($full) && !is_link($full)) $this->removeTree($full); else @unlink($full);
        }
        @rmdir($path);
    }
}
