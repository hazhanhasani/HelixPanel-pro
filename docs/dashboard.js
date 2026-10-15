'use strict';
/**
 * HelixPanel public sandbox. Static demonstration with ephemeral in-memory data.
 * SECURITY INVARIANT: no fetch/XMLHttpRequest/WebSocket, cookies or storage.
 * Every displayed metric and node here is explicitly fictitious.
 */
const $ = id => document.getElementById(id);
const nf = new Intl.NumberFormat('fa-IR');
const mockNodes = [
  {name:'Frankfurt 01',flag:'🇩🇪',region:'آلمان · فرانکفورت',status:'online',cpu:28,ram:43,ping:41,load:36,host:'de-fra-01.demo.local'},
  {name:'Amsterdam 02',flag:'🇳🇱',region:'هلند · آمستردام',status:'online',cpu:37,ram:52,ping:53,load:48,host:'nl-ams-02.demo.local'},
  {name:'Istanbul 03',flag:'🇹🇷',region:'ترکیه · استانبول',status:'online',cpu:42,ram:36,ping:22,load:57,host:'tr-ist-03.demo.local'},
  {name:'London 04',flag:'🇬🇧',region:'انگلیس · لندن',status:'online',cpu:15,ram:32,ping:88,load:29,host:'uk-lon-04.demo.local'},
  {name:'Dubai 05',flag:'🇦🇪',region:'امارات · دبی',status:'online',cpu:63,ram:66,ping:73,load:67,host:'ae-dxb-05.demo.local'},
  {name:'Paris 06',flag:'🇫🇷',region:'فرانسه · پاریس',status:'offline',cpu:0,ram:0,ping:null,load:0,host:'fr-par-06.demo.local'},
];
const seededUsers = [
  {id:'HP-10024',name:'مهدی رضایی',plan:'ویژه',quota:100,expiry:'۱۴۰۶/۰۳/۲۵',active:true},
  {id:'HP-10025',name:'آرمان کریمی',plan:'اقتصادی',quota:30,expiry:'۱۴۰۶/۰۲/۱۸',active:true},
  {id:'HP-10026',name:'سارا احمدی',plan:'ویژه',quota:100,expiry:'۱۴۰۶/۰۴/۰۱',active:true},
  {id:'HP-10027',name:'امیر حسینی',plan:'سازمانی',quota:500,expiry:'۱۴۰۶/۰۵/۱۵',active:true},
  {id:'HP-10028',name:'نیما شریفی',plan:'اقتصادی',quota:30,expiry:'۱۴۰۶/۰۱/۲۰',active:false},
  {id:'HP-10029',name:'الناز محمدی',plan:'ویژه',quota:100,expiry:'۱۴۰۶/۰۶/۱۰',active:true}
];
let users = seededUsers.map(u=>({...u}));
let currentView = 'overview';
let toastTimer = null;
const titles = {
  overview:['نمای کلی','CONTROL CENTER / OVERVIEW','مرکز فرماندهی زیرساخت','همه چیز در یک نگاه؛ وضعیت نمونه سرورها، اشتراک‌ها و عملکرد سرویس.'],
  nodes:['نودها و سرورها','INFRASTRUCTURE / NODES','مدیریت نودها و سرورها','مشاهده وضعیت و سلامت نمونه نودهای متصل به مستر.'],
  users:['مشترکین','SUBSCRIPTIONS / USERS','مدیریت مشترکین','مرور، جست‌وجو و ساخت مشترک آزمایشی بدون اتصال واقعی.'],
  plans:['پلن‌ها و اشتراک‌ها','PRODUCTS / PLANS','طرح‌های اشتراک','نمونه بسته‌ها و امکانات قابل ارائه به مشترکین.'],
  license:['مدیریت لایسنس','COMMERCIAL / LICENSE','لایسنس اختصاصی','نمایی از وضعیت مجوز و ظرفیت اختصاص‌یافته به پنل.'],
  activity:['رویدادها','SYSTEM / ACTIVITY','گزارش رویدادها','فعالیت‌های نمونه سیستم در یک نمای متمرکز.'],
  settings:['تنظیمات نمایش','PREFERENCES / SETTINGS','تنظیمات ظاهری','تغییر ظاهر این پیش‌نمایش بدون اثرگذاری بر سیستم واقعی.']
};
const events = [
  {icon:'✓',title:'Node Frankfurt 01 در دسترس است',detail:'۲ دقیقه پیش · بررسی آزمایشی سلامت'},
  {icon:'♧',title:'یک مشترک به پلن ویژه افزوده شد',detail:'۱۸ دقیقه پیش · رویداد نمونه'},
  {icon:'↗',title:'گزارش ترافیک هفتگی آماده شد',detail:'۴۵ دقیقه پیش · داده نمایشی'},
  {icon:'◇',title:'لایسنس Professional تأیید شد',detail:'۲ ساعت پیش · مجوز نمایشی'},
  {icon:'▣',title:'Node Paris 06 موقتاً آفلاین است',detail:'۳ ساعت پیش · وضعیت نمونه'},
  {icon:'◈',title:'بررسی وضعیت سرویس بدون خطا انجام شد',detail:'۵ ساعت پیش · رویداد نمونه'}
];
function el(tag, props={}, text) {
  const node = document.createElement(tag);
  for(const [key,value] of Object.entries(props)){
    if(key==='class') node.className=value;
    else if(key==='type') node.type=value;
    else node.setAttribute(key,value);
  }
  if(text !== undefined) node.textContent=String(text);
  return node;
}
function toast(message){
  const host=$('toast');host.textContent=message;host.classList.add('show');
  if(toastTimer!==null)clearTimeout(toastTimer);
  toastTimer=setTimeout(()=>{host.classList.remove('show');toastTimer=null;},3000);
}
function buildChart(){
  const values=[34,51,44,71,59,86,70];
  const W=700,H=200;
  const points=values.map((v,i)=>[24+i*108,H-18-v*1.7]);
  const ns='http://www.w3.org/2000/svg';
  const svg=document.createElementNS(ns,'svg');svg.setAttribute('viewBox',`0 0 ${W} ${H}`);svg.setAttribute('preserveAspectRatio','none');
  const defs=document.createElementNS(ns,'defs');const grad=document.createElementNS(ns,'linearGradient');grad.id='traffic-fill';grad.setAttribute('x1','0');grad.setAttribute('y1','0');grad.setAttribute('x2','0');grad.setAttribute('y2','1');
  [['0%','#51d7ad','.38'],['100%','#51d7ad','0']].forEach(([offset,color,opacity])=>{const stop=document.createElementNS(ns,'stop');stop.setAttribute('offset',offset);stop.setAttribute('stop-color',color);stop.setAttribute('stop-opacity',opacity);grad.appendChild(stop)});defs.appendChild(grad);svg.appendChild(defs);
  const line=points.map((p,i)=>(i?'L':'M')+p[0]+' '+p[1]).join(' ');
  const area=document.createElementNS(ns,'path');area.setAttribute('d',`${line} L ${points[points.length-1][0]} ${H} L ${points[0][0]} ${H} Z`);area.setAttribute('fill','url(#traffic-fill)');svg.appendChild(area);
  const stroke=document.createElementNS(ns,'path');stroke.setAttribute('d',line);stroke.setAttribute('fill','none');stroke.setAttribute('stroke','#61dcb8');stroke.setAttribute('stroke-width','3.2');stroke.setAttribute('stroke-linecap','round');stroke.setAttribute('stroke-linejoin','round');svg.appendChild(stroke);
  points.forEach(([cx,cy])=>{const circle=document.createElementNS(ns,'circle');circle.setAttribute('cx',cx);circle.setAttribute('cy',cy);circle.setAttribute('r',4.4);circle.setAttribute('fill','#172c2b');circle.setAttribute('stroke','#80ebc9');circle.setAttribute('stroke-width',2.5);svg.appendChild(circle)});
  $('traffic-chart').replaceChildren(svg);
}
function renderNodes(){
  const list=$('nodes-list');list.replaceChildren();
  for(const node of mockNodes){
    const card=el('article',{class:'node-card'});
    const head=el('div',{class:'node-card-head'}),title=el('div',{class:'node-title'});
    title.append(el('span',{class:'node-flag'},node.flag));
    const names=el('div');names.append(el('b',{},node.name),el('small',{},node.region));title.append(names);
    head.append(title,el('span',{class:'node-status'+(node.status==='offline'?' offline':'')},node.status==='online'?'● آنلاین':'● آفلاین'));
    const specs=el('div',{class:'node-specs'});
    [['CPU',node.cpu+'%'],['RAM',node.ram+'%'],['PING',node.ping===null?'—':node.ping+'ms']].forEach(([k,v])=>{const d=el('div');d.append(el('small',{},k),el('strong',{},v));specs.append(d)});
    const load=el('div',{class:'node-load'});load.append(el('span',{},'مصرف منابع · نمونه'),el('span',{},node.load+'%'));
    const meter=el('div',{class:'node-meter'});const bar=el('span');bar.style.width=node.load+'%';meter.append(bar);
    card.append(head,specs,load,meter);list.append(card);
  }
  const overview=$('overview-nodes');overview.replaceChildren();
  for(const node of mockNodes.slice(0,4)){
    const item=el('div',{class:'mini-node'});item.append(el('span',{class:'country'},node.flag));
    const names=el('div',{class:'nameblock'});names.append(el('b',{},node.name),el('small',{},node.region));
    item.append(names,el('span',{class:'dot-label'+(node.status==='offline'?' offline':'')},node.status==='online'?'آنلاین':'آفلاین'));overview.append(item);
  }
}
function renderActivity(){
  for(const [target,limit] of [['overview-activity',4],['activity-all',events.length]]){
    const host=$(target);host.replaceChildren();
    for(const event of events.slice(0,limit)){
      const item=el('div',{class:'activity-item'});item.append(el('span',{class:'activity-icon'},event.icon));const words=el('div');words.append(el('b',{},event.title),el('small',{},event.detail));item.append(words);host.append(item);
    }
  }
}
function renderUsers(){
  const term=$('user-search').value.trim().toLocaleLowerCase('fa-IR');
  const status=$('user-status').value;
  const subset=users.filter(u=>(!term||(u.name+' '+u.id).toLocaleLowerCase('fa-IR').includes(term))&&(status==='all'||(status==='active')===u.active));
  const table=$('users-table');table.replaceChildren();
  if(!subset.length){const tr=el('tr'),td=el('td',{class:'empty-table',colspan:'6'},'کاربری با این مشخصات پیدا نشد.');tr.append(td);table.append(tr)}
  for(const u of subset){
    const tr=el('tr');const first=el('td');first.append(el('span',{class:'user-name'},u.name),el('span',{class:'user-id'},u.id));tr.append(first);
    tr.append(el('td',{},u.plan),el('td',{},nf.format(u.quota)+' GB'),el('td',{},u.expiry));
    const stat=el('td');stat.append(el('span',{class:'status-label'+(u.active?'':' inactive')},u.active?'فعال':'غیرفعال'));tr.append(stat);
    const action=el('td');const toggle=el('button',{type:'button',class:'table-action'},u.active?'تعلیق دمو':'فعال‌سازی دمو');toggle.addEventListener('click',()=>{u.active=!u.active;renderUsers();toast('وضعیت کاربر در داده‌های نمونه تغییر کرد.');});action.append(toggle);tr.append(action);table.append(tr);
  }
  $('users-summary').textContent=nf.format(subset.length)+' مشترک نمونه';
  $('nav-user-count').textContent=nf.format(users.length);
}
function closeMobile(){
  $('sidebar').classList.remove('open');$('mobile-overlay').hidden=true;$('mobile-menu').setAttribute('aria-expanded','false');
}
function changeView(name){
  if(!Object.prototype.hasOwnProperty.call(titles,name))return;
  currentView=name;
  document.querySelectorAll('.view').forEach(v=>v.classList.toggle('active',v.id==='view-'+name));
  document.querySelectorAll('[data-view]').forEach(button=>{const on=button.dataset.view===name;button.classList.toggle('active',on);if(on)button.setAttribute('aria-current','page');else button.removeAttribute('aria-current');});
  const [crumb,kicker,title,desc]=titles[name];$('breadcrumb').textContent=crumb;$('page-kicker').textContent=kicker;$('page-title').textContent=title;$('page-desc').textContent=desc;
  $('primary-action').textContent=name==='nodes'?'▣ مشاهده راهنمای نود':name==='license'?'◇ اطلاعات مجوز':'＋ مشترک جدید';
  closeMobile();window.scrollTo({top:0,behavior:'smooth'});
}
function openNewUser(){const modal=$('user-modal');modal.showModal();modal.querySelector('input[name=name]').focus();}
function resetData(){users=seededUsers.map(u=>({...u}));$('user-search').value='';$('user-status').value='all';renderUsers();toast('اطلاعات پیش‌نمایش بازنشانی شد.');}
function toggleTheme(){const next=document.documentElement.dataset.theme==='light'?'dark':'light';document.documentElement.dataset.theme=next;toast(next==='light'?'تم روشن فعال شد.':'تم تاریک فعال شد.');}
function onPrimary(){if(currentView==='nodes'){window.open('https://github.com/hazhanhasani/HelixPanel-pro/blob/main/docs/NODE_SETUP.fa.md','_blank','noopener,noreferrer');return;}if(currentView==='license'){changeView('license');return;}openNewUser();}
function init(){
  buildChart();renderNodes();renderActivity();renderUsers();
  document.querySelectorAll('[data-view]').forEach(b=>b.addEventListener('click',()=>changeView(b.dataset.view)));
  document.querySelectorAll('[data-go]').forEach(b=>b.addEventListener('click',()=>changeView(b.dataset.go)));
  document.querySelectorAll('[data-demo-action]').forEach(b=>b.addEventListener('click',()=>toast('این «'+b.dataset.demoAction+'» فقط نمونه نمایشی است.')));
  $('primary-action').addEventListener('click',onPrimary);$('add-user-small').addEventListener('click',openNewUser);
  $('user-search').addEventListener('input',renderUsers);$('user-status').addEventListener('change',renderUsers);
  $('reset-data').addEventListener('click',resetData);$('reset-settings').addEventListener('click',resetData);
  $('theme-toggle').addEventListener('click',toggleTheme);$('theme-toggle-settings').addEventListener('click',toggleTheme);
  $('mobile-menu').addEventListener('click',()=>{const open=!$('sidebar').classList.contains('open');$('sidebar').classList.toggle('open',open);$('mobile-overlay').hidden=!open;$('mobile-menu').setAttribute('aria-expanded',String(open));});
  $('mobile-overlay').addEventListener('click',closeMobile);
  $('close-modal').addEventListener('click',()=>$('user-modal').close());$('cancel-modal').addEventListener('click',()=>$('user-modal').close());
  $('user-form').addEventListener('submit',e=>{
    e.preventDefault();const form=e.currentTarget;
    if(!form.reportValidity())return;
    const name=form.elements.namedItem('name').value.trim();const quota=Number(form.elements.namedItem('quota').value);const plan=form.elements.namedItem('plan').value;
    if(name.length<2||!Number.isInteger(quota)||quota<1||quota>10000||!['اقتصادی','ویژه','سازمانی'].includes(plan)){toast('اطلاعات واردشده معتبر نیست.');return;}
    const idx=users.length+10030;users.unshift({id:'DEMO-'+idx,name,plan,quota,expiry:'۱۴۰۶/۰۸/۳۰',active:true});
    form.reset();$('user-modal').close();changeView('users');renderUsers();toast('مشترک به لیست نمایشی افزوده شد.');
  });
  document.addEventListener('keydown',e=>{if(e.key==='Escape')closeMobile();});
}
document.addEventListener('DOMContentLoaded',init);
