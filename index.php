<?php
declare(strict_types=1);

try {
    $ctx = require __DIR__ . '/bootstrap.php';
    require_once __DIR__ . '/src/View.php';
} catch (\Throwable $e) {
    http_response_code(500);
    ?><!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>PenguLab setup error</title><style>body{font:16px system-ui;background:#f5f6fb;color:#18213a;padding:40px}.box{max-width:760px;margin:auto;background:#fff;border:1px solid #e2e5ef;border-radius:20px;padding:28px}code{background:#f0f2f8;padding:2px 6px;border-radius:6px}</style></head><body><div class="box"><h1>PenguLab could not start</h1><p><?= htmlspecialchars($e->getMessage()) ?></p><p>For PenguLab make sure <code>pdo_sqlite</code>, <code>curl</code>, <code>simplexml</code> and <code>sodium</code> are available and the data directory is writable.</p></div></body></html><?php
    exit;
}

$auth = $ctx['auth'];
$loginError = '';
if (isset($_GET['logout'])) {
    $auth->logout();
    header('Location: ./');
    exit;
}
if (!$auth->check() && ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && isset($_POST['pengulab_login'])) {
    $csrf = (string)($_POST['csrf'] ?? '');
    if ($csrf === '' || !hash_equals((string)$ctx['csrf'], $csrf)) {
        $loginError = 'Sitzung abgelaufen. Bitte erneut versuchen.';
    } elseif (!$auth->login((string)($_POST['username'] ?? ''), (string)($_POST['password'] ?? ''), !empty($_POST['remember']))) {
        $loginError = 'Benutzername oder Passwort ist falsch.';
    } else {
        header('Location: ./');
        exit;
    }
}
if (!$auth->check()) {
    $version = htmlspecialchars((string)($ctx['version'] ?? 'dev'));
    ?><!doctype html>
    <html lang="de"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="color-scheme" content="light dark"><title>PenguLab · Login</title><link rel="icon" type="image/png" sizes="64x64" href="favicon.png?v=<?= rawurlencode($version) ?>"><link rel="apple-touch-icon" href="assets/img/pengulab-logo.png?v=<?= rawurlencode($version) ?>"><link rel="stylesheet" href="assets/css/app.css?v=<?= rawurlencode($version) ?>"></head>
    <body class="login-page"><main class="login-wrap"><section class="login-card"><div class="login-brand"><img class="login-logo" src="assets/img/pengulab-logo.png?v=<?= rawurlencode($version) ?>" alt="PenguLab"><div><strong>PenguLab</strong><span>Your self-hosted Control Center</span></div></div><div class="eyebrow">Control Center</div><h1>Willkommen zurück</h1><p>Dein Homelab, deine Dienste und Integrationen – an einem Ort.</p><?php if($loginError!==''): ?><div class="login-error"><?= htmlspecialchars($loginError) ?></div><?php endif; ?><form method="post" autocomplete="on"><input type="hidden" name="pengulab_login" value="1"><input type="hidden" name="csrf" value="<?= htmlspecialchars((string)$ctx['csrf']) ?>"><div class="field-row"><label>Benutzername</label><input name="username" autocomplete="username" required autofocus></div><div class="field-row"><label>Passwort</label><input name="password" type="password" autocomplete="current-password" required></div><label class="remember-line"><input type="checkbox" name="remember" value="1" checked><span>Angemeldet bleiben</span></label><button class="btn primary login-button" type="submit">Anmelden</button></form><div class="login-hint">Erstanmeldung: <code>admin</code> / <code>admin</code> · Passwort danach unter Einstellungen ändern.</div></section></main></body></html><?php
    exit;
}

$addons = $ctx['addons'];
$addonId = trim((string)($_GET['addon'] ?? ''));
$addonEntry = $addonId !== '' ? $addons->entrypoint($addonId) : null;
if ($addonId === 'ipmanager' && !$auth->canIpManager()) { header('Location: ./#dashboard'); exit; }
$settings = [
    'theme' => $ctx['db']->setting('theme', 'system'),
    'language' => $ctx['db']->setting('language', 'de'),
];
$active = $addonEntry ? $addonId : 'dashboard';
$version = (string)($ctx['version'] ?? 'dev');
$assetVersion = rawurlencode($version);
?><!doctype html>
<html lang="<?= htmlspecialchars((string)$settings['language']) ?>" data-theme="<?= htmlspecialchars((string)$settings['theme']) ?>">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">
  <meta name="color-scheme" content="light dark">
  <title>PenguLab</title>
  <link rel="icon" type="image/png" sizes="64x64" href="favicon.png?v=<?= htmlspecialchars($assetVersion) ?>">
  <link rel="apple-touch-icon" href="assets/img/pengulab-logo.png?v=<?= htmlspecialchars($assetVersion) ?>">
  <link rel="stylesheet" href="assets/css/app.css?v=<?= htmlspecialchars($assetVersion) ?>">
</head>
<body class="<?= $auth->preference('sidebar_collapsed', false) ? 'sidebar-collapsed' : '' ?>">
<div class="shell">
  <?php \PenguLab\renderSidebar($addons, $active, $auth, $version); ?>
  <div class="workspace">
    <?php if ($addonEntry): ?>
      <header class="topbar addon-topbar">
        <button class="sidebar-open-btn" data-sidebar-open type="button" aria-label="Seitenleiste öffnen"><?= \PenguLab\icon('menu') ?></button>
        <a class="mobile-brand" href="./#dashboard"><img src="assets/img/pengulab-logo.png?v=<?= htmlspecialchars($assetVersion) ?>" alt="">PenguLab</a>
        <a class="back-link" href="./#dashboard"><?= \PenguLab\icon('back') ?> Dashboard</a>
        <div class="topbar-spacer"></div>
        <button class="topbar-search" type="button" onclick="location.href='./?search=1#dashboard'"><?= \PenguLab\icon('search') ?><span>Search</span><kbd>Ctrl K</kbd></button>
      </header>
      <main class="main addon-main">
        <?php $penguLab = $ctx; require $addonEntry; ?>
      </main>
    <?php else: ?>
      <header class="topbar">
        <button class="sidebar-open-btn" data-sidebar-open type="button" aria-label="Seitenleiste öffnen"><?= \PenguLab\icon('menu') ?></button>
        <a class="mobile-brand" href="#dashboard"><img src="assets/img/pengulab-logo.png?v=<?= htmlspecialchars($assetVersion) ?>" alt="">PenguLab</a>
        <button class="mobile-menu" id="mobileMenu" type="button" aria-label="Navigation">☰</button>
        <button class="topbar-search" id="globalSearchButton" type="button"><?= \PenguLab\icon('search') ?><span>Search apps, IPs, integrations…</span><kbd>Ctrl K</kbd></button>
        <div class="topbar-spacer"></div>
        <div class="health-pill"><span class="status-dot"></span><span id="healthText">PenguLab ready</span></div>
        <a class="avatar-btn" href="#settings" title="<?= htmlspecialchars((string)($auth->user()['username'] ?? 'User')) ?>"><?= htmlspecialchars(strtoupper(substr((string)($auth->user()['username'] ?? 'P'),0,1))) ?></a>
      </header>
      <main class="main" id="app"><div class="boot-loader"><span></span><p>PenguLab wird geladen…</p></div></main>
      <script src="assets/js/app.js?v=<?= htmlspecialchars($assetVersion) ?>" defer></script>
    <?php endif; ?>
  </div>
</div>
<script>window.PENGULAB={api:'api.php',version:<?= json_encode($version, JSON_UNESCAPED_SLASHES) ?>,csrf:<?= json_encode((string)$ctx['csrf'], JSON_UNESCAPED_SLASHES) ?>};</script>
<script src="assets/js/shell.js?v=<?= htmlspecialchars($assetVersion) ?>" defer></script>
</body>
</html>
