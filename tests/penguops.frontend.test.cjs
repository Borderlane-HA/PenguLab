'use strict';
const {chromium}=require('playwright'),fs=require('node:fs'),path=require('node:path'),assert=require('node:assert/strict');
const root=path.resolve(__dirname,'..');
(async()=>{
 const browser=await chromium.launch({headless:true,executablePath:process.env.CHROMIUM_EXECUTABLE,args:['--disable-gpu','--no-zygote']});
 const page=await browser.newPage({viewport:{width:1440,height:1050}}),errors=[];page.on('pageerror',e=>errors.push(e.message));
 const now=Math.floor(Date.now()/1000);let profiles=[],jobs=[],previewed=false,analyzed=false,savedRule=false;
 const bases={ollama:'http://127.0.0.1:11434',openai:'https://api.openai.com/v1',anthropic:'https://api.anthropic.com/v1',gemini:'https://generativelanguage.googleapis.com/v1beta/openai',ionos:'https://openai.inference.de-txl.ionos.com/v1',xai:'https://api.x.ai/v1'};
 const report={score:80,assessed:2,total:3,critical:1,warning:1,unknown:1,maintenance:0,worker_at:now,services:[{id:'pve',name:'Proxmox Server01',type:'proxmox',status:'critical',age:15,observed_at:now-15,checks:5,issues:[{severity:'critical',message:'Speicher local-lvm: 96 %'}]},{id:'ha',name:'Home Assistant',type:'homeassistant',status:'warning',age:20,observed_at:now-20,checks:2,issues:[{severity:'warning',message:'sensor.garage ist unavailable.'}]},{id:'dns',name:'Pi-hole',type:'pihole',status:'unknown',age:400,observed_at:now-400,checks:0,issues:[]}],incidents:[{id:'i1',integration_id:'pve',message:'Speicher local-lvm: 96 %',first_seen:now-900,last_seen:now-15,resolved_at:null}]};
 await page.route('http://ops.test/**',async route=>{
  const url=new URL(route.request().url());
  if(url.pathname==='/api.php'){
   const action=url.searchParams.get('route').split('/').pop();const b=route.request().method()==='POST'?route.request().postDataJSON():{};let data={ok:true};
   if(action==='report')data.report=report;
   else if(action==='settings')Object.assign(data,{bases,profiles,rules:[{id:'pve',name:'Proxmox Server01',type:'proxmox',rules:{enabled:true,warning:85,critical:95,required:['100'],entities:[],entity_grace:300,maintenance_until:0}}]});
   else if(action==='rules'){assert.equal(b.rules.critical,97);savedRule=true;}
   else if(action==='profile-save'){assert.equal(b.provider,'ionos');profiles=[{...b,id:'ai1',has_key:true}];delete profiles[0].api_key;}
   else if(action==='models')data.models=['model-test'];
   else if(action==='preview'){previewed=true;Object.assign(data,{profile:profiles[0],token:'preview-token',context:report});}
   else if(action==='analyze'){assert.ok(previewed);assert.equal(b.token,'preview-token');analyzed=true;jobs=[{id:'job1',status:'done',created_at:now,result:'<script>window.injection=1</script>\nHypothese: Storage prüfen.',usage_json:'{"model":"model-test","usage":{"total_tokens":25}}'}];}
   else if(action==='jobs')data.jobs=jobs;
   return route.fulfill({contentType:'application/json',body:JSON.stringify(data)});
  }
  if(url.pathname==='/'){
   let content=fs.readFileSync(path.join(root,'addons/penguops/page.php'),'utf8').replace(/<\?php[\s\S]*?\?>/g,'').replace(/<\?=[\s\S]*?\?>/g,'').replace('window.OPS_CONFIG=;','window.OPS_CONFIG={csrf:"fixture",admin:true};');
   return route.fulfill({headers:{'content-type':'text/html; charset=utf-8'},body:'<!doctype html><html><head><meta name="viewport" content="width=device-width,initial-scale=1"><link rel="stylesheet" href="assets/css/app.css"></head><body><main style="padding:32px">'+content+'</main></body></html>'});
  }
  const file=path.join(root,url.pathname);if(fs.existsSync(file))return route.fulfill({body:fs.readFileSync(file),contentType:file.endsWith('.css')?'text/css':file.endsWith('.js')?'text/javascript':'application/octet-stream'});return route.fulfill({status:404,body:''});
 });
 await page.goto('http://ops.test/');await page.getByText('80 / 100').waitFor();assert.equal(await page.locator('#opsHealth .ops-card').count(),3);
 const screenshotDir=process.env.OPS_SCREENSHOT_DIR||require('node:os').tmpdir();fs.mkdirSync(screenshotDir,{recursive:true});
 await page.screenshot({path:path.join(screenshotDir,'penguops-health.png'),fullPage:true});
 await page.getByRole('button',{name:'Verlauf',exact:true}).click();await page.getByRole('heading',{name:'Befundverlauf'}).waitFor();
 await page.getByRole('button',{name:'Regeln',exact:true}).click();await page.locator('[name=critical]').fill('97');await page.getByRole('button',{name:'Regeln speichern'}).click();await page.waitForFunction(()=>document.querySelector('#opsNotice').textContent.includes('gespeichert'));assert.ok(savedRule);
 await page.getByRole('button',{name:'AI Model Hub',exact:true}).click();await page.getByRole('button',{name:'+ KI-Profil'}).click();
 await page.locator('[name=name]').fill('IONOS Test');await page.locator('[name=provider]').selectOption('ionos');assert.equal(await page.locator('[name=base_url]').inputValue(),bases.ionos);
 await page.locator('[name=api_key]').fill('secret-fixture');await page.locator('[name=model]').fill('model-test');await page.getByRole('button',{name:'Profil speichern'}).click();await page.getByRole('button',{name:'Analyse vorbereiten'}).waitFor();
 await page.getByRole('button',{name:'Analyse vorbereiten'}).click();assert.equal(analyzed,false);await page.getByRole('button',{name:'Diese Daten analysieren'}).click();await page.getByText('Abgeschlossen',{exact:true}).waitFor();assert.ok(analyzed);assert.equal(await page.evaluate(()=>window.injection),undefined);assert.ok((await page.locator('.ops-analysis').textContent()).includes('<script>'));
 await page.screenshot({path:path.join(screenshotDir,'penguops-ai.png'),fullPage:true});
 await page.setViewportSize({width:390,height:844});await page.locator('[data-tab="health"]').click();await page.evaluate(()=>scrollTo(0,0));
 const mobile=await page.evaluate(()=>{const box=s=>{const r=document.querySelector(s).getBoundingClientRect();return {left:r.left,right:r.right,width:r.width}};return {scrollX,scrollWidth:document.documentElement.scrollWidth,viewport:innerWidth,wrap:box('#penguOps'),title:box('#penguOps h1'),stats:box('.ops-stats')}});
 assert.ok(mobile.scrollWidth<=mobile.viewport+2,'mobile page overflow');assert.ok(mobile.wrap.left>=0&&mobile.wrap.right<=mobile.viewport+2,'mobile wrapper clipped');assert.ok(mobile.title.left>=0&&mobile.title.right<=mobile.viewport+2,'mobile title clipped');assert.ok(mobile.stats.left>=0&&mobile.stats.right<=mobile.viewport+2,'mobile stats clipped');
 await page.screenshot({path:path.join(screenshotDir,'penguops-mobile.png'),fullPage:true});
 assert.deepEqual(errors,[]);await browser.close();console.log('PenguOps browser: health, timeline, rules, six-provider selector, profile save, explicit data preview, analysis, XSS escaping and mobile passed.');
})().catch(e=>{console.error(e);process.exit(1)});
