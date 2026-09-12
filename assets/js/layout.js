/* Geometry is expressed in existing 8px canvas units: saved layouts need no migration. */
(function(root){
  'use strict';
  const overlap=(a,b)=>a.id!==b.id&&a.x<b.x+b.w&&a.x+a.w>b.x&&a.y<b.y+b.h&&a.y+a.h>b.y;
  function nearestSlot(source,occupied,cols){
    const maxX=Math.max(0,cols-source.w), candidates=[];
    const add=(x,y)=>{if(x>=0&&x<=maxX&&y>=0)candidates.push({...source,x,y});};
    add(Math.min(source.x,maxX),source.y);
    for(const o of occupied){add(o.x-source.w,source.y);add(o.x+o.w,source.y);add(source.x,o.y-source.h);add(Math.min(source.x,maxX),o.y+o.h);}
    // Guaranteed free fallback below the occupied layout, even for very tall cards.
    add(Math.min(source.x,maxX),occupied.reduce((y,o)=>Math.max(y,o.y+o.h),0));
    candidates.sort((a,b)=>(Math.abs(a.x-source.x)+Math.abs(a.y-source.y))-(Math.abs(b.x-source.x)+Math.abs(b.y-source.y))||a.y-b.y||a.x-b.x);
    return candidates.find(c=>!occupied.some(o=>overlap(c,o)));
  }
  function reflow(id,candidate,base,widgets,cols){
    const active={...base[id],...candidate,id},result={[id]:active},occupied=[active],displaced=[];
    // Reserve every unaffected neighbour before placing displaced cards.
    for(const w of widgets){if(w.id===id)continue;const p=base[w.id];if(overlap(active,p))displaced.push(p);else{result[w.id]={...p};occupied.push(p);}}
    displaced.sort((a,b)=>a.y-b.y||a.x-b.x||String(a.id).localeCompare(String(b.id)));
    for(const p of displaced){const slot=nearestSlot(p,occupied,cols);result[p.id]=slot;occupied.push(slot);}
    return result;
  }
  function targets(base,id){
    const others=Object.values(base).filter(w=>w.id!==id),xs=[],ys=[];
    for(const w of others){for(const value of [w.x,w.x+w.w/2,w.x+w.w])xs.push(value);for(const value of [w.y,w.y+w.h/2,w.y+w.h])ys.push(value);}
    // Infer existing gaps only from immediate neighbours on overlapping rows/columns.
    const gx=new Set([2]),gy=new Set([2]);
    for(const a of others){let right=Infinity,below=Infinity;for(const b of others){if(a.id===b.id)continue;if(a.y<b.y+b.h&&a.y+a.h>b.y&&b.x>=a.x+a.w)right=Math.min(right,b.x-a.x-a.w);if(a.x<b.x+b.w&&a.x+a.w>b.x&&b.y>=a.y+a.h)below=Math.min(below,b.y-a.y-a.h);}if(right>0&&right<=8)gx.add(right);if(below>0&&below<=8)gy.add(below);}
    return {others,xs:[...new Set(xs)],ys:[...new Set(ys)],gx:[...gx],gy:[...gy]};
  }
  function snap(raw,refs,resize,lock={},disabled=false){
    const out={...raw},guides=[];
    for(const axis of ['x','y']){
      const size=axis==='x'?'w':'h',key=resize?size:axis,values=[];
      const add=(v,line,label='')=>{if(Number.isInteger(v))values.push({v,line,label});};
      if(!disabled){
        for(const edge of refs[axis==='x'?'xs':'ys']){
          if(resize)add(edge-raw[axis],edge);
          else for(const offset of [0,raw[size]/2,raw[size]])add(edge-offset,edge);
        }
        for(const n of refs.others){
          if(resize)add(n[size],raw[axis]+n[size],axis==='x'?'Gleiche Breite':'Gleiche Höhe');
          else for(const gap of refs[axis==='x'?'gx':'gy']){add(n[axis]+n[size]+gap,n[axis]+n[size]+gap,`${gap*8} px Abstand`);add(n[axis]-gap-raw[size],n[axis]-gap,`${gap*8} px Abstand`);}
        }
      }
      let best=!disabled&&lock[key]&&Math.abs(lock[key].v-raw[key])<=2?lock[key]:null;
      if(!best){values.sort((a,b)=>Math.abs(a.v-raw[key])-Math.abs(b.v-raw[key]));best=values[0]&&Math.abs(values[0].v-raw[key])<=1.1?values[0]:null;}
      lock[key]=best;out[key]=best?best.v:Math.round(raw[key]);if(best)guides.push({axis,line:best.line,label:best.label});
    }
    return {position:out,guides};
  }
  root.PenguLayout={overlap,nearestSlot,reflow,targets,snap};
})(typeof window==='undefined'?globalThis:window);
