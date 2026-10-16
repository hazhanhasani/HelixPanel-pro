'use strict';
const $ = (id) => document.getElementById(id);
let apiToken = '';
const hosted=document.body.dataset.helixMode==='hosted';
const csrf=document.querySelector('meta[name="helix-csrf"]')?.content||'';
const views=['overview','nodes','users','license'];
let state = {nodes: [], users: [], license: null};
let activeView = 'overview';
let createType = 'node';
const viewTitles = {
  overview: ['نمای کلی','مدیریت هوشمند زیرساخت','وضعیت سرویس، کاربران و منابع را از یک مرکز کنترل مدیریت کنید.'],
  nodes:['مدیریت نودها','مدیریت سرورها و نودها','ثبت سرور و آماده‌سازی برای اتصال امن Agent.'],
  users:['مشترکین','مدیریت اشتراک کاربران','ایجاد و پایش حساب‌های کاربری سرویس.'],
  license:['لایسنس','مدیریت مجوز محصول','وضعیت فعال‌سازی و ظرفیت اختصاص‌یافته به نصب فعلی.']
};
async function api(path, options={}) {
  const method=options.method||'GET';
  const endpoint=hosted?'./?hp_api='+encodeURIComponent(path):'/api/v1/'+path;
  const headers={
    ...(hosted?{'X-Helix-CSRF':csrf}:{Authorization:'Bearer '+apiToken}),
    ...(options.body?{'Content-Type':'application/json'}:{})
  };
  const response=await fetch(endpoint,{...options,method,cache:'no-store',credentials:'same-origin',headers});
  if(!response.ok){
    let code=String(response.status);
    try{code=(await response.json()).error||code}catch{}
    if(response.status===401 && hosted)window.location.reload();
    throw new Error(code);
  }
  return response.status===204?null:response.json();
}
function formatNum(n){return Number(n||0).toLocaleString('fa-IR');}
function errMessage(err){const m={unauthorized:'توکن دسترسی صحیح نیست.',license_limit_reached:'ظرفیت مجوز تکمیل شده است.',already_exists:'این نام قبلاً ثبت شده است.',invalid_resource:'اطلاعات نامعتبر است.',invalid_json:'فرمت درخواست نامعتبر است.'};return m[err.message]||'خطا: '+err.message;}
function showError(err){$('error').textContent=errMessage(err);$('error').classList.remove('hidden');}
function td(row,text){const cell=document.createElement('td');cell.textContent=String(text??'—');row.appendChild(cell);return cell;}
function renderTable(target, rows, columns, kind, deletable){
  const host=$(target);host.replaceChildren();
  if(!rows.length){const d=document.createElement('div');d.className='empty';d.textContent='هنوز موردی ثبت نشده است.';host.append(d);return;}
  const table=document.createElement('table');table.className='data-table';
  const thead=document.createElement('thead'),thRow=document.createElement('tr');
  [...columns.map(c=>c[0]),...(deletable?['عملیات']:[])].forEach(label=>{const th=document.createElement('th');th.textContent=label;thRow.appendChild(th);});thead.appendChild(thRow);table.appendChild(thead);
  const tbody=document.createElement('tbody');
  rows.forEach(obj=>{const tr=document.createElement('tr');columns.forEach(([name,key])=>{
    const c=td(tr,(key==='created_at'||key==='expires_at')&&obj[key]
      ?new Date(obj[key]).toLocaleDateString('fa-IR-u-ca-persian'):obj[key]);
    if(key==='status'){const label=document.createElement('span');label.className='pill';const values={online:'آنلاین',offline:'آفلاین',pending_enrollment:'در انتظار اتصال',pending_provisioning:'در انتظار ایجاد سرویس'};label.textContent=values[obj.status]||'نامشخص';if(obj.status==='online')label.classList.add('online');if(obj.status==='offline')label.classList.add('offline');c.replaceChildren(label);}
  });
  if(deletable){const cell=tr.insertCell();
  if(kind==='nodes'){
    const enroll=document.createElement('button');enroll.className='quiet';enroll.textContent='کد اتصال';
    enroll.title='با ساخت کد جدید، توکن Agent قبلی باطل می‌شود';
    enroll.addEventListener('click',async()=>{
      if(!confirm('کد اتصال جدید برای این نود صادر شود؟ اتصال Agent قبلی باطل می‌شود.'))return;
      try {
        const data=await api('nodes/'+encodeURIComponent(obj.id)+'/enrollment',{method:'POST'});
        alert('شناسه نود: '+data.node_id+'\\nکد اتصال (فقط یک بار): '+data.enrollment_token+'\\nاعتبار ۱۵ دقیقه');
        await refresh();
      }catch(err){showError(err)}
    });cell.appendChild(enroll);
  }
  const b=document.createElement('button');b.className='destructive';b.textContent='حذف';b.setAttribute('aria-label','حذف '+obj.name);b.addEventListener('click',async()=>{
    if(!confirm('«'+obj.name+'» حذف شود؟'))return;
    try{await api(kind+'/'+encodeURIComponent(obj.id),{method:'DELETE'});await refresh();}catch(err){showError(err);}
  });cell.appendChild(b);}
  tbody.appendChild(tr);});table.appendChild(tbody);host.appendChild(table);
}
const nodeCols=[['نام سرور','name'],['کشور','region'],['وضعیت','status'],['تاریخ ثبت','created_at']];
const userCols=[['نام مشترک','name'],['حجم (GB)','quota_gb'],['وضعیت','status'],['انقضا','expires_at']];
function filteredRows(kind, rows){
  const text=$(kind+'-search')?.value?.trim().toLocaleLowerCase('fa-IR')||'';
  const status=$(kind+'-filter')?.value||'all';
  const filtered=rows.filter(item=>(!text||
    [item.name,item.region,item.id].filter(Boolean).join(' ').toLocaleLowerCase('fa-IR').includes(text))&&
    (status==='all'||item.status===status));
  const counter=$(kind+'-result-count');
  if(counter)counter.textContent=formatNum(filtered.length)+' نتیجه از '+formatNum(rows.length);
  return filtered;
}
function fraction(id,used,max){$(id).textContent=formatNum(used)+' / '+formatNum(max);}
function fill(id,used,max){$(id).style.width=(max>0?Math.min(100,used/max*100):0)+'%';}
function render(){
  const l=state.license||{tier:'community',max_nodes:1,max_users:25};
  $('stat-nodes').textContent=formatNum(state.nodes.length);$('stat-users').textContent=formatNum(state.users.length);
  const onlineNodes=state.nodes.filter(n=>n.status==='online').length;
  $('stat-connected').textContent=formatNum(onlineNodes);
  $('fleet-health').textContent=formatNum(onlineNodes)+' نود آنلاین از '+formatNum(state.nodes.length);
  $('last-refresh').textContent=new Intl.DateTimeFormat('fa-IR-u-ca-persian',{dateStyle:'short',timeStyle:'short'}).format(new Date());
  $('stat-license').textContent=l.tier==='commercial'?'تجاری':'رایگان';
  $('stat-license-caption').textContent=l.active?'فعال':'نسخه پایه / محدود';
  fraction('nodes-fraction',state.nodes.length,l.max_nodes);fraction('users-fraction',state.users.length,l.max_users);
  fill('nodes-progress',state.nodes.length,l.max_nodes);fill('users-progress',state.users.length,l.max_users);
  renderTable('overview-nodes',state.nodes.slice(-5),nodeCols,'nodes',false);
  renderTable('overview-users',state.users.slice(-5),userCols,'users',false);
  renderTable('nodes-table',filteredRows('nodes',state.nodes),nodeCols,'nodes',true);
  renderTable('users-table',filteredRows('users',state.users),userCols,'users',true);
  const details=[['نوع مجوز',l.tier],['شناسه نصب',l.installation_id],['شناسه لایسنس',l.license_id||'—'],['حداکثر نود',formatNum(l.max_nodes)],['حداکثر کاربر',formatNum(l.max_users)],['انقضا',l.expires_at?new Date(l.expires_at*1000).toLocaleString('fa-IR'):'—'],['وضعیت',l.active?'فعال':'غیرفعال / رایگان'],['توضیح',l.reason||'—']];
  $('license-details').replaceChildren();for(const [k,v] of details){const dt=document.createElement('dt'),dd=document.createElement('dd');dt.textContent=k;dd.textContent=String(v||'—');$('license-details').append(dt,dd);}
}
// Same user-facing release widget in Go and cPanel builds. Hosted PHP owns
// the installation API; it verifies CI status and file hashes server-side.
async function checkUpdates(){
  if(!hosted)return;
  try{
    const status=await api('updates');
    const panel=$('update-banner');
    panel.hidden=!status.available;
    if(status.available){
      $('update-title').textContent='نسخه جدید HelixPanel در دسترس است';
      $('update-description').textContent='شناسه انتشار: '+(status.revision||'جدید')+' · قبل از نصب، از فایل‌های فعلی نسخه پشتیبان گرفته می‌شود.';
    }
  }catch{
    // Network problems must not block normal panel operation.
    $('update-banner').hidden=true;
  }
}
async function installUpdate(){
  if(!hosted)return;
  if(!window.confirm('بروزرسانی نسخه جدید با پشتیبان‌گیری انجام شود؟'))return;
  const button=$('update-install'),description=$('update-description');
  button.disabled=true;button.textContent='در حال نصب…';
  description.textContent='در حال دریافت، اعتبارسنجی و جایگزینی امن فایل‌های پنل. صفحه را نبندید.';
  try{
    const result=await api('updates/install',{method:'POST'});
    if(!result.ok)throw new Error(result.result||'update_failed');
    description.textContent='بروزرسانی موفق بود؛ در حال باز کردن نسخه جدید.';
    window.location.reload();
  }catch(error){
    description.textContent=errMessage(error)+' · نسخه قبلی در صورت شکست جایگزینی بازیابی می‌شود.';
    button.disabled=false;button.textContent='تلاش مجدد برای بروزرسانی';
  }
}
async function refresh(){
  $('error').classList.add('hidden');
  const [nodes,users,license]=await Promise.all([api('nodes'),api('users'),api('license')]);
  state={nodes,users,license};render();
}
function changeView(view,{history=true}={}){
  if(!views.includes(view))view='overview';
  activeView=view;
  for(const item of document.querySelectorAll('.view')){
    const active=item.id===view;
    item.classList.toggle('active',active);
    item.hidden=!active;
  }
  document.querySelectorAll('[data-view]').forEach(item=>{
    const active=item.dataset.view===view;
    item.classList.toggle('active',active);
    item.setAttribute('aria-current',active?'page':'false');
  });
  const [crumb,title,subtitle]=viewTitles[view];
  $('current-page').textContent=crumb;
  $('page-title').textContent=title;
  $('page-subtitle').textContent=subtitle;
  $('main-action').textContent=view==='users'?'+ افزودن مشترک':view==='license'?'بروزرسانی اطلاعات':'+ افزودن نود';
  if(history){
    const url=new URL(window.location.href);
    if(view==='overview')url.searchParams.delete('view');
    else url.searchParams.set('view',view);
    window.history.pushState({view},'',url.pathname+url.search);
  }
  window.scrollTo({top:0,behavior:'instant'});
}
function createForm(type){
  createType=type;$('create-title').textContent=type==='node'?'افزودن نود':'ایجاد مشترک';
  $('create-description').textContent=type==='node'?'فقط ثبت اولیه؛ نصب Agent هنوز پیاده‌سازی نشده است.':'حساب تا زمان اتصال موتور سرویس، در انتظار ایجاد می‌ماند.';
  $('region-field').classList.toggle('hidden',type!=='node');$('quota-field').classList.toggle('hidden',type==='node');$('expiry-field').classList.toggle('hidden',type==='node');
  $('entity-region').required=type==='node';$('entity-quota').required=type==='user';$('entity-expiry').required=type==='user';
  $('entity-name').value='';$('entity-expiry').value=new Date(Date.now()+30*86400000).toISOString().slice(0,10);
  $('create-modal').showModal();$('entity-name').focus();
}
$('auth-form').addEventListener('submit',async(e)=>{
  e.preventDefault();apiToken=$('api-token').value;
  try{await refresh();$('auth-modal').close();$('api-token').value='';}catch(e){$('auth-error').textContent=errMessage(e);apiToken='';}
});
$('refresh').addEventListener('click',async()=>{try{await refresh()}catch(e){showError(e)}});
$('main-action').addEventListener('click',async()=>{if(activeView==='license'){try{await refresh()}catch(e){showError(e)};return;}createForm(activeView==='users'?'user':'node')});
$('create-form').addEventListener('submit',async(e)=>{
  e.preventDefault();const name=$('entity-name').value.trim();
  const payload=createType==='node'?{name,region:$('entity-region').value.trim().toUpperCase()}:{name,quota_gb:Number($('entity-quota').value),expires_at:new Date($('entity-expiry').value+'T23:59:59Z').toISOString()};
  try{await api(createType==='node'?'nodes':'users',{method:'POST',body:JSON.stringify(payload)});$('create-modal').close();await refresh()}catch(err){$('create-modal').close();showError(err)}
});
$('cancel-create').addEventListener('click',()=>$('create-modal').close());
document.querySelectorAll('[data-view]').forEach(b=>b.addEventListener('click',()=>changeView(b.dataset.view)));
document.querySelectorAll('[data-go]').forEach(b=>b.addEventListener('click',()=>changeView(b.dataset.go)));
document.querySelectorAll('[data-create]').forEach(b=>b.addEventListener('click',()=>createForm(b.dataset.create)));
for(const kind of ['nodes','users']){
  $(kind+'-search')?.addEventListener('input',render);
  $(kind+'-filter')?.addEventListener('change',render);
}
document.addEventListener('keydown',(event)=>{
  if((event.ctrlKey||event.metaKey)&&event.key.toLowerCase()==='k'){
    event.preventDefault();const input=$('users-search');changeView('users');input?.focus();
  }
});
$('logout').addEventListener('click',()=>{
  if(hosted){
    const form=document.createElement('form');
    form.method='post';form.action='./';
    for(const [name,value] of [['action','logout'],['csrf',csrf]]){
      const input=document.createElement('input');input.type='hidden';input.name=name;input.value=value;form.append(input);
    }
    document.body.append(form);form.submit();
  }else{
    apiToken='';state={nodes:[],users:[],license:null};
    $('auth-modal').showModal();
  }
});
function initialView(){
  const query=new URLSearchParams(window.location.search).get('view');
  const legacy=window.location.hash.slice(1).toLowerCase();
  const selected=views.includes(query)?query:(views.includes(legacy)?legacy:'overview');
  // Migrate old #license/#nodes links so URL and visible page agree.
  if(views.includes(legacy)){
    const url=new URL(window.location.href);
    url.hash='';
    if(selected==='overview')url.searchParams.delete('view');
    else url.searchParams.set('view',selected);
    window.history.replaceState({view:selected},'',url.pathname+url.search);
  }
  return selected;
}
window.addEventListener('popstate',()=>changeView(new URLSearchParams(window.location.search).get('view')||'overview',{history:false}));
changeView(initialView(),{history:false});
$('update-install')?.addEventListener('click',installUpdate);
if(hosted){refresh().catch(showError);checkUpdates();}
else $('auth-modal').showModal();
