(() => {
  'use strict';
  const apiUrl = window.PENGULAB?.api || 'api.php';
  const csrf = window.PENGULAB?.csrf || '';
  async function saveCollapsed(collapsed){
    try{
      const u=new URL(apiUrl,location.href);u.searchParams.set('route','users/preference');
      await fetch(u,{method:'POST',headers:{'Content-Type':'application/json','X-PenguLab-CSRF':csrf},cache:'no-store',body:JSON.stringify({key:'sidebar_collapsed',value:collapsed})});
    }catch(_){ /* visual state still works; preference can retry next click */ }
  }
  function setCollapsed(collapsed){document.body.classList.toggle('sidebar-collapsed',collapsed);saveCollapsed(collapsed);}
  document.querySelectorAll('[data-sidebar-collapse]').forEach(b=>b.addEventListener('click',()=>setCollapsed(true)));
  document.querySelectorAll('[data-sidebar-open]').forEach(b=>b.addEventListener('click',()=>setCollapsed(false)));
  const mobile=document.getElementById('mobileMenu');
  if(mobile)mobile.addEventListener('click',()=>document.getElementById('sidebar')?.classList.toggle('open'));
})();
