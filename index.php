<?php
declare(strict_types=1);

try {
    $ctx = require __DIR__ . '/bootstrap.php';
    require_once __DIR__ . '/src/View.php';
} catch (\Throwable $e) {
    http_response_code(500);
    ?><!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>PenguLab setup error</title><style>body{font:16px system-ui;background:#f5f6fb;color:#18213a;padding:40px}.box{max-width:760px;margin:auto;background:#fff;border:1px solid #e2e5ef;border-radius:20px;padding:28px}code{background:#f0f2f8;padding:2px 6px;border-radius:6px}</style></head><body><div class="box"><h1>PenguLab could not start</h1><p><?= htmlspecialchars($e->getMessage()) ?></p><p>For PenguLab 2.0 make sure <code>pdo_sqlite</code>, <code>curl</code>, <code>simplexml</code> and <code>sodium</code> are available and the data directory is writable.</p></div></body></html><?php
    exit;
}

$addons = $ctx['addons'];
$addonId = trim((string)($_GET['addon'] ?? ''));
$addonEntry = $addonId !== '' ? $addons->entrypoint($addonId) : null;
$settings = [
    'theme' => $ctx['db']->setting('theme', 'system'),
    'language' => $ctx['db']->setting('language', 'de'),
];
$active = $addonEntry ? $addonId : 'dashboard';
?><!doctype html>
<html lang="<?= htmlspecialchars((string)$settings['language']) ?>" data-theme="<?= htmlspecialchars((string)$settings['theme']) ?>">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">
  <meta name="color-scheme" content="light dark">
  <title>PenguLab</title>
  <link rel="icon" type="image/png" href="favicon.png">
  <link rel="stylesheet" href="assets/css/app.css?v=2.0.0-alpha.1">
</head>
<body>
<div class="shell">
  <?php \PenguLab\renderSidebar($addons, $active); ?>
  <div class="workspace">
    <?php if ($addonEntry): ?>
      <header class="topbar addon-topbar">
        <a class="mobile-brand" href="./#dashboard">PenguLab</a>
        <a class="back-link" href="./#dashboard"><?= \PenguLab\icon('back') ?> Dashboard</a>
        <div class="topbar-spacer"></div>
        <button class="topbar-search" type="button" onclick="location.href='./?search=1#dashboard'"><?= \PenguLab\icon('search') ?><span>Search</span><kbd>Ctrl K</kbd></button>
      </header>
      <main class="main addon-main">
        <?php $penguLab = $ctx; require $addonEntry; ?>
      </main>
    <?php else: ?>
      <header class="topbar">
        <a class="mobile-brand" href="#dashboard">PenguLab</a>
        <button class="mobile-menu" id="mobileMenu" type="button" aria-label="Navigation">☰</button>
        <button class="topbar-search" id="globalSearchButton" type="button"><?= \PenguLab\icon('search') ?><span>Search apps, IPs, integrations…</span><kbd>Ctrl K</kbd></button>
        <div class="topbar-spacer"></div>
        <div class="health-pill"><span class="status-dot"></span><span id="healthText">PenguLab ready</span></div>
        <button class="avatar-btn" type="button" title="Local instance">P</button>
      </header>
      <main class="main" id="app"><div class="boot-loader"><span></span><p>PenguLab wird geladen…</p></div></main>
      <script>window.PENGULAB={api:'api.php',version:'2.0.0-alpha.1'};</script>
      <script src="assets/js/app.js?v=2.0.0-alpha.1" defer></script>
    <?php endif; ?>
  </div>
</div>
</body>
</html>
