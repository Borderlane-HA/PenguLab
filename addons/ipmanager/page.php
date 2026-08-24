<?php
declare(strict_types=1);
/** @var array $penguLab */
?>
<div class="addon-wrap" id="ipManagerApp">
  <div class="addon-header">
    <div><div class="eyebrow">PenguHub Add-on</div><h1>IP Manager</h1><p>Netze und belegte Adressen dokumentieren – ohne leere IP-Tabellen.</p></div>
    <div class="page-actions"><button class="btn primary" id="ipmAddNetwork">+ Netzwerk</button></div>
  </div>
  <div class="ipm-layout">
    <aside class="ipm-panel ipm-side">
      <div class="ipm-side-head"><strong>Networks</strong><button class="btn small icon-only" id="ipmAddNetworkSmall" title="Netzwerk hinzufügen">+</button></div>
      <div class="ipm-network-list" id="ipmNetworkList"><div class="widget-loading">Lädt…</div></div>
    </aside>
    <section class="ipm-panel ipm-detail" id="ipmDetail"><div class="ipm-empty"><div><strong>Netzwerk auswählen</strong><span>Links ein Netz wählen oder ein neues anlegen.</span></div></div></section>
  </div>
</div>
<script>
window.IPM_CONFIG = {
  api: <?= json_encode('api.php', JSON_UNESCAPED_SLASHES) ?>,
  csrf: <?= json_encode((string)$penguLab['csrf'], JSON_UNESCAPED_SLASHES) ?>
};
</script>
<script src="addons/ipmanager/assets/ipmanager.js?v=2.0.0" defer></script>
