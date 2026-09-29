<?php declare(strict_types=1); ?>
<link rel="stylesheet" href="addons/penguops/assets/ops.css?v=<?= rawurlencode($penguLab['version']) ?>">
<div id="penguOps" class="ops-wrap">
  <div class="addon-header"><div><div class="eyebrow">PenguHub · Operations</div><h1>Homelab Health Center</h1><p>Messwerte verstehen. Auffälligkeiten prüfen. Entscheidungen selbst treffen.</p></div><button type="button" class="btn" id="opsRefresh">Ansicht aktualisieren</button></div>
  <div id="opsNotice" role="status" aria-live="polite"></div>
  <nav class="ops-tabs" aria-label="Health Center">
    <button type="button" class="btn primary" data-tab="health">Übersicht</button>
    <button type="button" class="btn" data-tab="history">Verlauf</button>
    <?php if($penguLab['auth']->isAdmin()): ?><button type="button" class="btn" data-tab="rules">Regeln</button><button type="button" class="btn" data-tab="ai">AI Model Hub</button><?php endif; ?>
  </nav>
  <section id="opsHealth"><p>Lade Messwerte …</p></section>
  <section id="opsHistory" hidden></section>
  <?php if($penguLab['auth']->isAdmin()): ?><section id="opsRules" hidden></section><section id="opsAi" hidden></section><?php endif; ?>
</div>
<dialog id="opsDialog"><div id="opsDialogBody"></div><form method="dialog"><button class="btn" type="submit">Schließen</button></form></dialog>
<script>window.OPS_CONFIG=<?= json_encode(['csrf'=>$penguLab['csrf'],'admin'=>$penguLab['auth']->isAdmin()],JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT) ?>;</script>
<script defer src="addons/penguops/assets/ops.js?v=<?= rawurlencode($penguLab['version']) ?>"></script>
