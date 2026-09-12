const assert=require('node:assert/strict');
require('../assets/js/layout.js');
const {overlap,reflow,targets,snap}=globalThis.PenguLayout;
function valid(map){const a=Object.values(map);for(let i=0;i<a.length;i++){assert.ok(a[i].x>=0&&a[i].y>=0);for(let j=i+1;j<a.length;j++)assert.equal(overlap(a[i],a[j]),false,`${a[i].id}/${a[j].id}`);}}
const widgets=[{id:'a',x:0,y:0,w:20,h:10},{id:'b',x:22,y:0,w:20,h:10},{id:'c',x:44,y:0,w:20,h:10}];
const base=Object.fromEntries(widgets.map(w=>[w.id,w]));
const moved=reflow('a',{x:22,y:0,w:20,h:10},base,widgets,100);valid(moved);assert.deepEqual(moved.c,base.c);
const refs=targets(base,'a');
assert.equal(snap({...base.a,x:22.8,y:13},refs,false,{}).position.x,22);
assert.equal(snap({...base.a,w:19.2},refs,true,{}).position.w,20);
assert.equal(snap({...base.a,x:22.8,y:13},refs,false,{},true).position.x,23);
// Hysteresis: hold the selected guide beyond its initial capture distance.
const lock={};snap({...base.a,x:22.8,y:13},refs,false,lock);assert.equal(snap({...base.a,x:23.6,y:13},refs,false,lock).position.x,22);
const dense=Array.from({length:150},(_,i)=>({id:String(i),x:(i%10)*22,y:Math.floor(i/10)*12,w:20,h:10}));
const denseBase=Object.fromEntries(dense.map(w=>[w.id,w]));
const times=[];
for(let i=0;i<250;i++){const start=performance.now();const result=reflow('0',{x:(i*7)%190,y:(i*3)%170,w:25,h:16},denseBase,dense,220);times.push(performance.now()-start);valid(result);}
times.sort((a,b)=>a-b);console.log(JSON.stringify({cases:250,widgets:150,reflowP95Ms:times[Math.floor(times.length*.95)]}));
