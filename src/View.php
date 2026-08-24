<?php
declare(strict_types=1);

namespace PenguLab;

function icon(string $name): string
{
    $paths = [
        'dashboard' => '<path d="M4 4h6v6H4zM14 4h6v10h-6zM4 14h6v6H4zM14 18h6v2h-6z"/>',
        'apps' => '<path d="M4 4h6v6H4zM14 4h6v6h-6zM4 14h6v6H4zM14 14h6v6h-6z"/>',
        'plug' => '<path d="M9 3v6m6-6v6M7 9h10v3a5 5 0 0 1-5 5v4m-5-9h10"/>',
        'store' => '<path d="M4 9l2-5h12l2 5M5 10v10h14V10M9 20v-6h6v6"/>',
        'settings' => '<circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.7 1.7 0 0 0 .3 1.9l.1.1-2.8 2.8-.1-.1a1.7 1.7 0 0 0-1.9-.3 1.7 1.7 0 0 0-1 1.6V21h-4v-.1a1.7 1.7 0 0 0-1-1.6 1.7 1.7 0 0 0-1.9.3l-.1.1L4.2 17l.1-.1a1.7 1.7 0 0 0 .3-1.9A1.7 1.7 0 0 0 3 14H3v-4h.1a1.7 1.7 0 0 0 1.6-1 1.7 1.7 0 0 0-.3-1.9L4.2 7 7 4.2l.1.1A1.7 1.7 0 0 0 9 4.6a1.7 1.7 0 0 0 1-1.6V3h4v.1a1.7 1.7 0 0 0 1 1.6 1.7 1.7 0 0 0 1.9-.3l.1-.1L19.8 7l-.1.1a1.7 1.7 0 0 0-.3 1.9 1.7 1.7 0 0 0 1.6 1h.1v4H21a1.7 1.7 0 0 0-1.6 1z"/>',
        'search' => '<circle cx="11" cy="11" r="7"/><path d="m20 20-4-4"/>',
        'network' => '<rect x="3" y="4" width="7" height="5" rx="1"/><rect x="14" y="15" width="7" height="5" rx="1"/><rect x="3" y="15" width="7" height="5" rx="1"/><path d="M6.5 9v3h11v3M6.5 12v3"/>',
        'back' => '<path d="m15 18-6-6 6-6"/>',
    ];
    $path = $paths[$name] ?? $paths['apps'];
    return '<svg viewBox="0 0 24 24" aria-hidden="true" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">' . $path . '</svg>';
}

function renderSidebar(AddonManager $addons, string $active = 'dashboard'): void
{
    $ipm = $addons->enabled('ipmanager');
    ?>
    <aside class="sidebar" id="sidebar">
      <a class="brand" href="./#dashboard" aria-label="PenguLab">
        <span class="brand-mark"><span></span><span></span></span>
        <span class="brand-name">PenguLab</span>
        <span class="alpha-badge">2.0</span>
      </a>
      <nav class="primary-nav">
        <a class="nav-item <?= $active==='dashboard'?'active':'' ?>" href="./#dashboard"><?= icon('dashboard') ?><span>Dashboard</span></a>
        <a class="nav-item <?= $active==='apps'?'active':'' ?>" href="./#apps"><?= icon('apps') ?><span>Apps</span></a>
        <a class="nav-item <?= $active==='integrations'?'active':'' ?>" href="./#integrations"><?= icon('plug') ?><span>Integrationen</span></a>
        <a class="nav-item <?= $active==='hub'?'active':'' ?>" href="./#hub"><?= icon('store') ?><span>PenguHub</span></a>
      </nav>
      <?php if ($ipm): ?>
      <div class="nav-section-label">ADD-ONS</div>
      <nav class="primary-nav addon-nav">
        <a class="nav-item <?= $active==='ipmanager'?'active':'' ?>" href="./?addon=ipmanager"><?= icon('network') ?><span>IP Manager</span></a>
      </nav>
      <?php endif; ?>
      <div class="sidebar-spacer"></div>
      <nav class="primary-nav sidebar-bottom">
        <a class="nav-item <?= $active==='settings'?'active':'' ?>" href="./#settings"><?= icon('settings') ?><span>Einstellungen</span></a>
      </nav>
      <div class="sidebar-version">PenguLab <b>2.0 alpha</b></div>
    </aside>
    <?php
}
