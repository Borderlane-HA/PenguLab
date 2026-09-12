const {chromium}=require('playwright');
const fs=require('fs'),path=require('path'),http=require('http'),assert=require('assert/strict');
const root=path.resolve(__dirname,'..');
const html=`<!doctype html><html lang="de" data-theme="light"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><link rel="stylesheet" href="/assets/css/app.css"></head><body><div class="shell"><aside class="sidebar" id="sidebar"><div class="brand"><b>PenguLab</b></div><a class="nav-item" href="#dashboard">Dashboard</a><a class="nav-item" href="#integrations">Integrationen</a></aside><div class="workspace"><header class="topbar"><button id="globalSearchButton">Suchen</button><span id="healthText">PenguLab ready</span></header><main class="main" id="app"></main></div></div><script>window.PENGULAB={api:'/api.php'};</script><script src="/assets/js/layout.js"></script><script src="/assets/js/app.js"></script></body></html>`;
const server=http.createServer((req,res)=>{let file=path.join(root,req.url.split('?')[0]);if(req.url==='/'){res.setHeader('Content-Type','text/html');return res.end(html);}if(fs.existsSync(file)&&fs.statSync(file).isFile()){res.setHeader('Content-Type',file.endsWith('.css')?'text/css':'text/javascript');return res.end(fs.readFileSync(file));}res.statusCode=404;res.end();});
(async()=>{
 await new Promise(r=>server.listen(0,'127.0.0.1',r));const browser=await chromium.launch({headless:true,...(process.env.CHROMIUM_EXECUTABLE?{executablePath:process.env.CHROMIUM_EXECUTABLE}:{}),args:['--disable-gpu','--no-zygote']});const page=await browser.newPage({viewport:{width:1800,height:1100}});const errors=[];page.on('pageerror',e=>errors.push(e.message));
 await page.addInitScript(()=>{const realSetTimeout=window.setTimeout,realClearTimeout=window.clearTimeout,realSetInterval=window.setInterval,realClearInterval=window.clearInterval;window.testTimers=new Set();window.setTimeout=(fn,ms,...args)=>{let id=realSetTimeout(()=>{window.testTimers.delete(id);fn(...args);},ms);if(ms>=5000)testTimers.add(id);return id;};window.clearTimeout=id=>{testTimers.delete(id);realClearTimeout(id);};window.setInterval=(fn,ms,...args)=>{const id=realSetInterval(fn,ms,...args);if(ms>=5000)testTimers.add(id);return id;};window.clearInterval=id=>{testTimers.delete(id);realClearInterval(id);};});
 const integrations=[{id:'ha',type:'homeassistant',name:'Home Assistant',enabled:true,config:{refresh_interval:60}},{id:'io',type:'iobroker',name:'ioBroker',enabled:true,config:{refresh_interval:60}},{id:'nr',type:'nodered',name:'Node-RED',enabled:true,config:{refresh_interval:60}}];
 const entities=[{entity_id:'demo.switch',name:'Demo Schalter',domain:'switch',value_type:'boolean',writable:true,value:false,state:'off'},{entity_id:'demo.setpoint',name:'Sollwert',domain:'number',value_type:'number',writable:true,value:20,state:'20',min:5,max:30,unit:'°C'}];
 let widgets=[{id:'clock',type:'clock',x:0,y:0,w:26,h:10,config:{}},{id:'ha-widget',type:'homeassistant-entities',x:30,y:0,w:20,h:10,config:{integration_id:'ha',entity_ids:['sensor.pv']}},{id:'io-widget',type:'automation-entities',x:54,y:0,w:36,h:18,config:{integration_id:'io',entity_ids:entities.map(e=>e.entity_id)}},{id:'nr-widget',type:'automation-entities',x:94,y:0,w:36,h:18,config:{integration_id:'nr',entity_ids:entities.map(e=>e.entity_id)}}];
 const apps=Array.from({length:146},(_,i)=>({id:'app'+i,name:'App '+i,url:'https://example.com',category:'Homelab'}));
 widgets.push(...apps.map((app,i)=>({id:app.id,type:'app',x:(i%9)*16,y:22+Math.floor(i/9)*12,w:14,h:10,config:{app_id:app.id}})));
 let dataCalls=0,actions=[],groups=[];
 const boot=()=>({ok:true,csrf:'test',version:'2.10.0',settings:{theme:'light',layout_engine:'canvas8',dashboard_title:'Homelab · 150 Widgets'},user:{role:'admin'},widgets,apps,integrations,widgetCatalog:[],integrationTypes:[],addons:[]});
 await page.route('**/api.php?**',async route=>{const url=new URL(route.request().url()),key=url.searchParams.get('route');let response={ok:true};
  if(key==='bootstrap')response=boot();
  else if(key==='widgets/data'){dataCalls++;const w=widgets.find(w=>w.id===url.searchParams.get('id'));response.data=w.type==='app'?{kind:'app',app:apps.find(a=>a.id===w.config.app_id)}:w.type==='homeassistant-entities'?{kind:'homeassistant',entities:[{entity_id:'sensor.pv',name:'PV-Gesamt',domain:'sensor',state:'1.567',unit:'kW'}]}:{kind:'automation',entities};if(url.searchParams.has('cached'))response.data.cached=true;}
  else if(key==='widgets/layout'){const p=route.request().postDataJSON();widgets=widgets.map(w=>({...w,...p.widgets.find(x=>x.id===w.id)}));response={...boot()};}
  else if(key==='automation/entities'||key==='homeassistant/entities')response.entities=entities;
  else if(key==='automation/action'){actions.push(route.request().postDataJSON());}
  else if(key==='widgets/group'){const p=route.request().postDataJSON();groups.push(p);const a=widgets.find(w=>w.id===p.source_widget_id),b=widgets.find(w=>w.id===p.target_widget_id);widgets=widgets.filter(w=>w.id!==a.id).map(w=>w.id===b.id?{...w,type:'app-group',title:'Testgruppe',config:{app_ids:[b.config.app_id,a.config.app_id]}}:w);response.widgets=widgets;}
  else if(key==='widgets/update'){const p=route.request().postDataJSON();widgets=widgets.map(w=>w.id===p.id?{...w,...p}:w);response.widgets=widgets;}
  await route.fulfill({json:response});
 });
 await page.goto('http://127.0.0.1:'+server.address().port);await page.waitForFunction(()=>document.querySelectorAll('.widget-loading').length===0);await page.waitForTimeout(150);
 const dims=()=>page.evaluate(()=>Array.from(document.querySelectorAll('.widget')).slice(0,6).map(el=>{const body=el.querySelector('.widget-body'),r=body.getBoundingClientRect();return [el.dataset.widgetId,r.width,r.height,getComputedStyle(body).padding];}));
 const initial=await dims(),timers=await page.evaluate(()=>testTimers.size);const calls=dataCalls;let cycleCalls=0;
 for(let i=0;i<12;i++){await page.click('#editLayoutBtn');assert.deepEqual(await dims(),initial);await page.click(i%2?'#saveLayoutBtn':'#cancelLayoutBtn');}
 assert.equal(await page.evaluate(()=>testTimers.size),timers);assert.equal(dataCalls,calls,'mode changes should preserve data without request bursts');cycleCalls=dataCalls-calls;
 await page.click('#editLayoutBtn');await page.screenshot({path:path.join(require('os').tmpdir(),'pengulab-editor-preview.png')});
 const card=page.locator('[data-widget-id="clock"]');const r=await card.boundingBox();
 await page.mouse.move(r.x+r.width/2,r.y+r.height/2);await page.mouse.down();await page.mouse.move(r.x+r.width/2+242,r.y+r.height/2+150,{steps:20});await page.mouse.up();
 assert.equal(await page.locator('.layout-guides').count(),0);
 const beforeSave=await card.evaluate(el=>[el.style.getPropertyValue('--cx'),el.style.getPropertyValue('--cy'),el.style.getPropertyValue('--cw'),el.style.getPropertyValue('--ch')]);
 await page.click('#saveLayoutBtn');assert.deepEqual(await card.evaluate(el=>[el.style.getPropertyValue('--cx'),el.style.getPropertyValue('--cy'),el.style.getPropertyValue('--cw'),el.style.getPropertyValue('--ch')]),beforeSave);
 await page.click('#editLayoutBtn');const pos=await card.boundingBox();await page.mouse.move(pos.x+80,pos.y+40);await page.mouse.down();await page.mouse.move(pos.x+200,pos.y+80);await page.keyboard.press('Escape');await page.mouse.up();assert.deepEqual(await card.evaluate(el=>[el.style.getPropertyValue('--cx'),el.style.getPropertyValue('--cy'),el.style.getPropertyValue('--cw'),el.style.getPropertyValue('--ch')]),beforeSave);
 await page.click('[data-widget-id="io-widget"] .widget-settings');await page.waitForSelector('#haWidgetTitle');assert.match(await page.locator('.modal').innerText(),/ioBroker/);await page.click('[data-close-modal]');await page.click('#cancelLayoutBtn');
 await page.click('[data-widget-id="io-widget"] [data-auto-action]');assert.equal(actions[0].value,true);
 await page.locator('[data-widget-id="nr-widget"] input').fill('23');await page.locator('[data-widget-id="nr-widget"] input').press('Tab');await page.waitForTimeout(100);assert.equal(actions[1].value,23);
 await page.click('#editLayoutBtn');
 // Resize, then verify the same geometry is preserved on save.
 const grip=page.locator('[data-widget-id="clock"] .widget-resize');let gr=await grip.boundingBox();
 await page.mouse.move(gr.x+15,gr.y+15);await page.mouse.down();await page.mouse.move(gr.x+79,gr.y+47,{steps:8});await page.mouse.up();
 const resized=await card.evaluate(el=>[el.style.getPropertyValue('--cw'),el.style.getPropertyValue('--ch')]);
 await page.click('#saveLayoutBtn');assert.deepEqual(await card.evaluate(el=>[el.style.getPropertyValue('--cw'),el.style.getPropertyValue('--ch')]),resized);
 await page.click('#editLayoutBtn');await card.hover();await page.click('[data-widget-id="clock"] .widget-geometry');await page.fill('#geometry-w','256');await page.click('#applyGeometry');assert.equal(await card.evaluate(el=>el.style.getPropertyValue('--cw')),'32');
 // A short app hover must not form a group; a deliberate hold does.
 const a=page.locator('[data-widget-id="app0"]'),b=page.locator('[data-widget-id="app1"]');
 const hoverApp=async()=>{const ar=await a.boundingBox(),br=await b.boundingBox();await page.mouse.move(ar.x+ar.width/2,ar.y+ar.height/2);await page.mouse.down();await page.mouse.move(br.x+br.width/2,br.y+br.height/2,{steps:4});};
 await hoverApp();await page.waitForTimeout(80);await page.mouse.up();assert.equal(groups.length,0);
 await hoverApp();await page.waitForTimeout(800);await page.mouse.up();await page.waitForFunction(()=>document.querySelector('.app-group-widget'));assert.equal(groups.length,1);
 await page.click('#saveLayoutBtn');
 await page.setViewportSize({width:390,height:844});await page.reload();await page.waitForFunction(()=>!document.querySelector('.widget-loading'));await page.click('#editLayoutBtn');await page.screenshot({path:path.join(require('os').tmpdir(),'pengulab-mobile-preview.png')});await page.click('#cancelLayoutBtn');
 assert.deepEqual(errors,[]);console.log(JSON.stringify({widgets:widgets.length,modeCycles:12,timersBefore:timers,timersAfter:await page.evaluate(()=>testTimers.size),dataCallsDuringModeCycles:cycleCalls,actions:actions.length,groups:groups.length,errors}));
 await browser.close();server.close();
})().catch(e=>{console.error(e);server.close();process.exit(1)});
