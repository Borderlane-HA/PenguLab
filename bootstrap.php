<?php
declare(strict_types=1);

use PenguLab\AddonManager;
use PenguLab\Database;
use PenguLab\IntegrationManager;
use PenguLab\Secrets;

if (session_status() !== PHP_SESSION_ACTIVE) {
    ini_set('session.use_strict_mode', '1');
    session_name('pengulab');
    session_set_cookie_params([
        'httponly' => true,
        'samesite' => 'Lax',
        'secure' => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'),
    ]);
    session_start();
}

spl_autoload_register(static function (string $class): void {
    $prefix = 'PenguLab\\';
    if (!str_starts_with($class, $prefix)) {
        return;
    }
    $relative = substr($class, strlen($prefix));
    $file = __DIR__ . '/src/' . str_replace('\\', '/', $relative) . '.php';
    if (is_file($file)) {
        require_once $file;
    }
});

if (!isset($_SESSION['pengulab_csrf'])) {
    $_SESSION['pengulab_csrf'] = bin2hex(random_bytes(24));
}

$dataDir = getenv('PENGULAB_DATA_DIR') ?: (__DIR__ . '/data');
$db = new Database($dataDir, __DIR__);
$secrets = new Secrets($dataDir);
$addons = new AddonManager($db, __DIR__ . '/addons');
$integrations = new IntegrationManager($db, $addons, $secrets);
$version = trim((string)@file_get_contents(__DIR__ . '/VERSION')) ?: 'dev';

return [
    'version' => $version,
    'db' => $db,
    'secrets' => $secrets,
    'addons' => $addons,
    'integrations' => $integrations,
    'csrf' => $_SESSION['pengulab_csrf'],
    'root' => __DIR__,
    'dataDir' => $dataDir,
];
