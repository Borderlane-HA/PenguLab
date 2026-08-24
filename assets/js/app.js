(() => {
  'use strict';

  const apiUrl = window.PENGULAB?.api || 'api.php';
  const appRoot = document.getElementById('app');
  const state = {
    boot: null,
    csrf: '',
    view: 'dashboard',
    editMode: false,
    appSearch: '',
    appCategory: 'all',
    appView: localStorage.getItem('pengulab-app-view') || 'compact',
    widgetRefreshTimers: new Map(),
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
    const allowed = ['dashboard','apps','integrations','hub','settings'];
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
      const mobileMenu = $('#mobileMenu');
      if (mobileMenu) mobileMenu.addEventListener('click', () => $('#sidebar')?.classList.toggle('open'));
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

  function renderDashboard() {
    const widgets = state.boot.widgets || [];
    const title = state.boot.settings?.dashboard_title || 'My Homelab';
    appRoot.innerHTML = pageHead('Control Center', title, 'Apps, Services und Homelab-Status an einem Ort.', `
      <button class="btn" id="editLayoutBtn">${state.editMode ? 'Fertig' : 'Layout bearbeiten'}</button>
      <button class="btn primary" id="addWidgetBtn">+ Widget</button>
    `) + `<div class="dashboard-toolbar"><span class="edit-hint">Widgets ziehen oder am rechten unteren Rand skalieren. Änderungen werden automatisch gespeichert.</span></div>` +
    `<div class="dashboard-grid" id="dashboardGrid">${widgets.length ? widgets.map(widgetShell).join('') : `<div class="empty-dashboard"><h3>Dein Dashboard ist bereit</h3><p>Füge Apps, Statuskarten, RSS oder Add-on-Widgets hinzu.</p><button class="btn primary" id="emptyAddWidget">+ Erstes Widget</button></div>`}</div>`;

    $('#editLayoutBtn')?.addEventListener('click', () => { state.editMode = !state.editMode; renderDashboard(); document.body.classList.toggle('editing', state.editMode); });
    $('#addWidgetBtn')?.addEventListener('click', openWidgetPicker);
    $('#emptyAddWidget')?.addEventListener('click', openWidgetPicker);
    $$('.widget-remove', appRoot).forEach(btn => btn.addEventListener('click', async e => {
      e.stopPropagation(); if (!confirm('Widget vom Dashboard entfernen?')) return;
      try { const d = await api('widgets/delete', {body:{id:btn.dataset.id}}); state.boot.widgets = d.widgets; renderDashboard(); } catch(err){ toast(err.message,'error'); }
    }));
    if (state.editMode) enableGridInteractions();
    loadWidgetData();
  }

  function widgetShell(widget) {
    const title = widget.title || widgetTitle(widget);
    const typeClass = `widget-type-${String(widget.type || 'unknown').replace(/[^a-z0-9_-]/gi,'-')}`;
    const sizeClass = widget.type === 'app' ? (widget.w <= 1 ? 'app-widget-xs' : widget.w === 2 ? 'app-widget-sm' : 'app-widget-lg') : '';
    return `<section class="widget ${typeClass} ${sizeClass}" data-widget-id="${attr(widget.id)}" style="--x:${Number(widget.x)||0};--y:${Number(widget.y)||0};--w:${Number(widget.w)||3};--h:${Number(widget.h)||2}">
      <div class="widget-head"><span class="widget-drag-handle" title="Verschieben">⠿</span><span class="widget-title">${esc(title)}</span><span class="widget-head-spacer"></span><div class="widget-menu"><button class="widget-mini-btn widget-remove" data-id="${attr(widget.id)}" title="Entfernen">×</button></div></div>
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
    return 'Widget';
  }

  async function loadWidgetData() {
    for (const widget of state.boot.widgets || []) {
      loadOneWidget(widget);
      if (['integration-summary','rss','ipmanager-summary'].includes(widget.type)) {
        const timer = setInterval(() => loadOneWidget(widget, true), widget.type === 'rss' ? 300000 : 60000);
        state.widgetRefreshTimers.set(widget.id, timer);
      }
    }
  }

  async function loadOneWidget(widget, silent = false) {
    const body = $(`[data-widget-body="${CSS.escape(widget.id)}"]`);
    if (!body) return;
    try {
      if (widget.type === 'clock') {
        renderClock(body); return;
      }
      if (widget.type === 'note') {
        body.innerHTML = `<div class="note-widget">${esc(widget.config?.text || 'Noch keine Notiz.')}</div>`; return;
      }
      const result = await api('widgets/data', {params:{id:widget.id}});
      renderWidgetData(body, widget, result.data || {});
    } catch (e) {
      if (!silent) body.innerHTML = `<div class="widget-error">${esc(e.message)}</div>`;
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
      body.innerHTML = `<a class="app-widget app-widget-${size}" href="${attr(app.url)}" target="_blank" rel="noopener" title="${attr(app.name)} öffnen">${appIcon(app)}<div class="app-widget-copy"><div class="app-widget-name">${esc(app.name)}</div><div class="app-widget-meta">${esc(app.category || hostOf(app.url))}</div></div><span class="app-widget-open" aria-hidden="true">↗</span></a>`;
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
    if (data.kind === 'integration') {
      body.innerHTML = integrationSummaryHtml(data.integration || {}, data.summary || {});
      bindIntegrationActions(body, widget, data.integration || {});
      return;
    }
    body.innerHTML = `<pre style="font-size:10px;overflow:auto">${esc(JSON.stringify(data,null,2))}</pre>`;
  }

  function integrationSummaryHtml(integration, summary) {
    const type = integration.type || '';
    if (type === 'pihole' || type === 'adguardhome') {
      const active = summary.protection !== false;
      return `<div class="dns-widget"><div class="service-heading"><span class="status-dot ${active?'':'paused'}"></span><span class="service-name">${esc(integration.name)}</span><span class="service-state ${active?'active':'paused'}">${active?'Schutz aktiv':'Schutz pausiert'}</span></div><div class="metric-grid dns-metrics"><div class="metric"><div class="metric-label">Queries</div><div class="metric-value">${fmt(summary.queries)}</div></div><div class="metric"><div class="metric-label">Blocked</div><div class="metric-value">${fmt(summary.blocked_percent)}%</div></div><div class="metric"><div class="metric-label">Status</div><div class="metric-value">${active ? 'Active' : 'Paused'}</div></div><div class="metric"><div class="metric-label">Clients</div><div class="metric-value">${type==='pihole'?fmt(summary.clients):'—'}</div></div></div><div class="service-actions"><button class="service-action ${active?'':'primary'}" data-integration-action="protection_enable" ${active?'disabled':''}>Fortsetzen</button><button class="service-action" data-integration-action="protection_pause_300">5 Min Pause</button><button class="service-action danger" data-integration-action="protection_disable" ${!active?'disabled':''}>Anhalten</button></div></div>`;
    }
    if (type === 'opnsense') {
      const sys = summary.system || {};
      const statusText = typeof sys.status === 'string' ? sys.status : (sys.status === 'ok' ? 'OK' : 'API online');
      return `<div class="service-heading"><span class="status-dot"></span><span class="service-name">${esc(integration.name)}</span></div><div class="metric-grid"><div class="metric"><div class="metric-label">Firewall</div><div class="metric-value">Online</div></div><div class="metric"><div class="metric-label">System</div><div class="metric-value">${esc(String(statusText).slice(0,20))}</div></div></div>`;
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
        toast(action === 'protection_enable' ? 'Schutz fortgesetzt.' : action === 'protection_pause_300' ? 'Schutz für 5 Minuten pausiert.' : 'Schutz angehalten.');
        await loadOneWidget(widget);
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
  function findFree(candidate, widgets) {
    if (positionFree(candidate, widgets, candidate.id)) return candidate;
    for (let y=0;y<120;y++) for (let x=0;x<=12-candidate.w;x++) { const c={...candidate,x,y}; if(positionFree(c,widgets,candidate.id)) return c; }
    return {...candidate,y:Math.max(0,...widgets.filter(w=>w.id!==candidate.id).map(w=>w.y+w.h))};
  }
  function setWidgetStyle(el,w){el.style.setProperty('--x',w.x);el.style.setProperty('--y',w.y);el.style.setProperty('--w',w.w);el.style.setProperty('--h',w.h);}
  function startDrag(ev, el, widget, grid) {
    ev.preventDefault(); ev.stopPropagation(); el.setPointerCapture?.(ev.pointerId); el.classList.add('dragging');
    const m=gridMetrics(grid), sx=ev.clientX, sy=ev.clientY, original={x:widget.x,y:widget.y};
    const move=e=>{const dx=Math.round((e.clientX-sx)/(m.col+m.gap));const dy=Math.round((e.clientY-sy)/m.unitY);widget.x=Math.max(0,Math.min(12-widget.w,original.x+dx));widget.y=Math.max(0,original.y+dy);setWidgetStyle(el,widget)};
    const up=async e=>{el.classList.remove('dragging');el.releasePointerCapture?.(ev.pointerId);window.removeEventListener('pointermove',move);window.removeEventListener('pointerup',up);const resolved=findFree(widget,state.boot.widgets);Object.assign(widget,resolved);setWidgetStyle(el,widget);await saveLayout();};
    window.addEventListener('pointermove',move);window.addEventListener('pointerup',up,{once:true});
  }
  function startResize(ev, el, widget, grid) {
    ev.preventDefault();ev.stopPropagation();el.setPointerCapture?.(ev.pointerId);el.classList.add('dragging');const m=gridMetrics(grid),sx=ev.clientX,sy=ev.clientY,original={w:widget.w,h:widget.h};
    const move=e=>{const dw=Math.round((e.clientX-sx)/(m.col+m.gap));const dh=Math.round((e.clientY-sy)/m.unitY);const minW=widget.type==='app'?1:2;widget.w=Math.max(minW,Math.min(12-widget.x,original.w+dw));widget.h=Math.max(1,Math.min(8,original.h+dh));setWidgetStyle(el,widget);el.classList.toggle('app-widget-xs',widget.type==='app'&&widget.w<=1);el.classList.toggle('app-widget-sm',widget.type==='app'&&widget.w===2);el.classList.toggle('app-widget-lg',widget.type==='app'&&widget.w>=3)};
    const up=async()=>{el.classList.remove('dragging');window.removeEventListener('pointermove',move);window.removeEventListener('pointerup',up);if(!positionFree(widget,state.boot.widgets,widget.id)){widget.w=original.w;widget.h=original.h;setWidgetStyle(el,widget);toast('Dort ist nicht genug Platz.','error');return;}await saveLayout();await loadOneWidget(widget,true);};
    window.addEventListener('pointermove',move);window.addEventListener('pointerup',up,{once:true});
  }
  async function saveLayout(){try{const d=await api('widgets/layout',{body:{widgets:(state.boot.widgets||[]).map(({id,x,y,w,h})=>({id,x,y,w,h}))}});state.boot.widgets=d.widgets;}catch(e){toast(e.message,'error')}}

  function openWidgetPicker() {
    const integrations = state.boot.integrations || []; const apps = state.boot.apps || []; const catalog = state.boot.widgetCatalog || [];
    const installedTypes = new Set(catalog.map(c=>c.type));
    const cards = [
      apps.length ? {key:'app',name:'App Shortcut',desc:'Eine vorhandene App als Schnellstart.',icon:'A'} : null,
      {key:'clock',name:'Clock',desc:'Uhrzeit und Datum.',icon:'C'},
      {key:'note',name:'Note',desc:'Kurze Notiz direkt auf dem Dashboard.',icon:'N'},
      installedTypes.has('rss') ? {key:'rss',name:'RSS / Atom',desc:'News und Feeds anzeigen.',icon:'R'} : null,
      installedTypes.has('ipmanager-summary') ? {key:'ipmanager-summary',name:'IP Manager',desc:'Netze und dokumentierte Geräte.',icon:'IP'} : null,
      ...integrations.filter(i=>i.enabled).map(i=>({key:'integration:'+i.id,name:i.name,desc:`${i.type} Status`,icon:initials(i.name)})),
    ].filter(Boolean);
    showModal('Widget hinzufügen','Wähle, was auf dem Dashboard erscheinen soll.', `<div class="widget-picker">${cards.map(c=>`<button class="widget-choice" type="button" data-widget-choice="${attr(c.key)}"><strong>${esc(c.name)}</strong><span>${esc(c.desc)}</span></button>`).join('') || '<p>Installiere zuerst Pakete im PenguHub oder lege Apps an.</p>'}</div>`, '', modal => {
      $$('[data-widget-choice]',modal).forEach(btn=>btn.addEventListener('click',()=>configureWidgetChoice(btn.dataset.widgetChoice)));
    });
  }

  function configureWidgetChoice(key) {
    closeModal();
    if (key === 'clock' || key === 'ipmanager-summary') { createWidget({type:key,w:key==='clock'?3:4,h:2}); return; }
    if (key.startsWith('integration:')) {
      const id=key.split(':')[1];const integration=(state.boot.integrations||[]).find(i=>i.id===id);const catalog=(state.boot.widgetCatalog||[]).find(c=>c.type==='integration-summary'&&c.integrationType===integration?.type);const size=catalog?.defaultSize||[4,2];createWidget({type:'integration-summary',title:integration?.name||'',config:{integration_id:id},w:size[0],h:size[1]});return;
    }
    if (key === 'app') {
      const opts=(state.boot.apps||[]).map(a=>`<option value="${attr(a.id)}">${esc(a.name)}</option>`).join('');
      showModal('App Shortcut','Wähle eine vorhandene App.',`<div class="field-row"><label>App</label><select id="widgetAppId">${opts}</select></div>`,`<button class="btn" data-close-modal>Abbrechen</button><button class="btn primary" id="saveWidgetConfig">Hinzufügen</button>`,modal=>{$('#saveWidgetConfig',modal).onclick=()=>{const id=$('#widgetAppId',modal).value;closeModal();createWidget({type:'app',config:{app_id:id},w:2,h:1});}});return;
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
    const compact = state.appView === 'compact';
    const cards = filtered.map(app => compact ? `
      <article class="app-library-item">
        <a class="app-library-open" href="${attr(app.url)}" target="_blank" rel="noopener">${appIcon(app,'library-icon')}<span class="app-library-copy"><strong>${esc(app.name)}</strong><small>${esc(app.category || hostOf(app.url))}</small></span><span class="app-library-arrow">↗</span></a>
        <div class="app-library-actions"><button title="Zum Dashboard" data-add-app-widget="${attr(app.id)}">＋</button><button title="Bearbeiten" data-edit-app="${attr(app.id)}">⋯</button></div>
      </article>` : `
      <article class="app-card"><div class="app-card-top">${appIcon(app)}<div class="app-card-copy"><div class="app-card-name">${esc(app.name)}</div>${app.category?`<div class="app-card-category">${esc(app.category)}</div>`:''}<div class="url-host">${esc(hostOf(app.url))}</div></div></div><div class="app-card-description">${esc(app.description||'Keine Beschreibung.')}</div><div class="app-card-actions"><a class="btn soft" href="${attr(app.url)}" target="_blank" rel="noopener">Öffnen ↗</a><button class="btn" data-add-app-widget="${attr(app.id)}">Dashboard</button><button class="btn" data-edit-app="${attr(app.id)}">Bearbeiten</button></div></article>`).join('');

    appRoot.innerHTML = pageHead('Library','Apps','Viele Homelab-Dienste schnell finden, sauber gruppieren und mit einem Klick öffnen.',`<div class="view-switch"><button data-app-view="compact" class="${compact?'active':''}" title="Kompakt">▦</button><button data-app-view="cards" class="${!compact?'active':''}" title="Details">▤</button></div><button class="btn primary" id="addAppBtn">+ App</button>`) +
      `<div class="section-card app-library-tools"><div class="search-field"><span>⌕</span><input id="appSearch" placeholder="App, Host oder Kategorie suchen…" value="${attr(state.appSearch)}"></div><div class="category-strip">${categoryButtons}</div></div>` +
      `<div class="${compact?'app-library-grid':'apps-grid'}">${cards || `<div class="empty-dashboard"><h3>Keine Apps gefunden</h3><p>Lege deine erste App an oder ändere den Filter.</p></div>`}</div>`;

    $('#addAppBtn').onclick=()=>openAppModal();
    $('#appSearch').addEventListener('input',e=>{state.appSearch=e.target.value;renderApps();requestAnimationFrame(()=>{$('#appSearch')?.focus();$('#appSearch')?.setSelectionRange(state.appSearch.length,state.appSearch.length)})});
    $$('[data-app-category]').forEach(b=>b.onclick=()=>{state.appCategory=b.dataset.appCategory;renderApps()});
    $$('[data-app-view]').forEach(b=>b.onclick=()=>{state.appView=b.dataset.appView;localStorage.setItem('pengulab-app-view',state.appView);renderApps()});
    $$('[data-edit-app]').forEach(b=>b.onclick=e=>{e.preventDefault();e.stopPropagation();openAppModal(all.find(a=>a.id===b.dataset.editApp))});
    $$('[data-add-app-widget]').forEach(b=>b.onclick=e=>{e.preventDefault();e.stopPropagation();createWidget({type:'app',config:{app_id:b.dataset.addAppWidget},w:2,h:1})});
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
    const integrations=state.boot.integrations||[];const types=state.boot.integrationTypes||[];
    appRoot.innerHTML=pageHead('Connections','Integrationen','Zugangsdaten bleiben in PenguLab. Widgets sprechen nie direkt aus dem Browser mit deinen Diensten.', types.length?`<button class="btn primary" id="addIntegration">+ Verbindung</button>`:`<a class="btn primary" href="#hub">PenguHub öffnen</a>`)+
      (types.length?`<div class="integration-list">${integrations.map(i=>`<article class="section-card integration-card"><div class="integration-top"><div class="package-icon" data-letter="${attr(initials(i.name))}"></div><div><div class="integration-name">${esc(i.name)}</div><div class="integration-type">${esc(i.type)}</div></div><div class="integration-status ${attr(i.last_status)}"><span class="status-dot"></span>${esc(i.last_status==='unknown'?'Nicht getestet':i.last_status)}</div></div><div class="integration-url">${esc(i.base_url)}</div>${i.last_error?`<div style="font-size:10px;color:var(--danger);margin-bottom:10px">${esc(i.last_error)}</div>`:''}<div class="integration-actions"><button class="btn small soft" data-test-integration="${attr(i.id)}">Testen</button><button class="btn small" data-widget-integration="${attr(i.id)}">Widget</button><button class="btn small" data-edit-integration="${attr(i.id)}">Bearbeiten</button></div></article>`).join('') || `<div class="empty-dashboard" style="grid-column:1/-1"><h3>Noch keine Verbindung</h3><p>Füge Pi-hole, AdGuard Home, OPNsense oder eine Generic API hinzu.</p></div>`}</div>`:`<div class="empty-dashboard"><h3>Keine Integrationspakete installiert</h3><p>Installiere im PenguHub zunächst Pi-hole, AdGuard Home, OPNsense oder Generic API.</p><a class="btn primary" href="#hub">PenguHub öffnen</a></div>`);
    $('#addIntegration')?.addEventListener('click',()=>openIntegrationTypePicker());
    $$('[data-test-integration]').forEach(b=>b.onclick=async()=>{b.disabled=true;b.textContent='Teste…';try{const d=await api('integrations/test',{body:{id:b.dataset.testIntegration}});state.boot.integrations=d.integrations;renderIntegrations();toast('Verbindung erfolgreich.');}catch(e){b.disabled=false;b.textContent='Testen';toast(e.message,'error')}});
    $$('[data-edit-integration]').forEach(b=>b.onclick=()=>openIntegrationModal(integrations.find(i=>i.id===b.dataset.editIntegration)));
    $$('[data-widget-integration]').forEach(b=>{b.onclick=()=>{const i=integrations.find(x=>x.id===b.dataset.widgetIntegration);const cat=(state.boot.widgetCatalog||[]).find(c=>c.type==='integration-summary'&&c.integrationType===i?.type);const s=cat?.defaultSize||[4,2];createWidget({type:'integration-summary',title:i?.name||'',config:{integration_id:i.id},w:s[0],h:s[1]});}});
  }

  function openIntegrationTypePicker(){const types=state.boot.integrationTypes||[];showModal('Verbindung hinzufügen','Installierte PenguHub-Connectoren.',`<div class="widget-picker">${types.map(t=>`<button class="widget-choice" data-integration-type="${attr(t.type)}"><strong>${esc(t.name)}</strong><span>${esc(t.description||'')}</span></button>`).join('')}</div>`,'',modal=>{$$('[data-integration-type]',modal).forEach(b=>b.onclick=()=>{closeModal();openIntegrationModal(null,b.dataset.integrationType)})});}

  function openIntegrationModal(existing=null, forcedType='') {
    const type=forcedType||existing?.type;const info=(state.boot.integrationTypes||[]).find(t=>t.type===type);if(!info){toast('Connector nicht installiert.','error');return;}
    const fields=(info.fields||[]).map(f=>integrationFieldHtml(f,existing)).join('');
    showModal(existing?'Verbindung bearbeiten':`${info.name} verbinden`,'Zugangsdaten werden serverseitig verschlüsselt gespeichert.',`<div class="field-row"><label>Name</label><input id="integrationName" value="${attr(existing?.name||info.name)}"></div>${fields}`,`<button class="btn" data-close-modal>Abbrechen</button>${existing?`<button class="btn danger" id="deleteIntegration">Löschen</button>`:''}<button class="btn primary" id="saveIntegration">Speichern</button>`,modal=>{
      $('#saveIntegration',modal).onclick=async()=>{const payload={id:existing?.id,type,name:$('#integrationName',modal).value,secrets:{}};for(const f of info.fields||[]){const el=$(`[data-field="${CSS.escape(f.key)}"]`,modal);if(!el)continue;let value=f.type==='boolean'?el.checked:el.value;if(f.secret)payload.secrets[f.key]=value;else payload[f.key]=value;}try{const d=await api('integrations/save',{body:payload});state.boot.integrations=d.integrations;closeModal();renderIntegrations();toast('Integration gespeichert.');}catch(e){toast(e.message,'error')}};
      $('#deleteIntegration',modal)?.addEventListener('click',async()=>{if(!confirm('Integration löschen?'))return;try{const d=await api('integrations/delete',{body:{id:existing.id}});state.boot.integrations=d.integrations;closeModal();renderIntegrations();toast('Integration gelöscht.');}catch(e){toast(e.message,'error')}});
    });
  }

  function integrationFieldHtml(field,existing){const k=field.key;if(k==='base_url'){return `<div class="field-row"><label>${esc(field.label)}</label><input data-field="${attr(k)}" type="url" value="${attr(existing?.base_url||'')}" placeholder="${attr(field.placeholder||'')}"></div>`;}if(k==='username'){return `<div class="field-row"><label>${esc(field.label)}</label><input data-field="${attr(k)}" value="${attr(existing?.username||'')}"></div>`;}if(k==='verify_tls'){const checked=existing?existing.verify_tls:(field.default!==false);return `<label class="setting-line"><span class="setting-copy"><strong>${esc(field.label)}</strong><p>Bei internen Self-Signed-Zertifikaten kann dies deaktiviert werden.</p></span><input data-field="${attr(k)}" type="checkbox" ${checked?'checked':''}></label>`;}if(field.secret){const has=existing?.has_secrets?.[k];return `<div class="field-row"><label>${esc(field.label)}${has?' · gespeichert':''}</label><input data-field="${attr(k)}" type="password" autocomplete="new-password" placeholder="${has?'Leer lassen = behalten':''}"></div>`;}return `<div class="field-row"><label>${esc(field.label)}</label><input data-field="${attr(k)}" value=""></div>`;}

  function renderHub() {
    const addons=state.boot.addons||[];
    appRoot.innerHTML=pageHead('Extensions','PenguHub','Funktionen werden als klar getrennte Pakete installiert. Der PenguLab-Core bleibt klein und stabil.')+
      `<section class="section-card hub-hero"><div class="hub-hero-icon">P</div><div><h2>Baue dir genau dein PenguLab</h2><p>IP Management, DNS-Monitoring, Firewall-Status, RSS und API-Anbindungen sind Erweiterungen – nicht fest im Dashboard verdrahtet.</p></div></section>`+
      `<div class="hub-grid">${addons.map(a=>`<article class="section-card hub-card"><div class="hub-head"><div class="package-icon" data-letter="${attr(initials(a.name))}"></div><div><div class="hub-name">${esc(a.name)}</div><div class="hub-meta">${esc(a.category||'Addon')} · v${esc(a.version)}</div></div><div class="hub-badges">${a.enabled?'<span class="badge installed">Installed</span>':'<span class="badge">Available</span>'}</div></div><div class="hub-description">${esc(a.description||'')}</div><div class="hub-permissions">${(a.permissions||[]).map(p=>esc(p)).join(' · ')}</div><div class="hub-actions">${a.enabled?(a.id==='ipmanager'?`<a class="btn small soft" href="?addon=ipmanager">Öffnen</a>`:'')+`<button class="btn small" data-uninstall-addon="${attr(a.id)}">Deaktivieren</button>`:`<button class="btn small primary" data-install-addon="${attr(a.id)}">Installieren</button>`}</div></article>`).join('')}</div>`;
    $$('[data-install-addon]').forEach(b=>b.onclick=async()=>{b.disabled=true;b.textContent='Installiere…';try{await api('addons/install',{body:{id:b.dataset.installAddon}});toast('Paket installiert.');location.reload();}catch(e){b.disabled=false;toast(e.message,'error')}});
    $$('[data-uninstall-addon]').forEach(b=>b.onclick=async()=>{if(!confirm('Paket deaktivieren? Daten bleiben erhalten.'))return;try{await api('addons/uninstall',{body:{id:b.dataset.uninstallAddon}});toast('Paket deaktiviert.');location.reload();}catch(e){toast(e.message,'error')}});
  }

  function renderSettings() {
    const s=state.boot.settings||{};
    appRoot.innerHTML=pageHead('System','Einstellungen','Aussehen, Dashboard-Name und Backups.')+`<div class="settings-grid"><section class="section-card settings-card"><h3>Appearance</h3><p>Theme wird für alle Browser dieser PenguLab-Instanz gespeichert.</p><div class="segmented" id="themeSegment"><button data-theme-value="system" class="${s.theme==='system'?'active':''}">System</button><button data-theme-value="light" class="${s.theme==='light'?'active':''}">Light</button><button data-theme-value="dark" class="${s.theme==='dark'?'active':''}">Dark</button></div><div class="field-row" style="margin-top:18px"><label>Dashboard Titel</label><input id="dashboardTitle" value="${attr(s.dashboard_title||'My Homelab')}"></div><button class="btn primary" id="saveGeneral">Speichern</button></section><section class="section-card settings-card"><h3>Backup & Migration</h3><p>JSON-Export enthält Apps, Layout und Konfiguration, aber bewusst keine Zugangsdaten. Für ein vollständiges Backup das gesamte <code>data/</code>-Verzeichnis sichern.</p><div style="display:flex;gap:8px;flex-wrap:wrap"><button class="btn" id="exportBtn">JSON exportieren</button><label class="btn" for="importFile">JSON importieren</label><input id="importFile" type="file" accept="application/json" hidden></div><div class="setting-line" style="margin-top:15px"><span class="setting-copy"><strong>Database</strong><p>SQLite · /data/pengulab.sqlite</p></span><span class="badge installed">Active</span></div><div class="setting-line"><span class="setting-copy"><strong>Secrets</strong><p>libsodium SecretBox · separater secret.key</p></span><span class="badge installed">Encrypted</span></div></section></div>`;
    $$('[data-theme-value]').forEach(b=>b.onclick=async()=>{const theme=b.dataset.themeValue;try{const d=await api('settings/save',{body:{theme}});state.boot.settings=d.settings;applyTheme(theme);renderSettings();}catch(e){toast(e.message,'error')}});
    $('#saveGeneral').onclick=async()=>{try{const d=await api('settings/save',{body:{dashboard_title:$('#dashboardTitle').value}});state.boot.settings=d.settings;toast('Gespeichert.');}catch(e){toast(e.message,'error')}};
    $('#exportBtn').onclick=async()=>{try{const d=await api('export');const blob=new Blob([JSON.stringify(d.data,null,2)],{type:'application/json'});const url=URL.createObjectURL(blob);const a=document.createElement('a');a.href=url;a.download=`pengulab-export-${new Date().toISOString().slice(0,10)}.json`;a.click();URL.revokeObjectURL(url);}catch(e){toast(e.message,'error')}};
    $('#importFile').onchange=async e=>{const file=e.target.files?.[0];if(!file)return;try{const data=JSON.parse(await file.text());await api('import',{body:{data}});await refreshBoot();toast('Import abgeschlossen.');}catch(err){toast(err.message,'error')}};
  }

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

  window.addEventListener('hashchange',()=>{setViewFromHash();state.editMode=false;render();$('#sidebar')?.classList.remove('open')});
  boot();
})();
