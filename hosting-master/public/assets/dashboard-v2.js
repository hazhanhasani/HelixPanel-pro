'use strict';
// Progressive enhancements only. Existing PHP forms keep working without JS.
(() => {
  const body=document.body;
  const toggle=document.getElementById('mobile-menu-toggle');
  const shade=document.getElementById('mobile-shade');
  const sidebar=document.getElementById('master-sidebar');
  const setMenu=(open)=>{
    body.classList.toggle('sidebar-open',open);
    toggle?.setAttribute('aria-expanded',String(open));
    if(shade)shade.setAttribute('aria-hidden',String(!open));
  };
  toggle?.addEventListener('click',()=>setMenu(!body.classList.contains('sidebar-open')));
  shade?.addEventListener('click',()=>setMenu(false));
  document.addEventListener('keydown',(event)=>{
    if(event.key==='Escape')setMenu(false);
    if((event.ctrlKey||event.metaKey)&&event.key.toLowerCase()==='k'){
      event.preventDefault();document.getElementById('user-search')?.focus();
    }
  });
  sidebar?.querySelectorAll('a[href^="#"]').forEach(link=>{
    link.addEventListener('click',()=>{
      sidebar.querySelectorAll('a').forEach(el=>el.classList.remove('current'));
      link.classList.add('current');setMenu(false);
    });
  });
  for(const [inputId,tableId,filterId] of [
    ['user-search','users-table','user-filter'],
    ['node-search','nodes-table','node-filter']
  ]){
    const input=document.getElementById(inputId);
    const table=document.getElementById(tableId);
    const select=document.getElementById(filterId);
    if(!input||!table)continue;
    const rows=Array.from(table.querySelectorAll('tbody tr[data-search]'));
    const count=document.getElementById(tableId+'-count');
    const empty=document.getElementById(tableId+'-no-results');
    const apply=()=>{
      const search=input.value.trim().toLocaleLowerCase('fa-IR');
      const status=select?.value||'all';
      let visible=0;
      for(const row of rows){
        const matches=(!search||(row.dataset.search||'').toLocaleLowerCase('fa-IR').includes(search))
          &&(status==='all'||row.dataset.status===status);
        row.classList.toggle('hidden-row',!matches);
        if(matches)visible++;
      }
      if(count)count.textContent=new Intl.NumberFormat('fa-IR').format(visible);
      if(empty)empty.hidden=visible!==0||rows.length===0;
    };
    input.addEventListener('input',apply);
    select?.addEventListener('change',apply);
    apply();
  }
  document.querySelectorAll('form[data-confirm]').forEach(form=>{
    form.addEventListener('submit',event=>{
      if(!window.confirm(form.dataset.confirm||'از انجام عملیات مطمئن هستید؟'))event.preventDefault();
    });
  });
  const date=new Date();
  const calendar=document.getElementById('current-date');
  if(calendar&&Number.isFinite(date.valueOf())){
    calendar.textContent=new Intl.DateTimeFormat('fa-IR-u-ca-persian',{year:'numeric',month:'long',day:'numeric'}).format(date);
  }
  const announce=document.getElementById('live-region');
  document.querySelectorAll('button[data-copy-target]').forEach(button=>{
    button.addEventListener('click',async()=>{
      const target=document.getElementById(button.dataset.copyTarget||'');
      if(!target)return;
      try{
        await navigator.clipboard.writeText(target.textContent||'');
        if(announce)announce.textContent='کپی شد';
      }catch{if(announce)announce.textContent='امکان کپی وجود ندارد'}
    });
  });
})();
