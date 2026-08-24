(() => {
  'use strict';

  const apiUrl = window.PENGULAB?.api || 'api.php';
  const appRoot = document.getElementById('app');
  const state = {
    boot: null,
    csrf: '',
    view: 'dashboard',
    editMode: false,
    layoutOriginal: null,
    layoutDirty: false,
    appSearch: '',
    appCategory: 'all',
    appView: localStorage.getItem('pengulab-app-view') || 'compact',
    widgetRefreshTimers: new Map(),
    widgetInFlight: new Map(),
    metricHistory: new Map(),
  };

  const $ = (sel, root = document) => root.querySelector(sel);
  const $$ = (sel, root = document) => [...root.querySelectorAll(sel)];
  const esc = (value) => String(value ?? '')
    .replaceAll('&', '&amp;').replaceAll('<', '&lt;').replaceAll('>', '&gt;')
    .replaceAll('"', '&quot;').replaceAll("'", '&#39;');
  const attr = esc;
  const initials = (name) => String(name || '?').trim().split(/\s+/).slice(0,2).map(x => x[0] || '').join('').toUpperCase();
  const hostOf = (url) => { try { return new URL(url).host; } catch { return url || ''; } };
  const fmt = (n) => new Intl.NumberFormat(document.documentElement.lang || 'de-DE', { maximumFractionDigits: 1 }).format(Number(n || 0));
  const fmtDate = (value) => { if (!value) return ''; const d = new Date(value); return Number.isNaN(d.getTime()) ? '' : new Intl.DateTimeFormat(document.documentElement.lang || 'de-DE', { day:'2-digit', month:'2-digit', hour:'2-digit', minute:'2-digit' }).format(d); };
  const fmtTime = (value) => { if (!value) return ''; const raw=typeof value==='number'&&value<1e12?value*1000:value; const d=new Date(raw); return Number.isNaN(d.getTime())?'':new Intl.DateTimeFormat(document.documentElement.lang||'de-DE',{hour:'2-digit',minute:'2-digit'}).format(d); };
  const isAdmin = () => state.boot?.user?.role === 'admin';

  async function api(route, options = {}) {
    const method = options.method || (options.body ? 'POST' : 'GET');
    const url = new URL(apiUrl, window.location.href);
    url.searchParams.set('route', route);
    Object.entries(options.params || {}).forEach(([k,v]) => url.searchParams.set(k, v));
    const headers = { ...(options.headers || {}) };
    if (method !== 'GET') headers['X-PenguLab-CSRF'] = state.csrf;
    if (options.body !== undefined) headers['Content-Type'] = 'application/json';
    const response = await fetch(url, {
      method,
      headers,
      cache: 'no-store',
      body: options.body !== undefined ? JSON.stringify(options.body) : undefined,
    });
    const data = await response.json().catch(() => ({}));
    if (!response.ok || !data.ok) throw new Error(data.error || `HTTP ${response.status}`);
    return data;
  }

  function toast(message, type = 'ok') {
    let wrap = $('.toast-wrap');
    if (!wrap) {
      wrap = document.createElement('div'); wrap.className = 'toast-wrap'; document.body.appendChild(wrap);
    }
    const el = document.createElement('div'); el.className = `toast ${type === 'error' ? 'error' : ''}`; el.textContent = message; wrap.appendChild(el);
    setTimeout(() => el.remove(), 3800);
  }

  function clearTimers() {
    for (const timer of state.widgetRefreshTimers.values()) clearInterval(timer);
    state.widgetRefreshTimers.clear();
  }

  function setViewFromHash() {
    const raw = location.hash.replace(/^#/, '').split('?')[0] || 'dashboard';
    const allowed = ['dashboard','apps','settings'];
    if (isAdmin() || (state.boot?.integrations||[]).length) allowed.push('integrations');
    if (isAdmin()) allowed.push('hub');
    state.view = allowed.includes(raw) ? raw : 'dashboard';
    $$('.nav-item').forEach(a => a.classList.remove('active'));
    const nav = $(`.nav-item[href$="#${state.view}"]`); if (nav) nav.classList.add('active');
  }

  function pageHead(eyebrow, title, subtitle, actions = '') {
    return `<div class="page-head"><div class="page-head-main"><div class="eyebrow">${esc(eyebrow)}</div><h1 class="page-title">${esc(title)}</h1><p class="page-subtitle">${esc(subtitle)}</p></div><div class="page-actions">${actions}</div></div>`;
  }

  function appIcon(app, className = '') {
    const image = app?.image || '';
    return `<div class="app-icon ${className}">${image ? `<img src="${attr(image)}" alt="" onerror="this.style.display='none';this.nextElementSibling.style.display='grid'"><span class="app-fallback" style="display:none">${esc(initials(app?.name))}</span>` : `<span class="app-fallback">${esc(initials(app?.name))}</span>`}</div>`;
  }

  async function boot() {
    try {
      const data = await api('bootstrap');
      state.boot = data; state.csrf = data.csrf || '';
      applyTheme(data.settings?.theme || 'system');
      setViewFromHash();
      render();
      setupGlobalSearch();
      if (new URL(location.href).searchParams.get('search') === '1') {
        const clean = location.pathname + location.hash;
        history.replaceState(null, '', clean);
        setTimeout(openGlobalSearch, 60);
      }
    } catch (e) {
      appRoot.innerHTML = `<div class="section-card" style="padding:24px"><h2>PenguLab konnte nicht geladen werden</h2><p style="color:var(--muted)">${esc(e.message)}</p></div>`;
    }
  }

  async function refreshBoot({renderAfter = true} = {}) {
    const data = await api('bootstrap');
    state.boot = data; state.csrf = data.csrf || state.csrf;
    if (renderAfter) render();
    return data;
  }

  function render() {
    if (!state.boot) return;
    clearTimers();
    document.body.classList.toggle('editing', state.editMode && state.view === 'dashboard');
    switch (state.view) {
      case 'apps': renderApps(); break;
      case 'integrations': renderIntegrations(); break;
      case 'hub': renderHub(); break;
      case 'settings': renderSettings(); break;
      default: renderDashboard(); break;
    }
    window.scrollTo({top:0, behavior:'auto'});
  }

  function layoutSnapshot() {
    const out = {};
    for (const w of state.boot?.widgets || []) out[w.id] = {x:w.x,y:w.y,w:w.w,h:w.h};
    return out;
  }

  function beginLayoutEdit() {
    state.layoutOriginal = layoutSnapshot();
    state.layoutDirty = false;
    state.editMode = true;
    renderDashboard();
  }

  function discardLayoutEdit({renderAfter=true} = {}) {
    if (state.layoutOriginal) {
      for (const widget of state.boot?.widgets || []) {
        const original = state.layoutOriginal[widget.id];
        if (original) Object.assign(widget, original);
      }
    }
    state.layoutOriginal = null;
    state.layoutDirty = false;
    state.editMode = false;
    if (renderAfter) renderDashboard();
  }

  async function commitLayoutEdit() {
    if (!state.editMode) return;
    if (!state.layoutDirty) { discardLayoutEdit(); return; }
    const button=$('#saveLayoutBtn');if(button){button.disabled=true;button.textContent='Speichert…';}
    try {
      const d=await api('widgets/layout',{body:{widgets:(state.boot.widgets||[]).map(({id,x,y,w,h})=>({id,x,y,w,h}))}});
      state.boot.widgets=d.widgets;
      state.layoutOriginal=null;state.layoutDirty=false;state.editMode=false;
      renderDashboard();toast('Dashboard-Layout gespeichert.');
    } catch(e) {
      if(button){button.disabled=false;button.textContent='Speichern';}
      toast(e.message,'error');
    }
  }

  function renderDashboard() {
    // renderDashboard() is also called directly while entering/leaving layout edit mode.
    // Keep the body state in sync so drag handles, delete buttons and resize grips are visible.
    document.body.classList.toggle('editing', state.editMode);
    const widgets = state.boot.widgets || [];
    const title = state.boot.settings?.dashboard_title || 'My Homelab';
    const dashboardActions=isAdmin()?(state.editMode
      ? `<button class="btn" id="cancelLayoutBtn">Abbrechen</button><button class="btn primary" id="saveLayoutBtn">Speichern</button>`
      : `<button class="btn" id="editLayoutBtn">Layout bearbeiten</button><button class="btn primary" id="addWidgetBtn">+ Widget</button>`):'';
    const editHint=state.editMode?'Widgets ziehen oder am rechten unteren Rand skalieren. Erst „Speichern“ übernimmt das komplette Layout.':'Dashboard-Widgets lassen sich über „Layout bearbeiten“ frei anordnen.';
    appRoot.innerHTML = pageHead('Control Center', title, 'Apps, Services und Homelab-Status an einem Ort.', dashboardActions) + (isAdmin()?`<div class="dashboard-toolbar"><span class="edit-hint">${esc(editHint)}</span></div>`:'') +
    `<div class="dashboard-grid" id="dashboardGrid">${widgets.length ? widgets.map(widgetShell).join('') : `<div class="empty-dashboard"><h3>Dein Dashboard ist bereit</h3><p>${isAdmin()?'Füge Apps, Statuskarten, RSS oder Add-on-Widgets hinzu.':'Für deinen Benutzer sind noch keine Dashboard-Widgets freigegeben.'}</p>${isAdmin()?'<button class="btn primary" id="emptyAddWidget">+ Erstes Widget</button>':''}</div>`}</div>`;

    $('#editLayoutBtn')?.addEventListener('click', beginLayoutEdit);
    $('#cancelLayoutBtn')?.addEventListener('click', ()=>discardLayoutEdit());
    $('#saveLayoutBtn')?.addEventListener('click', commitLayoutEdit);
    $('#addWidgetBtn')?.addEventListener('click', openWidgetPicker);
    $('#emptyAddWidget')?.addEventListener('click', ()=>isAdmin()&&openWidgetPicker());
    $$('.widget-remove', appRoot).forEach(btn => btn.addEventListener('click', async e => {
      e.stopPropagation(); if (!confirm('Widget vom Dashboard entfernen?')) return;
      try { const d = await api('widgets/delete', {body:{id:btn.dataset.id}}); state.boot.widgets = d.widgets; renderDashboard(); } catch(err){ toast(err.message,'error'); }
    }));
    $$('.widget-settings', appRoot).forEach(btn => btn.addEventListener('click', e => {
      e.stopPropagation(); const widget=(state.boot.widgets||[]).find(w=>w.id===btn.dataset.id); if(widget) openWidgetSettings(widget);
    }));
    if (state.editMode) enableGridInteractions();
    loadWidgetData();
  }

  function widgetShell(widget) {
    const title = widget.title || widgetTitle(widget);
    const typeClass = `widget-type-${String(widget.type || 'unknown').replace(/[^a-z0-9_-]/gi,'-')}`;
    const sizeClass = widget.type === 'app' ? (widget.w <= 1 ? 'app-widget-xs' : widget.w === 2 ? 'app-widget-sm' : 'app-widget-lg') : '';
    const settingsButton = isAdmin() && ['app','homeassistant-entities'].includes(widget.type) ? `<button class="widget-mini-btn widget-settings" data-id="${attr(widget.id)}" title="Einstellungen">⚙</button>` : '';
    return `<section class="widget ${typeClass} ${sizeClass}" data-widget-id="${attr(widget.id)}" style="--x:${Number(widget.x)||0};--y:${Number(widget.y)||0};--w:${Number(widget.w)||3};--h:${Number(widget.h)||2}">
      <div class="widget-head"><span class="widget-drag-handle" title="Verschieben">⠿</span><span class="widget-title">${esc(title)}</span><span class="widget-head-spacer"></span><div class="widget-menu">${settingsButton}${isAdmin()?`<button class="widget-mini-btn widget-remove" data-id="${attr(widget.id)}" title="Entfernen">×</button>`:''}</div></div>
      <div class="widget-body" data-widget-body="${attr(widget.id)}"><div class="widget-loading">Lädt…</div></div><span class="widget-resize" title="Größe ändern"></span>
    </section>`;
  }

  function widgetTitle(widget) {
    if (widget.type === 'app') {
      const app = (state.boot.apps || []).find(a => a.id === widget.config?.app_id); return app?.name || 'App';
    }
    if (widget.type === 'clock') return 'Zeit';
    if (widget.type === 'note') return 'Notiz';
    if (widget.type === 'rss') return 'News';
    if (widget.type === 'ipmanager-summary') return 'IP Manager';
    if (widget.type === 'integration-summary') {
      const i = (state.boot.integrations || []).find(x => x.id === widget.config?.integration_id); return i?.name || 'Service';
    }
    if (widget.type === 'homeassistant-entities') {
      const i = (state.boot.integrations || []).find(x => x.id === widget.config?.integration_id); return widget.title || i?.name || 'Home Assistant';
    }
    return 'Widget';
  }

  function integrationRefreshMs(widget) {
    const integration=(state.boot.integrations||[]).find(i=>i.id===widget.config?.integration_id);
    const seconds=Math.max(5,Math.min(300,Number(integration?.config?.refresh_interval||10)));
    return seconds*1000;
  }

  async function loadWidgetData() {
    for (const widget of state.boot.widgets || []) {
      if (['integration-summary','homeassistant-entities'].includes(widget.type)) {
        // Paint the last server-side snapshot first, then refresh silently in the background.
        loadCachedWidget(widget).finally(() => loadOneWidget(widget, true));
      } else {
        loadOneWidget(widget);
      }
      if (['integration-summary','homeassistant-entities','rss','ipmanager-summary'].includes(widget.type)) {
        const interval = widget.type === 'rss' ? 300000 : (['integration-summary','homeassistant-entities'].includes(widget.type) ? integrationRefreshMs(widget) : 60000);
        const timer = setInterval(() => loadOneWidget(widget, true), interval);
        state.widgetRefreshTimers.set(widget.id, timer);
      }
    }
  }

  async function loadCachedWidget(widget) {
    const body=$(`[data-widget-body="${CSS.escape(widget.id)}"]`);
    if(!body)return;
    try {
      const result=await api('widgets/data',{params:{id:widget.id,cached:'1'}});
      const data=result.data||{};
      if(data.cached || (data.history?.a||[]).length || (data.history?.b||[]).length) renderWidgetData(body,widget,data);
    } catch (_) { /* cache is optional */ }
  }

  async function loadOneWidget(widget, silent = false) {
    const body = $(`[data-widget-body="${CSS.escape(widget.id)}"]`);
    if (!body) return;
    if (state.widgetInFlight.get(widget.id)) return;
    try {
      if (widget.type === 'clock') {
        renderClock(body); return;
      }
      if (widget.type === 'note') {
        body.innerHTML = `<div class="note-widget">${esc(widget.config?.text || 'Noch keine Notiz.')}</div>`; return;
      }
      state.widgetInFlight.set(widget.id,true);
      const result = await api('widgets/data', {params:{id:widget.id}});
      renderWidgetData(body, widget, result.data || {});
    } catch (e) {
      if (!silent) body.innerHTML = `<div class="widget-error">${esc(e.message)}</div>`;
    } finally {
      state.widgetInFlight.delete(widget.id);
    }
  }

  function renderClock(body) {
    const tick = () => {
      const now = new Date();
      body.innerHTML = `<div class="clock-widget"><div><div class="clock-time">${new Intl.DateTimeFormat('de-DE',{hour:'2-digit',minute:'2-digit'}).format(now)}</div><div class="clock-date">${new Intl.DateTimeFormat('de-DE',{weekday:'long',day:'2-digit',month:'long'}).format(now)}</div></div></div>`;
    };
    tick(); const timer = setInterval(tick, 30000); state.widgetRefreshTimers.set(`clock-${Math.random()}`, timer);
  }

  function renderWidgetData(body, widget, data) {
    if (data.kind === 'app') {
      const app = data.app;
      if (!app) { body.innerHTML = '<div class="widget-error">App wurde entfernt.</div>'; return; }
      const size = widget.w <= 1 ? 'xs' : widget.w === 2 ? 'sm' : 'lg';
      const requested = widget.config?.layout || 'auto';
      const layout = requested === 'auto' ? (widget.w <= 2 ? 'vertical' : (widget.h >= 2 && widget.w <= 3 ? 'vertical' : 'horizontal')) : requested;
      body.innerHTML = `<a class="app-widget app-widget-${size} app-layout-${attr(layout)}" href="${attr(app.url)}" target="_blank" rel="noopener" title="${attr(app.name)} öffnen">${appIcon(app)}<div class="app-widget-copy"><div class="app-widget-name">${esc(app.name)}</div><div class="app-widget-meta">${esc(app.category || hostOf(app.url))}</div></div><span class="app-widget-open" aria-hidden="true">↗</span></a>`;
      return;
    }
    if (data.kind === 'ipmanager') {
      body.innerHTML = `<div class="metric-grid"><div class="metric"><div class="metric-label">Netze</div><div class="metric-value">${fmt(data.networks)}</div></div><div class="metric"><div class="metric-label">Geräte</div><div class="metric-value">${fmt(data.devices)}</div></div></div>`;
      return;
    }
    if (data.kind === 'rss') {
      const items = data.items || [];
      body.innerHTML = `<div class="rss-list">${items.length ? items.map(i => `<a class="rss-item" href="${attr(i.link || '#')}" ${i.link ? 'target="_blank" rel="noopener"' : ''}><div><div class="rss-title">${esc(i.title)}</div>${i.description ? `<div class="rss-desc">${esc(i.description)}</div>` : ''}</div><div class="rss-date">${esc(fmtDate(i.date))}</div></a>`).join('') : '<div class="widget-loading">Keine Meldungen.</div>'}</div>`;
      return;
    }
    if (data.kind === 'homeassistant') {
      body.innerHTML = homeAssistantWidgetHtml(widget, data);
      bindHomeAssistantActions(body, widget, data);
      return;
    }
    if (data.kind === 'integration') {
      if (data.history) hydrateMetricHistory(data.integration || {}, data.history);
      else updateMetricHistory(data.integration || {}, data.summary || {});
      body.innerHTML = integrationSummaryHtml(data.integration || {}, data.summary || {});
      bindIntegrationActions(body, widget, data.integration || {});
      return;
    }
    body.innerHTML = `<pre style="font-size:10px;overflow:auto">${esc(JSON.stringify(data,null,2))}</pre>`;
  }

  function haIconSvg(entity){
    const domain=entity?.domain||String(entity?.entity_id||'').split('.')[0];
    const dc=String(entity?.device_class||'').toLowerCase();
    const common='viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"';
    if(dc==='battery')return `<svg ${common}><rect x="3" y="7" width="16" height="10" rx="2"/><path d="M21 10v4"/><path d="M6 10h7"/></svg>`;
    if(['power','energy'].includes(dc))return `<svg ${common}><path d="M13 2 5 14h6l-1 8 9-13h-6z"/></svg>`;
    if(dc==='temperature')return `<svg ${common}><path d="M10 14.8V5a2 2 0 1 1 4 0v9.8a4 4 0 1 1-4 0Z"/><path d="M12 11v6"/></svg>`;
    if(domain==='light')return `<svg ${common}><path d="M9 18h6"/><path d="M10 22h4"/><path d="M8.4 14.5A6 6 0 1 1 15.6 14.5c-.9.8-1.6 1.6-1.8 2.5h-3.6c-.2-.9-.9-1.7-1.8-2.5Z"/></svg>`;
    if(domain==='switch')return `<svg ${common}><path d="M12 2v10"/><path d="M7.1 5.1a8 8 0 1 0 9.8 0"/></svg>`;
    if(domain==='cover')return `<svg ${common}><rect x="4" y="3" width="16" height="18" rx="2"/><path d="M4 8h16M4 13h16M4 18h16"/></svg>`;
    return `<svg ${common}><path d="M4 18V9M9 18V5M14 18v-7M19 18V3"/></svg>`;
  }

  function haStateLabel(entity){
    const domain=entity?.domain||'';const state=String(entity?.state??'unknown');
    if(domain==='switch'||domain==='light'){if(state==='on'){if(domain==='light'&&Number.isFinite(Number(entity?.brightness)))return `An · ${Math.round(Number(entity.brightness)/255*100)}%`;return 'An';}if(state==='off')return 'Aus';}
    if(domain==='cover'){
      const pos=Number(entity?.current_position);const suffix=Number.isFinite(pos)?` · ${Math.round(pos)}%`:'';
      const names={open:'Offen',closed:'Geschlossen',opening:'Öffnet',closing:'Schließt'};return `${names[state]||state}${suffix}`;
    }
    return `${state}${entity?.unit?` ${entity.unit}`:''}`;
  }

  function homeAssistantWidgetHtml(widget,data){
    const entities=data.entities||[];const display=data.display||widget.config?.display||'tiles';const icons=data.show_icons!==false;const controls=data.show_controls!==false;
    if(!entities.length)return '<div class="widget-loading">Keine Entitäten ausgewählt.</div>';
    return `<div class="ha-entity-grid ha-display-${attr(display)}">${entities.map(e=>{
      const unavailable=['unavailable','unknown'].includes(String(e.state));const numeric=Number(e.state);const percent=e.unit==='%'&&Number.isFinite(numeric);
      const domain=e.domain||String(e.entity_id||'').split('.')[0];
      let action='';
      if(controls&&(domain==='switch'||domain==='light'))action=`<button class="ha-toggle ${e.state==='on'?'on':''}" data-ha-action="toggle" data-ha-entity="${attr(e.entity_id)}" title="${e.state==='on'?'Ausschalten':'Einschalten'}" aria-label="Schalten"><span></span></button>`;
      if(controls&&domain==='cover')action=`<div class="ha-cover-actions"><button data-ha-action="open" data-ha-entity="${attr(e.entity_id)}" title="Öffnen">↑</button><button data-ha-action="stop" data-ha-entity="${attr(e.entity_id)}" title="Stop">■</button><button data-ha-action="close" data-ha-entity="${attr(e.entity_id)}" title="Schließen">↓</button></div>`;
      return `<div class="ha-entity ${unavailable?'unavailable':''} ha-domain-${attr(domain)}">${icons?`<div class="ha-entity-icon">${haIconSvg(e)}</div>`:''}<div class="ha-entity-copy"><div class="ha-entity-name" title="${attr(e.entity_id)}">${esc(e.name||e.entity_id)}</div><div class="ha-entity-state">${esc(haStateLabel(e))}</div>${percent?`<div class="ha-percent"><span style="width:${Math.max(0,Math.min(100,numeric))}%"></span></div>`:''}</div>${action}</div>`;
    }).join('')}</div>`;
  }

  function bindHomeAssistantActions(body,widget,data){
    $$('[data-ha-action]',body).forEach(btn=>btn.onclick=async e=>{
      e.preventDefault();e.stopPropagation();const integrationId=widget.config?.integration_id;if(!integrationId)return;
      const payload={id:integrationId,entity_id:btn.dataset.haEntity,action:btn.dataset.haAction};
      btn.disabled=true;
      try{await api('homeassistant/action',{body:payload});await new Promise(r=>setTimeout(r,180));await loadOneWidget(widget,true);}catch(err){btn.disabled=false;toast(err.message,'error')}
    });
  }

  async function openHomeAssistantWidgetModal(integrationId,existingWidget=null){
    const integration=(state.boot.integrations||[]).find(i=>i.id===integrationId&&i.type==='homeassistant');
    if(!integration){toast('Home Assistant Integration nicht gefunden.','error');return;}
    showModal(existingWidget?'Home Assistant Widget bearbeiten':'Home Assistant Widget','Lade verfügbare Entitäten…','');
    let entities=[];
    try{const d=await api('homeassistant/entities',{body:{id:integrationId}});entities=d.entities||[];}catch(e){closeModal();toast(e.message,'error');return;}
    const selected=new Set((existingWidget?.config?.entity_ids||[]).map(String));
    const display=existingWidget?.config?.display||'tiles';const showIcons=existingWidget?.config?.show_icons!==false;const showControls=existingWidget?.config?.show_controls!==false;
    const entityRows=entities.map(e=>`<label class="ha-picker-row" data-ha-search="${attr(`${e.name} ${e.entity_id} ${e.domain}`.toLowerCase())}"><input type="checkbox" data-ha-pick="${attr(e.entity_id)}" ${selected.has(e.entity_id)?'checked':''}><span class="ha-picker-icon">${haIconSvg(e)}</span><span class="ha-picker-copy"><strong>${esc(e.name)}</strong><small>${esc(e.entity_id)} · ${esc(haStateLabel(e))}</small></span><span class="ha-picker-domain">${esc(e.domain)}</span></label>`).join('');
    showModal(existingWidget?'Home Assistant Widget bearbeiten':'Home Assistant Widget','Bis zu 8 Sensoren, Schalter, Lichter oder Cover auswählen.',`<div class="field-row"><label>Titel (optional)</label><input id="haWidgetTitle" value="${attr(existingWidget?.title||'') }" placeholder="PV & Energie"></div><div class="form-grid"><div class="field-row"><label>Darstellung</label><select id="haWidgetDisplay"><option value="tiles" ${display==='tiles'?'selected':''}>Kacheln</option><option value="compact" ${display==='compact'?'selected':''}>Kompakt</option></select></div><div class="field-row"><label>Entitäten suchen</label><input id="haEntitySearch" placeholder="Batterie, PV, light…"></div></div><div class="ha-picker-options"><label><input id="haShowIcons" type="checkbox" ${showIcons?'checked':''}> Icons anzeigen</label><label><input id="haShowControls" type="checkbox" ${showControls?'checked':''}> Steuerung anzeigen</label><span id="haSelectedCount">${selected.size}/8 ausgewählt</span></div><div class="ha-picker-list">${entityRows||'<div class="widget-loading">Keine unterstützten Entitäten gefunden.</div>'}</div>`,`<button class="btn" data-close-modal>Abbrechen</button><button class="btn primary" id="saveHaWidget">${existingWidget?'Speichern':'Hinzufügen'}</button>`,modal=>{
      const search=$('#haEntitySearch',modal),count=$('#haSelectedCount',modal);const checks=$$('[data-ha-pick]',modal);
      const updateCount=()=>{const n=checks.filter(c=>c.checked).length;count.textContent=`${n}/8 ausgewählt`;checks.forEach(c=>c.disabled=!c.checked&&n>=8)};
      checks.forEach(c=>c.onchange=updateCount);updateCount();
      search.oninput=()=>{const q=search.value.trim().toLowerCase();$$('[data-ha-search]',modal).forEach(row=>row.hidden=!!q&&!row.dataset.haSearch.includes(q))};
      $('#saveHaWidget',modal).onclick=async()=>{const ids=checks.filter(c=>c.checked).map(c=>c.dataset.haPick).slice(0,8);if(!ids.length){toast('Wähle mindestens eine Entität.','error');return;}const config={integration_id:integrationId,entity_ids:ids,display:$('#haWidgetDisplay',modal).value,show_icons:$('#haShowIcons',modal).checked,show_controls:$('#haShowControls',modal).checked};const title=$('#haWidgetTitle',modal).value.trim();try{if(existingWidget){const d=await api('widgets/update',{body:{id:existingWidget.id,title,config}});state.boot.widgets=d.widgets;closeModal();renderDashboard();toast('Home Assistant Widget gespeichert.');}else{closeModal();const w=ids.length===1?2:(ids.length<=4?4:5),h=ids.length<=2?1:(ids.length<=4?2:3);await createWidget({type:'homeassistant-entities',title,config,w,h});}}catch(e){toast(e.message,'error')}};
    });
  }

  function integrationOption(integration,key,def=true){const cfg=integration?.config||{};return Object.prototype.hasOwnProperty.call(cfg,key)?Boolean(cfg[key]):def;}
  function historyBucket(key){if(!state.metricHistory.has(key))state.metricHistory.set(key,{last:null,a:[],b:[]});return state.metricHistory.get(key);}
  function hydrateMetricHistory(integration,history){
    const metric=history?.metric||'';if(!metric)return;
    const key=`${integration.id}:${metric}`;
    const existing=historyBucket(key);
    existing.a=(history.a||[]).map(Number).filter(Number.isFinite);
    existing.b=(history.b||[]).map(Number).filter(Number.isFinite);
    existing.last=null;
  }
  function updateMetricHistory(integration,summary){
    const type=integration.type||'', now=Date.now();
    if(type==='pihole'||type==='adguardhome'){
      const h=historyBucket(`${integration.id}:dns`), current={t:now,a:Number(summary.queries||0),b:Number(summary.blocked||0)};
      if(h.last){const mins=Math.max((current.t-h.last.t)/60000,.1);h.a.push(Math.max(0,(current.a-h.last.a)/mins));h.b.push(Math.max(0,(current.b-h.last.b)/mins));if(h.a.length>30)h.a.shift();if(h.b.length>30)h.b.shift();}h.last=current;
    }
    if(type==='opnsense'&&summary.traffic){
      const rawRx=summary.traffic.rx_bytes,rawTx=summary.traffic.tx_bytes;
      if(rawRx!==null&&rawRx!==undefined&&rawTx!==null&&rawTx!==undefined){
        const rx=Number(rawRx),tx=Number(rawTx);if(Number.isFinite(rx)&&Number.isFinite(tx)){
          const h=historyBucket(`${integration.id}:traffic`),current={t:now,a:rx,b:tx};
          if(h.last){const sec=Math.max((current.t-h.last.t)/1000,1);const da=current.a-h.last.a,db=current.b-h.last.b;if(da>=0&&db>=0){h.a.push(da/sec);h.b.push(db/sec);if(h.a.length>30)h.a.shift();if(h.b.length>30)h.b.shift();}}h.last=current;
        }
      }
    }
  }
  function sparkline(values,cls=''){let nums=(values||[]).filter(Number.isFinite);if(!nums.length)return `<div class="sparkline-empty">Startet…</div>`;if(nums.length===1)nums=[nums[0],nums[0]];const min=Math.min(...nums),max=Math.max(...nums),range=Math.max(max-min,1);const pts=nums.map((v,i)=>`${(i/(nums.length-1))*100},${28-((v-min)/range)*24}`).join(' ');return `<svg class="sparkline ${attr(cls)}" viewBox="0 0 100 30" preserveAspectRatio="none" aria-hidden="true"><polyline points="${pts}" fill="none" vector-effect="non-scaling-stroke"/></svg>`;}
  function fmtRate(v){const n=Number(v||0);if(n>=125000000)return `${fmt(n*8/1e9)} Gbit/s`;if(n>=125000)return `${fmt(n*8/1e6)} Mbit/s`;if(n>=125)return `${fmt(n*8/1e3)} kbit/s`;return `${fmt(n*8)} bit/s`;}

  function integrationSummaryHtml(integration, summary) {
    const type = integration.type || '';
    if (type === 'pihole' || type === 'adguardhome') {
      const active = summary.protection !== false;
      const showStats=integrationOption(integration,'show_stats',true),showGraph=integrationOption(integration,'show_graph',true),showControls=integrationOption(integration,'show_controls',true),showClients=type==='pihole'&&integrationOption(integration,'show_clients',true),showRecent=integrationOption(integration,'show_recent_blocked',false);
      const h=historyBucket(`${integration.id}:dns`);const graph=showGraph?`<div class="mini-graph-grid"><div><div class="mini-graph-label"><span>Queries/min</span><strong>${h.a.length?fmt(h.a.at(-1)):''}</strong></div>${sparkline(h.a,'queries')}</div><div><div class="mini-graph-label"><span>Blocked/min</span><strong>${h.b.length?fmt(h.b.at(-1)):''}</strong></div>${sparkline(h.b,'blocked')}</div></div>`:'';
      const metrics=showStats?`<div class="metric-grid dns-metrics"><div class="metric"><div class="metric-label">Queries</div><div class="metric-value">${fmt(summary.queries)}</div></div><div class="metric"><div class="metric-label">Blocked</div><div class="metric-value">${fmt(summary.blocked_percent)}%</div></div><div class="metric"><div class="metric-label">Status</div><div class="metric-value">${active ? 'Active' : 'Paused'}</div></div>${showClients?`<div class="metric"><div class="metric-label">Clients</div><div class="metric-value">${fmt(summary.clients)}</div></div>`:''}</div>`:'';
      const recentRows=Array.isArray(summary.recent_blocked)?summary.recent_blocked:[];
      const recent=showRecent?`<div class="recent-blocked"><div class="recent-blocked-head"><span>Letzte Blockierungen</span>${summary.recent_blocked_error?'<small>Query-Log nicht verfügbar</small>':''}</div>${recentRows.length?recentRows.map(row=>`<div class="recent-blocked-row"><span class="recent-blocked-domain" title="${attr(row.domain||'')}">${esc(row.domain||'')}</span><span class="recent-blocked-time">${esc(fmtTime(row.time))}</span></div>`).join(''):`<div class="recent-blocked-empty">${summary.recent_blocked_error?'Keine Daten verfügbar.':'Noch keine Blockierungen im geladenen Zeitraum.'}</div>`}</div>`:'';
      const controls=showControls?`<div class="service-actions"><button class="service-action ${active?'':'primary'}" data-integration-action="protection_enable" ${active?'disabled':''}>Fortsetzen</button><button class="service-action" data-integration-action="protection_pause_300">5 Min Pause</button><button class="service-action danger" data-integration-action="protection_disable" ${!active?'disabled':''}>Anhalten</button></div>`:'';
      return `<div class="dns-widget"><div class="service-heading"><span class="status-dot ${active?'':'paused'}"></span><span class="service-name">${esc(integration.name)}</span><span class="service-state ${active?'active':'paused'}">${active?'Schutz aktiv':'Schutz pausiert'}</span></div>${metrics}${graph}${recent}${controls}</div>`;
    }
    if (type === 'opnsense') {
      const cfg=integration.config||{}, metrics=[];
      if(integrationOption(integration,'show_system',true))metrics.push(['Firewall','Online']);
      if(integrationOption(integration,'show_gateway',true)&&summary.gateway){const g=summary.gateway;metrics.push(['Gateway',g.status||'unknown']);if(g.delay)metrics.push(['Latenz',String(g.delay)]);}
      if(integrationOption(integration,'show_memory',true)&&summary.memory_percent!==null&&summary.memory_percent!==undefined)metrics.push(['RAM',`${fmt(summary.memory_percent)}%`]);
      if(integrationOption(integration,'show_wireguard',false)&&summary.wireguard?.available)metrics.push(['WireGuard',summary.wireguard.running?'Online':'Offline']);
      const trafficEnabled=integrationOption(integration,'show_traffic',true)&&summary.traffic;const h=historyBucket(`${integration.id}:traffic`);const rx=h.a.length?h.a.at(-1):null,tx=h.b.length?h.b.at(-1):null;
      const traffic=trafficEnabled?`<div class="opn-traffic"><div class="mini-graph-label"><span>${esc(summary.traffic.label||summary.traffic.interface||cfg.traffic_interface||'WAN')} Traffic</span><strong>${rx===null||tx===null?'Messung startet…':`↓ ${fmtRate(rx)} · ↑ ${fmtRate(tx)}`}</strong></div><div class="traffic-sparks">${sparkline(h.a,'rx')}${sparkline(h.b,'tx')}</div></div>`:'';
      return `<div class="opn-widget"><div class="service-heading"><span class="status-dot"></span><span class="service-name">${esc(integration.name)}</span><span class="service-state active">API online</span></div><div class="metric-grid opn-metrics">${metrics.map(([k,v])=>`<div class="metric"><div class="metric-label">${esc(k)}</div><div class="metric-value">${esc(String(v))}</div></div>`).join('')}</div>${traffic}</div>`;
    }
    if (type === 'generic-api') {
      const values = Object.entries(summary.values || {}).slice(0,4);
      return `<div class="service-heading"><span class="status-dot"></span><span class="service-name">${esc(integration.name)}</span></div><div class="metric-grid">${values.map(([k,v]) => `<div class="metric"><div class="metric-label">${esc(k)}</div><div class="metric-value">${esc(String(v))}</div></div>`).join('') || '<div class="metric"><div class="metric-label">Status</div><div class="metric-value">Online</div></div>'}</div>`;
    }
    return `<div class="service-heading"><span class="status-dot"></span><span class="service-name">${esc(integration.name || summary.service || 'Service')}</span></div><pre style="font-size:10px;overflow:auto">${esc(JSON.stringify(summary,null,2))}</pre>`;
  }

  function bindIntegrationActions(body, widget, integration) {
    $$('[data-integration-action]', body).forEach(button => button.addEventListener('click', async e => {
      e.preventDefault(); e.stopPropagation();
      const action = button.dataset.integrationAction;
      const label = button.textContent;
      $$('[data-integration-action]', body).forEach(b => b.disabled = true);
      button.textContent = '…';
      try {
        const result = await api('integrations/action', {body:{id:integration.id, action}});
        state.boot.integrations = result.integrations || state.boot.integrations;
        if(result.history)hydrateMetricHistory(result.integration||integration,result.history);
        if(result.summary){body.innerHTML=integrationSummaryHtml(result.integration||integration,result.summary);bindIntegrationActions(body,widget,result.integration||integration);}
        toast(action === 'protection_enable' ? 'Schutz fortgesetzt.' : action === 'protection_pause_300' ? 'Schutz für 5 Minuten pausiert.' : 'Schutz angehalten.');
      } catch (err) {
        toast(err.message, 'error');
        button.textContent = label;
        await loadOneWidget(widget, true);
      }
    }));
  }

  function enableGridInteractions() {
    const grid = $('#dashboardGrid'); if (!grid || matchMedia('(max-width:760px)').matches) return;
    const widgets = state.boot.widgets || [];
    $$('.widget', grid).forEach(el => {
      const id = el.dataset.widgetId; const widget = widgets.find(w => w.id === id); if (!widget) return;
      $('.widget-drag-handle', el)?.addEventListener('pointerdown', ev => startDrag(ev, el, widget, grid));
      $('.widget-resize', el)?.addEventListener('pointerdown', ev => startResize(ev, el, widget, grid));
    });
  }

  function gridMetrics(grid) {
    const style = getComputedStyle(grid); const gap = parseFloat(style.columnGap) || 16; const row = parseFloat(style.gridAutoRows) || 86; const rect = grid.getBoundingClientRect();
    return { rect, gap, col: (rect.width - gap * 11) / 12, row, unitY: row + gap };
  }
  const intersects = (a,b) => a.id !== b.id && a.x < b.x+b.w && a.x+a.w > b.x && a.y < b.y+b.h && a.y+a.h > b.y;
  function positionFree(candidate, widgets, ignoreId) { return !widgets.some(w => w.id !== ignoreId && intersects(candidate,w)); }
  function setWidgetStyle(el,w){el.style.setProperty('--x',w.x);el.style.setProperty('--y',w.y);el.style.setProperty('--w',w.w);el.style.setProperty('--h',w.h);}
  function markLayoutDirty(){state.layoutDirty=true;const btn=$('#saveLayoutBtn');if(btn)btn.disabled=false;}
  function startDrag(ev, el, widget, grid) {
    ev.preventDefault(); ev.stopPropagation(); el.setPointerCapture?.(ev.pointerId); el.classList.add('dragging');
    const m=gridMetrics(grid), sx=ev.clientX, sy=ev.clientY, original={x:widget.x,y:widget.y};
    const move=e=>{const dx=Math.round((e.clientX-sx)/(m.col+m.gap));const dy=Math.round((e.clientY-sy)/m.unitY);widget.x=Math.max(0,Math.min(12-widget.w,original.x+dx));widget.y=Math.max(0,original.y+dy);setWidgetStyle(el,widget)};
    const up=e=>{el.classList.remove('dragging');el.releasePointerCapture?.(ev.pointerId);window.removeEventListener('pointermove',move);window.removeEventListener('pointerup',up);if(!positionFree(widget,state.boot.widgets,widget.id)){widget.x=original.x;widget.y=original.y;setWidgetStyle(el,widget);toast('Dort ist bereits ein anderes Widget.','error');return;}if(widget.x!==original.x||widget.y!==original.y)markLayoutDirty();};
    window.addEventListener('pointermove',move);window.addEventListener('pointerup',up,{once:true});
  }
  function startResize(ev, el, widget, grid) {
    ev.preventDefault();ev.stopPropagation();el.setPointerCapture?.(ev.pointerId);el.classList.add('dragging');const m=gridMetrics(grid),sx=ev.clientX,sy=ev.clientY,original={w:widget.w,h:widget.h};
    const move=e=>{const dw=Math.round((e.clientX-sx)/(m.col+m.gap));const dh=Math.round((e.clientY-sy)/m.unitY);const minW=widget.type==='app'?1:2;widget.w=Math.max(minW,Math.min(12-widget.x,original.w+dw));widget.h=Math.max(1,Math.min(8,original.h+dh));setWidgetStyle(el,widget);el.classList.toggle('app-widget-xs',widget.type==='app'&&widget.w<=1);el.classList.toggle('app-widget-sm',widget.type==='app'&&widget.w===2);el.classList.toggle('app-widget-lg',widget.type==='app'&&widget.w>=3)};
    const up=()=>{el.classList.remove('dragging');window.removeEventListener('pointermove',move);window.removeEventListener('pointerup',up);if(!positionFree(widget,state.boot.widgets,widget.id)){widget.w=original.w;widget.h=original.h;setWidgetStyle(el,widget);toast('Dort ist nicht genug Platz.','error');return;}if(widget.w!==original.w||widget.h!==original.h){markLayoutDirty();loadOneWidget(widget,true);}};
    window.addEventListener('pointermove',move);window.addEventListener('pointerup',up,{once:true});
  }

  function openWidgetSettings(widget){
    if(widget.type==='app'){
      const current=widget.config?.layout||'auto';
      showModal('App-Widget Darstellung','Die Darstellung gilt nur für dieses Dashboard-Widget.',`<div class="field-row"><label>Layout</label><select id="appWidgetLayout"><option value="auto" ${current==='auto'?'selected':''}>Automatisch nach Größe</option><option value="vertical" ${current==='vertical'?'selected':''}>Icon oben · Text darunter</option><option value="horizontal" ${current==='horizontal'?'selected':''}>Icon links · Text daneben</option><option value="icon" ${current==='icon'?'selected':''}>Nur Icon</option></select></div><div class="widget-layout-preview"><span>1×1 wird bei Bedarf automatisch sehr kompakt dargestellt.</span></div>`,`<button class="btn" data-close-modal>Abbrechen</button><button class="btn primary" id="saveWidgetSettings">Speichern</button>`,modal=>{$('#saveWidgetSettings',modal).onclick=async()=>{const config={...(widget.config||{}),layout:$('#appWidgetLayout',modal).value};try{const d=await api('widgets/update',{body:{id:widget.id,config}});state.boot.widgets=d.widgets;closeModal();renderDashboard();toast('Darstellung gespeichert.');}catch(e){toast(e.message,'error')}}});
      return;
    }
    if(widget.type==='homeassistant-entities'){
      openHomeAssistantWidgetModal(widget.config?.integration_id, widget);
    }
  }

  function openWidgetPicker() {
    const integrations = state.boot.integrations || []; const apps = state.boot.apps || []; const catalog = state.boot.widgetCatalog || [];
    const installedTypes = new Set(catalog.map(c=>c.type));
    const cards = [
      apps.length ? {key:'app',name:'App Shortcut',desc:'Eine vorhandene App als Schnellstart.',icon:'A'} : null,
      {key:'clock',name:'Clock',desc:'Uhrzeit und Datum.',icon:'C'},
      {key:'note',name:'Note',desc:'Kurze Notiz direkt auf dem Dashboard.',icon:'N'},
      installedTypes.has('rss') ? {key:'rss',name:'RSS / Atom',desc:'News und Feeds anzeigen.',icon:'R'} : null,
      installedTypes.has('ipmanager-summary') ? {key:'ipmanager-summary',name:'IP Manager',desc:'Netze und dokumentierte Geräte.',icon:'IP'} : null,
      ...integrations.filter(i=>i.enabled).map(i=>({key:'integration:'+i.id,name:i.name,desc:i.type==='homeassistant'?'Sensoren, Schalter, Lichter und Cover auswählen.':`${i.type} Status`,icon:initials(i.name)})),
    ].filter(Boolean);
    showModal('Widget hinzufügen','Wähle, was auf dem Dashboard erscheinen soll.', `<div class="widget-picker">${cards.map(c=>`<button class="widget-choice" type="button" data-widget-choice="${attr(c.key)}"><strong>${esc(c.name)}</strong><span>${esc(c.desc)}</span></button>`).join('') || '<p>Installiere zuerst Pakete im PenguHub oder lege Apps an.</p>'}</div>`, '', modal => {
      $$('[data-widget-choice]',modal).forEach(btn=>btn.addEventListener('click',()=>configureWidgetChoice(btn.dataset.widgetChoice)));
    });
  }

  function configureWidgetChoice(key) {
    closeModal();
    if (key === 'clock' || key === 'ipmanager-summary') { createWidget({type:key,w:key==='clock'?3:4,h:2}); return; }
    if (key.startsWith('integration:')) {
      const id=key.split(':')[1];const integration=(state.boot.integrations||[]).find(i=>i.id===id);if(integration?.type==='homeassistant'){openHomeAssistantWidgetModal(id);return;}const catalog=(state.boot.widgetCatalog||[]).find(c=>c.type==='integration-summary'&&c.integrationType===integration?.type);const size=catalog?.defaultSize||[4,2];createWidget({type:'integration-summary',title:integration?.name||'',config:{integration_id:id},w:size[0],h:size[1]});return;
    }
    if (key === 'app') {
      const opts=(state.boot.apps||[]).map(a=>`<option value="${attr(a.id)}">${esc(a.name)}</option>`).join('');
      showModal('App Shortcut','Wähle App und Darstellung.',`<div class="form-grid"><div class="field-row full"><label>App</label><select id="widgetAppId">${opts}</select></div><div class="field-row full"><label>Darstellung</label><select id="widgetAppLayout"><option value="vertical">Icon oben · Text darunter</option><option value="horizontal">Icon links · Text daneben</option><option value="auto">Automatisch nach Größe</option><option value="icon">Nur Icon</option></select></div></div>`,`<button class="btn" data-close-modal>Abbrechen</button><button class="btn primary" id="saveWidgetConfig">Hinzufügen</button>`,modal=>{$('#saveWidgetConfig',modal).onclick=()=>{const id=$('#widgetAppId',modal).value,layout=$('#widgetAppLayout',modal).value;closeModal();createWidget({type:'app',config:{app_id:id,layout},w:1,h:1});}});return;
    }
    if (key === 'note') {
      showModal('Notiz','Kurzer Text für dein Dashboard.',`<div class="field-row"><label>Titel (optional)</label><input id="widgetTitle"></div><div class="field-row"><label>Text</label><textarea id="widgetText" placeholder="Was willst du im Blick behalten?"></textarea></div>`,`<button class="btn" data-close-modal>Abbrechen</button><button class="btn primary" id="saveWidgetConfig">Hinzufügen</button>`,modal=>{$('#saveWidgetConfig',modal).onclick=()=>{const title=$('#widgetTitle',modal).value,text=$('#widgetText',modal).value;closeModal();createWidget({type:'note',title,config:{text},w:3,h:2});}});return;
    }
    if (key === 'rss') {
      showModal('RSS / Atom Feed','Feed wird serverseitig geladen.',`<div class="field-row"><label>Titel</label><input id="widgetTitle" placeholder="Tech News"></div><div class="field-row"><label>Feed URL</label><input id="feedUrl" type="url" placeholder="https://example.org/feed.xml"></div><div class="field-row"><label>Max. Meldungen</label><input id="feedLimit" type="number" min="1" max="15" value="6"></div>`,`<button class="btn" data-close-modal>Abbrechen</button><button class="btn primary" id="saveWidgetConfig">Hinzufügen</button>`,modal=>{$('#saveWidgetConfig',modal).onclick=()=>{const title=$('#widgetTitle',modal).value,feed_url=$('#feedUrl',modal).value,limit=Number($('#feedLimit',modal).value)||6;if(!feed_url){toast('Feed URL fehlt.','error');return;}closeModal();createWidget({type:'rss',title,config:{feed_url,limit,verify_tls:true},w:6,h:3});}});
    }
  }

  async function createWidget(payload){try{const d=await api('widgets/create',{body:payload});state.boot.widgets=d.widgets;if(state.view==='dashboard')renderDashboard();else render();toast('Widget hinzugefügt.');}catch(e){toast(e.message,'error')}}

  function renderApps() {
    const all = state.boot.apps || [];
    const cats = [...new Set(all.map(a=>a.category).filter(Boolean))].sort((a,b)=>a.localeCompare(b));
    const q = state.appSearch.trim().toLowerCase();
    const filtered = all.filter(a => (state.appCategory==='all'||a.category===state.appCategory) && (!q || `${a.name} ${a.url} ${a.description} ${a.category}`.toLowerCase().includes(q)));
    const categoryButtons = `<button class="category-chip ${state.appCategory==='all'?'active':''}" data-app-category="all">Alle <span>${all.length}</span></button>${cats.map(c=>`<button class="category-chip ${state.appCategory===c?'active':''}" data-app-category="${attr(c)}">${esc(c)} <span>${all.filter(a=>a.category===c).length}</span></button>`).join('')}`;
    const compact = state.appView === 'compact'; const admin=isAdmin();
    const cards = filtered.map(app => compact ? `
      <article class="app-library-item">
        <a class="app-library-open" href="${attr(app.url)}" target="_blank" rel="noopener">${appIcon(app,'library-icon')}<span class="app-library-copy"><strong>${esc(app.name)}</strong><small>${esc(app.category || hostOf(app.url))}</small></span><span class="app-library-arrow">↗</span></a>
        ${admin?`<div class="app-library-actions"><button title="Zum Dashboard" data-add-app-widget="${attr(app.id)}">＋</button><button title="Bearbeiten" data-edit-app="${attr(app.id)}">⋯</button></div>`:''}
      </article>` : `
      <article class="app-card"><div class="app-card-top">${appIcon(app)}<div class="app-card-copy"><div class="app-card-name">${esc(app.name)}</div>${app.category?`<div class="app-card-category">${esc(app.category)}</div>`:''}<div class="url-host">${esc(hostOf(app.url))}</div></div></div><div class="app-card-description">${esc(app.description||'Keine Beschreibung.')}</div><div class="app-card-actions"><a class="btn soft" href="${attr(app.url)}" target="_blank" rel="noopener">Öffnen ↗</a>${admin?`<button class="btn" data-add-app-widget="${attr(app.id)}">Dashboard</button><button class="btn" data-edit-app="${attr(app.id)}">Bearbeiten</button>`:''}</div></article>`).join('');

    appRoot.innerHTML = pageHead('Library','Apps','Viele Homelab-Dienste schnell finden, sauber gruppieren und mit einem Klick öffnen.',`<div class="view-switch"><button data-app-view="compact" class="${compact?'active':''}" title="Kompakt">▦</button><button data-app-view="cards" class="${!compact?'active':''}" title="Details">▤</button></div>${admin?'<button class="btn primary" id="addAppBtn">+ App</button>':''}`) +
      `<div class="section-card app-library-tools"><div class="search-field"><span>⌕</span><input id="appSearch" placeholder="App, Host oder Kategorie suchen…" value="${attr(state.appSearch)}"></div><div class="category-strip">${categoryButtons}</div></div>` +
      `<div class="${compact?'app-library-grid':'apps-grid'}">${cards || `<div class="empty-dashboard"><h3>Keine Apps gefunden</h3><p>Lege deine erste App an oder ändere den Filter.</p></div>`}</div>`;

    if($('#addAppBtn'))$('#addAppBtn').onclick=()=>openAppModal();
    $('#appSearch').addEventListener('input',e=>{state.appSearch=e.target.value;renderApps();requestAnimationFrame(()=>{$('#appSearch')?.focus();$('#appSearch')?.setSelectionRange(state.appSearch.length,state.appSearch.length)})});
    $$('[data-app-category]').forEach(b=>b.onclick=()=>{state.appCategory=b.dataset.appCategory;renderApps()});
    $$('[data-app-view]').forEach(b=>b.onclick=()=>{state.appView=b.dataset.appView;localStorage.setItem('pengulab-app-view',state.appView);renderApps()});
    $$('[data-edit-app]').forEach(b=>b.onclick=e=>{e.preventDefault();e.stopPropagation();openAppModal(all.find(a=>a.id===b.dataset.editApp))});
    $$('[data-add-app-widget]').forEach(b=>b.onclick=e=>{e.preventDefault();e.stopPropagation();createWidget({type:'app',config:{app_id:b.dataset.addAppWidget,layout:'vertical'},w:1,h:1})});
  }

  function openAppModal(app=null) {
    const editing = !!app;
    const imageValue = app?.image?.startsWith('data:') ? '' : (app?.image||'');
    const currentPreview = app?.image || '';
    showModal(editing?'App bearbeiten':'App hinzufügen',editing?'Link, Kategorie, Icon oder Beschreibung ändern.':'PenguLab sucht das Favicon automatisch, wenn kein eigenes Icon gesetzt ist.',`<div class="form-grid"><div class="field-row full"><label>Name</label><input id="appName" maxlength="100" value="${attr(app?.name||'')}" placeholder="Home Assistant"></div><div class="field-row full"><label>URL</label><input id="appUrl" type="url" value="${attr(app?.url||'')}" placeholder="https://homeassistant.local"></div><div class="field-row"><label>Kategorie</label><input id="appCategoryField" value="${attr(app?.category||'')}" placeholder="Smart Home"></div><div class="field-row"><label>Icon URL (optional)</label><input id="appImage" value="${attr(imageValue)}" placeholder="Leer = Favicon automatisch"></div><div class="field-row full"><label>Icon</label><div class="favicon-row"><div id="faviconPreview">${appIcon({name:app?.name||'App',image:currentPreview},'favicon-preview-icon')}</div><div><button class="btn small" type="button" id="lookupFavicon">Favicon neu laden</button><div class="field-help" id="faviconHelp">Bei leerem Icon wird beim Speichern automatisch gesucht.</div></div></div></div><div class="field-row full"><label>Beschreibung</label><textarea id="appDescription">${esc(app?.description||'')}</textarea></div>${!editing?`<label class="setting-line full"><span class="setting-copy"><strong>Direkt zum Dashboard</strong><p>Erstellt einen kompakten Shortcut. Er kann später bis auf 1×1 verkleinert werden.</p></span><button type="button" class="toggle on" id="appDashboardToggle"></button></label>`:''}</div>`, `<button class="btn" data-close-modal>Abbrechen</button>${editing?`<button class="btn danger" id="deleteApp">Löschen</button>`:''}<button class="btn primary" id="saveApp">Speichern</button>`, modal=>{
      let addToDashboard=!editing;
      let detectedImage=currentPreview;
      let refreshFavicon=false;
      $('#appDashboardToggle',modal)?.addEventListener('click',e=>{e.currentTarget.classList.toggle('on');addToDashboard=e.currentTarget.classList.contains('on')});
      const updatePreview=()=>{const name=$('#appName',modal).value||'App';const manual=$('#appImage',modal).value.trim();$('#faviconPreview',modal).innerHTML=appIcon({name,image:manual||detectedImage},'favicon-preview-icon')};
      $('#appName',modal).addEventListener('input',updatePreview);
      $('#appImage',modal).addEventListener('input',()=>{if($('#appImage',modal).value.trim())detectedImage='';updatePreview()});
      $('#lookupFavicon',modal).onclick=async()=>{const url=$('#appUrl',modal).value.trim();if(!url){toast('URL fehlt.','error');return;}const btn=$('#lookupFavicon',modal);btn.disabled=true;btn.textContent='Suche…';try{const d=await api('apps/favicon',{body:{url,verify_tls:true}});detectedImage=d.image||'';refreshFavicon=true;$('#appImage',modal).value='';updatePreview();$('#faviconHelp',modal).textContent=detectedImage?'Favicon gefunden und wird beim Speichern übernommen.':'Kein Favicon gefunden. Du kannst eine Icon-URL eintragen.';}catch(e){$('#faviconHelp',modal).textContent=e.message;toast(e.message,'error')}finally{btn.disabled=false;btn.textContent='Favicon neu laden'}};
      $('#saveApp',modal).onclick=async()=>{const manualImage=$('#appImage',modal).value.trim();const payload={id:app?.id,name:$('#appName',modal).value,url:$('#appUrl',modal).value,category:$('#appCategoryField',modal).value,image:manualImage || detectedImage || '',description:$('#appDescription',modal).value,add_to_dashboard:addToDashboard,refresh_favicon:refreshFavicon};try{const d=await api('apps/save',{body:payload});state.boot.apps=d.apps;state.boot.widgets=d.widgets;closeModal();renderApps();toast('App gespeichert.');}catch(e){toast(e.message,'error')}};
      $('#deleteApp',modal)?.addEventListener('click',async()=>{if(!confirm(`App „${app.name}“ löschen?`))return;try{const d=await api('apps/delete',{body:{id:app.id}});state.boot.apps=d.apps;state.boot.widgets=d.widgets;closeModal();renderApps();toast('App gelöscht.');}catch(e){toast(e.message,'error')}});
    });
  }

  function renderIntegrations() {
    const integrations=state.boot.integrations||[];const types=state.boot.integrationTypes||[];const admin=isAdmin();
    const actions=admin?(types.length?`<button class="btn primary" id="addIntegration">+ Verbindung</button>`:`<a class="btn primary" href="#hub">PenguHub öffnen</a>`):'';
    const cards=integrations.map(i=>`<article class="section-card integration-card"><div class="integration-top"><div class="package-icon" data-letter="${attr(initials(i.name))}"></div><div><div class="integration-name">${esc(i.name)}</div><div class="integration-type">${esc(i.type)}</div></div><div class="integration-status ${attr(i.last_status)}"><span class="status-dot"></span>${esc(i.last_status==='unknown'?'Nicht getestet':i.last_status)}</div></div><div class="integration-url">${esc(i.base_url)}</div>${i.last_error?`<div style="font-size:10px;color:var(--danger);margin-bottom:10px">${esc(i.last_error)}</div>`:''}${admin?`<div class="integration-actions"><button class="btn small soft" data-test-integration="${attr(i.id)}">Testen</button><button class="btn small" data-widget-integration="${attr(i.id)}">Widget</button><button class="btn small" data-edit-integration="${attr(i.id)}">Bearbeiten</button></div>`:'<div class="integration-access-note">Für deinen Benutzer freigegeben</div>'}</article>`).join('');
    appRoot.innerHTML=pageHead('Connections','Integrationen',admin?'Zugangsdaten bleiben in PenguLab. Widgets sprechen nie direkt aus dem Browser mit deinen Diensten.':'Hier siehst du die Integrationen, die dir ein Administrator freigegeben hat.',actions)+
      `<div class="integration-list">${cards||`<div class="empty-dashboard" style="grid-column:1/-1"><h3>Keine Integrationen freigegeben</h3><p>${admin?'Installiere im PenguHub einen Connector und füge eine Verbindung hinzu.':'Bitte einen Administrator um eine Freigabe.'}</p></div>`}</div>`;
    $('#addIntegration')?.addEventListener('click',()=>openIntegrationTypePicker());
    $$('[data-test-integration]').forEach(b=>b.onclick=async()=>{b.disabled=true;b.textContent='Teste…';try{const d=await api('integrations/test',{body:{id:b.dataset.testIntegration}});state.boot.integrations=d.integrations;renderIntegrations();toast('Verbindung erfolgreich.');}catch(e){b.disabled=false;b.textContent='Testen';toast(e.message,'error')}});
    $$('[data-edit-integration]').forEach(b=>b.onclick=()=>openIntegrationModal(integrations.find(i=>i.id===b.dataset.editIntegration)));
    $$('[data-widget-integration]').forEach(b=>{b.onclick=()=>{const i=integrations.find(x=>x.id===b.dataset.widgetIntegration);if(!i)return;if(i.type==='homeassistant'){openHomeAssistantWidgetModal(i.id);return;}const cat=(state.boot.widgetCatalog||[]).find(c=>c.type==='integration-summary'&&c.integrationType===i?.type);const sz=cat?.defaultSize||[4,2];createWidget({type:'integration-summary',title:i?.name||'',config:{integration_id:i.id},w:sz[0],h:sz[1]});}});
  }

  function openIntegrationTypePicker(){const types=state.boot.integrationTypes||[];showModal('Verbindung hinzufügen','Installierte PenguHub-Connectoren.',`<div class="widget-picker">${types.map(t=>`<button class="widget-choice" data-integration-type="${attr(t.type)}"><strong>${esc(t.name)}</strong><span>${esc(t.description||'')}</span></button>`).join('')}</div>`,'',modal=>{$$('[data-integration-type]',modal).forEach(b=>b.onclick=()=>{closeModal();openIntegrationModal(null,b.dataset.integrationType)})});}

  function openIntegrationModal(existing=null, forcedType='') {
    const type=forcedType||existing?.type;const info=(state.boot.integrationTypes||[]).find(t=>t.type===type);if(!info){toast('Connector nicht installiert.','error');return;}
    const fields=(info.fields||[]).map(f=>integrationFieldHtml(f,existing)).join('');
    const widgetOptions=(info.widget_options||[]).map(o=>integrationWidgetOptionHtml(o,existing)).join('');
    const widgetSection=widgetOptions?`<div class="modal-section-title">Widget-Inhalte</div><div class="integration-widget-options">${widgetOptions}</div>`:'';
    showModal(existing?'Verbindung bearbeiten':`${info.name} verbinden`,'Zugangsdaten werden serverseitig verschlüsselt gespeichert.',`<div class="field-row"><label>Name</label><input id="integrationName" value="${attr(existing?.name||info.name)}"></div>${fields}${widgetSection}`,`<button class="btn" data-close-modal>Abbrechen</button>${existing?`<button class="btn danger" id="deleteIntegration">Löschen</button>`:''}<button class="btn primary" id="saveIntegration">Speichern</button>`,modal=>{
      $('#saveIntegration',modal).onclick=async()=>{const payload={id:existing?.id,type,name:$('#integrationName',modal).value,secrets:{},config:{...(existing?.config||{})}};for(const f of info.fields||[]){const el=$(`[data-field="${CSS.escape(f.key)}"]`,modal);if(!el)continue;let value=f.type==='boolean'?el.checked:el.value;if(f.secret)payload.secrets[f.key]=value;else payload[f.key]=value;}for(const o of info.widget_options||[]){const el=$(`[data-widget-option="${CSS.escape(o.key)}"]`,modal);if(!el)continue;payload.config[o.key]=o.type==='boolean'?el.checked:(o.type==='number'?Number(el.value):el.value);}try{const d=await api('integrations/save',{body:payload});state.boot.integrations=d.integrations;closeModal();renderIntegrations();toast('Integration gespeichert.');}catch(e){toast(e.message,'error')}};
      $('#deleteIntegration',modal)?.addEventListener('click',async()=>{if(!confirm('Integration löschen?'))return;try{const d=await api('integrations/delete',{body:{id:existing.id}});state.boot.integrations=d.integrations;closeModal();renderIntegrations();toast('Integration gelöscht.');}catch(e){toast(e.message,'error')}});
      if(type==='opnsense')loadOpnsenseInterfaces(modal,existing);
    });
  }

  async function loadOpnsenseInterfaces(modal,existing){
    const select=$('[data-widget-option="traffic_interface"]',modal);if(!select)return;
    const saved=String(existing?.config?.traffic_interface||'auto');const current=saved.toLowerCase()==='wan'?'auto':saved;
    if(!existing?.id){select.innerHTML='<option value="auto">Automatisch (WAN erkennen)</option>';select.disabled=true;select.title='Nach dem ersten Speichern werden die OPNsense-Interfaces automatisch geladen.';return;}
    select.disabled=true;select.innerHTML=`<option value="${attr(current)}">Interfaces werden geladen…</option>`;
    try{
      const d=await api('integrations/interfaces',{body:{id:existing.id}});const interfaces=d.interfaces||[];
      const options=[{id:'auto',label:'Automatisch (WAN erkennen)',address:''},...interfaces];
      if(current!=='auto'&&!options.some(i=>String(i.id)===current))options.push({id:current,label:`${current} (gespeichert)`,address:''});
      select.innerHTML=options.map(i=>{const label=i.id==='auto'?i.label:`${i.label||i.id} · ${i.id}${i.address?` · ${i.address}`:''}`;return `<option value="${attr(i.id)}" ${String(i.id)===current?'selected':''}>${esc(label)}</option>`}).join('');
      select.disabled=false;
    }catch(e){select.innerHTML=`<option value="${attr(current)}">${esc(current==='auto'?'Automatisch (WAN erkennen)':current)}</option>`;select.disabled=false;select.title=e.message;}
  }

  function integrationFieldHtml(field,existing){const k=field.key;if(k==='base_url'){return `<div class="field-row"><label>${esc(field.label)}</label><input data-field="${attr(k)}" type="url" value="${attr(existing?.base_url||'')}" placeholder="${attr(field.placeholder||'')}"></div>`;}if(k==='username'){return `<div class="field-row"><label>${esc(field.label)}</label><input data-field="${attr(k)}" value="${attr(existing?.username||'')}"></div>`;}if(k==='verify_tls'){const checked=existing?existing.verify_tls:(field.default!==false);return `<label class="setting-line"><span class="setting-copy"><strong>${esc(field.label)}</strong><p>Bei internen Self-Signed-Zertifikaten kann dies deaktiviert werden.</p></span><input data-field="${attr(k)}" type="checkbox" ${checked?'checked':''}></label>`;}if(field.secret){const has=existing?.has_secrets?.[k];return `<div class="field-row"><label>${esc(field.label)}${has?' · gespeichert':''}</label><input data-field="${attr(k)}" type="password" autocomplete="new-password" placeholder="${has?'Leer lassen = behalten':''}"></div>`;}return `<div class="field-row"><label>${esc(field.label)}</label><input data-field="${attr(k)}" value="${attr(existing?.config?.[k]??field.default??'')}" placeholder="${attr(field.placeholder||'')}"></div>`;}
  function integrationWidgetOptionHtml(option,existing){const cfg=existing?.config||{},value=Object.prototype.hasOwnProperty.call(cfg,option.key)?cfg[option.key]:option.default;if(option.type==='boolean')return `<label class="setting-line compact"><span class="setting-copy"><strong>${esc(option.label)}</strong></span><input data-widget-option="${attr(option.key)}" type="checkbox" ${value!==false?'checked':''}></label>`;if(option.type==='interface-select')return `<div class="field-row"><label>${esc(option.label)}</label><select data-widget-option="${attr(option.key)}"><option value="${attr(value||'auto')}">${esc(String(value||'auto'))}</option></select><small class="field-help">PenguLab erkennt die in OPNsense konfigurierten Interfaces automatisch.</small></div>`;if(option.type==='select')return `<div class="field-row"><label>${esc(option.label)}</label><select data-widget-option="${attr(option.key)}">${(option.options||[]).map(o=>{const ov=typeof o==='object'?o.value:o,ol=typeof o==='object'?(o.label??o.value):o;return `<option value="${attr(ov)}" ${String(value)===String(ov)?'selected':''}>${esc(ol)}</option>`}).join('')}</select></div>`;if(option.type==='number')return `<div class="field-row"><label>${esc(option.label)}</label><input data-widget-option="${attr(option.key)}" type="number" min="${attr(option.min??'')}" max="${attr(option.max??'')}" step="${attr(option.step??1)}" value="${attr(value??'')}"></div>`;return `<div class="field-row"><label>${esc(option.label)}</label><input data-widget-option="${attr(option.key)}" value="${attr(value??'')}" placeholder="${attr(option.placeholder||'')}"></div>`;}

  function renderHub() {
    const addons=state.boot.addons||[];
    appRoot.innerHTML=pageHead('Extensions','PenguHub','Funktionen werden als klar getrennte Pakete installiert. Der PenguLab-Core bleibt klein und stabil.')+
      `<section class="section-card hub-hero"><div class="hub-hero-icon">P</div><div><h2>Baue dir genau dein PenguLab</h2><p>IP Management, DNS-Monitoring, Firewall-Status, RSS und API-Anbindungen sind Erweiterungen – nicht fest im Dashboard verdrahtet.</p></div></section>`+
      `<div class="hub-grid">${addons.map(a=>`<article class="section-card hub-card"><div class="hub-head"><div class="package-icon" data-letter="${attr(initials(a.name))}"></div><div><div class="hub-name">${esc(a.name)}</div><div class="hub-meta">${esc(a.category||'Addon')} · v${esc(a.version)}</div></div><div class="hub-badges">${a.enabled?'<span class="badge installed">Installed</span>':'<span class="badge">Available</span>'}</div></div><div class="hub-description">${esc(a.description||'')}</div><div class="hub-permissions">${(a.permissions||[]).map(p=>esc(p)).join(' · ')}</div><div class="hub-actions">${a.enabled?(a.id==='ipmanager'?`<a class="btn small soft" href="?addon=ipmanager">Öffnen</a>`:'')+`<button class="btn small" data-uninstall-addon="${attr(a.id)}">Deaktivieren</button>`:`<button class="btn small primary" data-install-addon="${attr(a.id)}">Installieren</button>`}</div></article>`).join('')}</div>`;
    $$('[data-install-addon]').forEach(b=>b.onclick=async()=>{b.disabled=true;b.textContent='Installiere…';try{await api('addons/install',{body:{id:b.dataset.installAddon}});toast('Paket installiert.');location.reload();}catch(e){b.disabled=false;toast(e.message,'error')}});
    $$('[data-uninstall-addon]').forEach(b=>b.onclick=async()=>{if(!confirm('Paket deaktivieren? Daten bleiben erhalten.'))return;try{await api('addons/uninstall',{body:{id:b.dataset.uninstallAddon}});toast('Paket deaktiviert.');location.reload();}catch(e){toast(e.message,'error')}});
  }

  function renderSettings() {
    const s=state.boot.settings||{},user=state.boot.user||{},admin=isAdmin();
    const account=`<section class="section-card settings-card"><h3>Benutzerkonto</h3><p>Angemeldet als <strong>${esc(user.username||'')}</strong> · ${admin?'Administrator':'Benutzer'}</p>${state.boot.defaultPassword?'<div class="security-warning"><strong>Standardpasswort aktiv</strong><span>Bitte <code>admin</code> jetzt durch ein eigenes Passwort ersetzen.</span></div>':''}<div class="field-row"><label>Aktuelles Passwort</label><input id="currentPassword" type="password" autocomplete="current-password"></div><div class="field-row"><label>Neues Passwort</label><input id="newPassword" type="password" autocomplete="new-password" minlength="8" placeholder="mindestens 8 Zeichen"></div><div class="settings-actions"><button class="btn primary" id="changePassword">Passwort ändern</button><a class="btn" href="?logout=1">Abmelden</a></div></section>`;
    const appearance=admin?`<section class="section-card settings-card"><h3>Appearance</h3><p>Theme und Dashboard-Titel gelten für die gesamte PenguLab-Instanz.</p><div class="segmented" id="themeSegment"><button data-theme-value="system" class="${s.theme==='system'?'active':''}">System</button><button data-theme-value="light" class="${s.theme==='light'?'active':''}">Light</button><button data-theme-value="dark" class="${s.theme==='dark'?'active':''}">Dark</button></div><div class="field-row" style="margin-top:18px"><label>Dashboard Titel</label><input id="dashboardTitle" value="${attr(s.dashboard_title||'My Homelab')}"></div><button class="btn primary" id="saveGeneral">Speichern</button></section>`:'';
    const backup=admin?`<section class="section-card settings-card"><h3>Backup & Migration</h3><p>JSON-Export enthält Apps, Layout und Konfiguration, aber keine Passwörter, Benutzer-Hashes oder Integration-Secrets. Für ein vollständiges Backup das gesamte <code>data/</code>-Verzeichnis sichern.</p><div class="settings-actions"><button class="btn" id="exportBtn">JSON exportieren</button><label class="btn" for="importFile">JSON importieren</label><input id="importFile" type="file" accept="application/json" hidden></div><div class="setting-line" style="margin-top:15px"><span class="setting-copy"><strong>Database</strong><p>SQLite · /data/pengulab.sqlite</p></span><span class="badge installed">Active</span></div><div class="setting-line"><span class="setting-copy"><strong>Remember Login</strong><p>90 Tage · HttpOnly Cookie · serverseitig gehashter Token</p></span><span class="badge installed">Active</span></div></section>`:'';
    const users=admin?usersSettingsHtml():'';
    appRoot.innerHTML=pageHead('System','Einstellungen',admin?'Benutzer, Aussehen, Sicherheit und Backups.':'Konto und persönliche Einstellungen.')+`<div class="settings-grid">${account}${appearance}${users}${backup}</div>`;
    $('#changePassword')?.addEventListener('click',async()=>{try{await api('users/password',{body:{current:$('#currentPassword').value,password:$('#newPassword').value}});$('#currentPassword').value='';$('#newPassword').value='';state.boot.defaultPassword=false;toast('Passwort geändert.');renderSettings();}catch(e){toast(e.message,'error')}});
    $$('[data-theme-value]').forEach(b=>b.onclick=async()=>{const theme=b.dataset.themeValue;try{const d=await api('settings/save',{body:{theme}});state.boot.settings=d.settings;applyTheme(theme);renderSettings();}catch(e){toast(e.message,'error')}});
    if($('#saveGeneral'))$('#saveGeneral').onclick=async()=>{try{const d=await api('settings/save',{body:{dashboard_title:$('#dashboardTitle').value}});state.boot.settings=d.settings;toast('Gespeichert.');}catch(e){toast(e.message,'error')}};
    $('#exportBtn')?.addEventListener('click',async()=>{try{const d=await api('export');const blob=new Blob([JSON.stringify(d.data,null,2)],{type:'application/json'});const url=URL.createObjectURL(blob);const a=document.createElement('a');a.href=url;a.download=`pengulab-export-${new Date().toISOString().slice(0,10)}.json`;a.click();URL.revokeObjectURL(url);}catch(e){toast(e.message,'error')}});
    if($('#importFile'))$('#importFile').onchange=async e=>{const file=e.target.files?.[0];if(!file)return;try{const data=JSON.parse(await file.text());await api('import',{body:{data}});await refreshBoot();toast('Import abgeschlossen.');}catch(err){toast(err.message,'error')}};
    $$('[data-edit-user]').forEach(b=>b.onclick=()=>openUserModal((state.boot.users||[]).find(u=>u.id===b.dataset.editUser)));
    $('#addUser')?.addEventListener('click',()=>openUserModal());
  }

  function usersSettingsHtml(){const users=state.boot.users||[];return `<section class="section-card settings-card settings-users"><div class="settings-card-head"><div><h3>Benutzer</h3><p>Freigaben gelten pro Integration. Dashboard-Apps sind für alle Benutzer sichtbar.</p></div><button class="btn small primary" id="addUser">+ Benutzer</button></div><div class="user-list">${users.map(u=>`<button class="user-row" data-edit-user="${attr(u.id)}"><span class="user-avatar">${esc((u.username||'?')[0].toUpperCase())}</span><span><strong>${esc(u.username)}</strong><small>${u.role==='admin'?'Administrator':`${(u.permissions?.integrations||[]).length} Integration(en)${u.permissions?.ipmanager?' · IP Manager':''}`}</small></span><span class="badge ${u.role==='admin'?'installed':''}">${esc(u.role)}</span></button>`).join('')}</div></section>`;}

  function openUserModal(existing=null){const integrations=state.boot.integrations||[],perms=existing?.permissions||{ipmanager:false,integrations:[]};showModal(existing?'Benutzer bearbeiten':'Benutzer anlegen','Administratoren haben immer Vollzugriff. Normale Benutzer erhalten nur die ausgewählten Integrationen und optional den IP Manager.',`<div class="field-row"><label>Benutzername</label><input id="userName" value="${attr(existing?.username||'')}" autocomplete="off"></div><div class="field-row"><label>${existing?'Neues Passwort (leer = unverändert)':'Passwort'}</label><input id="userPassword" type="password" autocomplete="new-password" placeholder="mindestens 8 Zeichen"></div><div class="field-row"><label>Rolle</label><select id="userRole"><option value="user" ${existing?.role!=='admin'?'selected':''}>Benutzer</option><option value="admin" ${existing?.role==='admin'?'selected':''}>Administrator</option></select></div><div class="modal-section-title">Freigaben für Benutzer</div><label class="setting-line compact"><span class="setting-copy"><strong>IP Manager</strong></span><input id="userIpm" type="checkbox" ${perms.ipmanager?'checked':''}></label><div class="permission-list">${integrations.map(i=>`<label class="setting-line compact"><span class="setting-copy"><strong>${esc(i.name)}</strong><p>${esc(i.type)}</p></span><input data-user-integration="${attr(i.id)}" type="checkbox" ${(perms.integrations||[]).includes(i.id)?'checked':''}></label>`).join('')||'<p class="field-help">Noch keine Integrationen vorhanden.</p>'}</div>`,`<button class="btn" data-close-modal>Abbrechen</button>${existing&&existing.id!==state.boot.user.id?'<button class="btn danger" id="deleteUser">Löschen</button>':''}<button class="btn primary" id="saveUser">Speichern</button>`,modal=>{const syncRole=()=>{const admin=$('#userRole',modal).value==='admin';$('#userIpm',modal).disabled=admin;$$('[data-user-integration]',modal).forEach(e=>e.disabled=admin)};$('#userRole',modal).onchange=syncRole;syncRole();$('#saveUser',modal).onclick=async()=>{const payload={id:existing?.id,username:$('#userName',modal).value,password:$('#userPassword',modal).value,role:$('#userRole',modal).value,permissions:{ipmanager:$('#userIpm',modal).checked,integrations:$$('[data-user-integration]',modal).filter(e=>e.checked).map(e=>e.dataset.userIntegration)}};try{const d=await api('users/save',{body:payload});state.boot.users=d.users;closeModal();renderSettings();toast('Benutzer gespeichert.');}catch(e){toast(e.message,'error')}};$('#deleteUser',modal)?.addEventListener('click',async()=>{if(!confirm(`Benutzer „${existing.username}“ löschen?`))return;try{const d=await api('users/delete',{body:{id:existing.id}});state.boot.users=d.users;closeModal();renderSettings();toast('Benutzer gelöscht.');}catch(e){toast(e.message,'error')}})});}

  function applyTheme(theme){document.documentElement.dataset.theme=theme||'system';}

  function showModal(title, subtitle, bodyHtml, footerHtml = '', onMount = null) {
    closeModal(); const wrap=document.createElement('div');wrap.className='modal-backdrop';wrap.id='modalBackdrop';wrap.innerHTML=`<div class="modal" role="dialog" aria-modal="true"><div class="modal-head"><div><h2>${esc(title)}</h2><p>${esc(subtitle||'')}</p></div><button class="modal-close" data-close-modal aria-label="Schließen">×</button></div><div class="modal-body">${bodyHtml}</div>${footerHtml?`<div class="modal-footer">${footerHtml}</div>`:''}</div>`;document.body.appendChild(wrap);wrap.addEventListener('mousedown',e=>{if(e.target===wrap)closeModal()});$$('[data-close-modal]',wrap).forEach(b=>b.addEventListener('click',closeModal));onMount?.(wrap);setTimeout(()=>wrap.querySelector('input,select,textarea,button')?.focus(),20);
  }
  function closeModal(){document.getElementById('modalBackdrop')?.remove();}

  function setupGlobalSearch() {
    $('#globalSearchButton')?.addEventListener('click',openGlobalSearch);
    document.addEventListener('keydown',e=>{if((e.ctrlKey||e.metaKey)&&e.key.toLowerCase()==='k'){e.preventDefault();openGlobalSearch()}if(e.key==='Escape'){$('#searchOverlay')?.remove();closeModal();}});
  }

  function openGlobalSearch(){if($('#searchOverlay'))return;const overlay=document.createElement('div');overlay.id='searchOverlay';overlay.className='search-overlay';overlay.innerHTML=`<div class="command"><div class="command-input"><span>⌕</span><input id="commandSearch" placeholder="App, IP, Hostname, Integration…" autocomplete="off"><kbd>ESC</kbd></div><div class="command-results" id="commandResults"><div class="command-empty">Tippe zum Suchen…</div></div></div>`;document.body.appendChild(overlay);overlay.addEventListener('mousedown',e=>{if(e.target===overlay)overlay.remove()});const input=$('#commandSearch',overlay),results=$('#commandResults',overlay);let timer;input.addEventListener('input',()=>{clearTimeout(timer);timer=setTimeout(async()=>{const q=input.value.trim();if(!q){results.innerHTML='<div class="command-empty">Tippe zum Suchen…</div>';return;}try{const d=await api('search',{params:{q}});results.innerHTML=d.items.length?d.items.map(item=>`<button class="command-item" data-search-type="${attr(item.type)}" data-search-id="${attr(item.id||'')}" data-search-url="${attr(item.url||'')}" data-search-addon="${attr(item.addon||'')}"><span class="command-kind">${esc((item.type||'?').slice(0,2))}</span><span class="command-copy"><span class="command-title">${esc(item.title)}</span><span class="command-subtitle">${esc(item.subtitle||'')}</span></span></button>`).join(''):'<div class="command-empty">Nichts gefunden.</div>';$$('.command-item',results).forEach(b=>b.onclick=()=>activateSearchResult(b));}catch(e){results.innerHTML=`<div class="command-empty">${esc(e.message)}</div>`}},160)});input.focus();}
  function activateSearchResult(btn){const type=btn.dataset.searchType,url=btn.dataset.searchUrl;if(type==='app'&&url){window.open(url,'_blank','noopener');return;}$('#searchOverlay')?.remove();if(btn.dataset.searchAddon==='ipmanager'||['network','device'].includes(type)){location.href='?addon=ipmanager';return;}if(type==='integration'){location.hash='integrations';return;}location.hash='dashboard';}

  window.addEventListener('hashchange',()=>{if(state.editMode)discardLayoutEdit({renderAfter:false});setViewFromHash();render();$('#sidebar')?.classList.remove('open')});
  boot();
})();
