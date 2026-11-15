const $=id=>document.getElementById(id);
let activeImages=[],activeIndex=0;
const clean=(s)=>String(s||'').trim();
const safeImage=(s)=>{const v=clean(s);return v.startsWith('/media/')||v.startsWith('/assets/')||/^https:\/\//i.test(v)?v:''};
function setText(id,value){if($(id)&&clean(value))$(id).textContent=value}
function setPhoto(id,container,value){const src=safeImage(value);if(src){$(id).src=src;$(id).hidden=false;$(container).classList.remove('photo-fallback')}}
function openLightbox(images,index){activeImages=images.map(safeImage).filter(Boolean);if(!activeImages.length)return;activeIndex=index;$('lightbox').hidden=false;document.body.classList.add('locked');switchImage(0)}
function switchImage(delta){activeIndex=(activeIndex+delta+activeImages.length)%activeImages.length;$('lightbox-img').src=activeImages[activeIndex]}
function galleryCard(g,i){const card=document.createElement('button');card.className='gallery-card';card.type='button';const cover=document.createElement('div');cover.className='gallery-cover';const images=(Array.isArray(g.images)?g.images:[]).map(safeImage).filter(Boolean);const coverSrc=safeImage(g.cover)||images[0];if(coverSrc){const img=document.createElement('img');img.src=coverSrc;img.alt='תמונת שער '+clean(g.title);img.loading='lazy';cover.append(img)}else{const flower=document.createElement('span');flower.className='gallery-placeholder';flower.textContent=['✿','❀','✾'][i%3];cover.append(flower)}const h=document.createElement('h3');h.textContent=clean(g.title);const p=document.createElement('p');p.textContent=clean(g.subtitle);const view=document.createElement('span');view.className='view';view.textContent=images.length?'לצפייה בגלריה ←':'תמונות יתווספו בקרוב';card.append(cover,h,p,view);card.addEventListener('click',()=>{if(images.length)openLightbox(images,0)});return card}
// Dates are explicit availability published by Liraz; no bookings or customer details are stored.
const dateKey = date => date.toISOString().slice(0,10);
function israelToday(){
  const parts = new Intl.DateTimeFormat('en-GB',{timeZone:'Asia/Jerusalem',year:'numeric',month:'2-digit',day:'2-digit'}).formatToParts(new Date());
  const part = type => parts.find(p=>p.type===type).value;
  return `${part('year')}-${part('month')}-${part('day')}`;
}
function validDateKey(value){
  if(typeof value!=='string'||!/^\d{4}-\d{2}-\d{2}$/.test(value))return false;
  const date=new Date(value+'T12:00:00Z');
  return Number.isFinite(date.getTime())&&dateKey(date)===value;
}
const prettyDate = value => new Intl.DateTimeFormat('he-IL',{dateStyle:'full',timeZone:'UTC'}).format(new Date(value+'T12:00:00Z'));
function setupCalendar(site){
  const today=israelToday();
  const available=new Set((Array.isArray(site.available_dates)?site.available_dates:[]).filter(value=>validDateKey(value)&&value>=today));
  const current=new Date(today.slice(0,7)+'-01T12:00:00Z');
  let month=new Date(current);
  let limit=new Date(Date.UTC(current.getUTCFullYear()+1,current.getUTCMonth(),1,12));
  const last=[...available].sort().at(-1);
  if(last){const lastMonth=new Date(last.slice(0,7)+'-01T12:00:00Z');if(lastMonth>limit)limit=lastMonth;}
  let selected='';
  function select(value){
    selected=value;$('requested-date').value=value;
    $('selected-date').textContent=value?'התאריך שבחרתם: '+prettyDate(value):'אפשר לפנות גם בלי לבחור תאריך.';
    $('clear-date').hidden=!value;
    render();
  }
  function render(){
    const year=month.getUTCFullYear(),index=month.getUTCMonth();
    $('calendar-month').textContent=new Intl.DateTimeFormat('he-IL',{month:'long',year:'numeric',timeZone:'UTC'}).format(month);
    $('calendar-prev').disabled=month<=current;$('calendar-next').disabled=month>=limit;
    const days=$('calendar-days');days.replaceChildren();
    const start=new Date(Date.UTC(year,index,1,12)).getUTCDay();
    for(let offset=0;offset<start;offset++){const blank=document.createElement('span');blank.setAttribute('aria-hidden','true');days.append(blank);}
    const total=new Date(Date.UTC(year,index+1,0,12)).getUTCDate();let count=0;
    for(let day=1;day<=total;day++){
      const key=dateKey(new Date(Date.UTC(year,index,day,12)));
      const open=key>=israelToday()&&available.has(key);if(open)count++;
      const button=document.createElement('button');button.type='button';button.textContent=String(day);button.disabled=!open;
      button.setAttribute('aria-label',prettyDate(key)+(open?' — פנוי לבקשת תיאום':' — אינו זמין לבחירה'));
      if(open)button.setAttribute('aria-pressed',String(key===selected));
      if(key===israelToday())button.setAttribute('aria-current','date');
      button.addEventListener('click',()=>{if(key>=israelToday()&&available.has(key))select(key);});days.append(button);
    }
    $('calendar-message').textContent=count?'בחרו יום מסומן בירוק. שליחת הבקשה אינה אישור הזמנה.':available.size?'אין כרגע תאריכים פנויים בחודש הזה. אפשר לעבור לחודש אחר או לדבר איתי בוואטסאפ.':'לתיאום מועד לצילומים, דברו איתי בוואטסאפ. תאריכים פנויים יופיעו כאן לאחר עדכון היומן.';
  }
  $('calendar-prev').addEventListener('click',()=>{if(month>current){month=new Date(Date.UTC(month.getUTCFullYear(),month.getUTCMonth()-1,1,12));render();}});
  $('calendar-next').addEventListener('click',()=>{if(month<limit){month=new Date(Date.UTC(month.getUTCFullYear(),month.getUTCMonth()+1,1,12));render();}});
  $('clear-date').addEventListener('click',()=>select(''));
  render();
}
function setupContact(site){
  const phone=clean(site.phone)||'054-4412421',email=clean(site.email),whatsapp=clean(site.whatsapp).replace(/\D/g,'')||'972544412421',instagram=clean(site.instagram)||'https://www.instagram.com/liraz.b_photography/';
  const links=$('contact-links');links.replaceChildren();
  for(const [label,url] of [['נדבר בוואטסאפ ↗','https://wa.me/'+whatsapp],['חייגו ללירז · '+phone,'tel:'+phone.replace(/[^\d+]/g,'')],['האינסטגרם שלי ↗',instagram.startsWith('https://')?instagram:''],['אימייל',email?'mailto:'+email:'']]){
    if(!url)continue;const a=document.createElement('a');a.href=url;a.textContent=label;
    if(url.startsWith('https')){a.target='_blank';a.rel='noopener noreferrer';}links.append(a);
  }
  const mobile=$('mobile-contact');
  if(mobile){mobile.querySelector('a[href^="tel:"]').href='tel:'+phone.replace(/[^\d+]/g,'');mobile.querySelector('a[href^="https://wa.me/"]').href='https://wa.me/'+whatsapp;}
  setupCalendar(site);
  $('contact-form').addEventListener('submit',event=>{
    event.preventDefault();
    const data=new FormData(event.currentTarget),date=clean(data.get('date'));
    if(date&&(!validDateKey(date)||date<israelToday()||!(site.available_dates||[]).includes(date))){$('form-feedback').textContent='התאריך שנבחר כבר אינו זמין לבקשה. בחרו תאריך אחר או פנו בלי תאריך.';return;}
    const message=`שלום לירז, שמי ${clean(data.get('name'))}.\nטלפון לחזרה: ${clean(data.get('phone'))}\n${date?'אשמח לתאם צילומים בתאריך '+prettyDate(date)+' (בכפוף לאישורך).\n':''}${clean(data.get('message'))}`;
    const url='https://wa.me/'+whatsapp+'?text='+encodeURIComponent(message);
    window.open(url,'_blank','noopener,noreferrer');
    $('form-feedback').textContent='ההודעה מוכנה בוואטסאפ. שלחו אותה שם להשלמת הפנייה; המועד יאושר אישית מול לירז.';
  });
}

async function loadSite(){try{const r=await fetch('/site-data.json',{cache:'no-store'});if(!r.ok)throw Error('data');const {site,galleries}=await r.json();for(const [id,key] of [['eyebrow','eyebrow'],['headline','headline'],['intro','intro'],['about-title','about_title'],['about-text','about'],['contact-title','contact_title'],['contact-text','contact_text'],['footer-text','footer']])setText(id,site[key]);setPhoto('hero-image','hero-photo',site.hero_image);setPhoto('about-image','about-photo',site.about_image);const grid=$('gallery-grid');galleries.forEach((g,i)=>grid.append(galleryCard(g,i)));if(!galleries.length)grid.textContent='גלריות יתווספו בקרוב';setupContact(site)}catch(error){$('gallery-grid').textContent='כדי לטעון את תוכן האתר יש לפרסם אותו או להריץ את פקודת הבנייה לפי ההוראות.';setupContact({})}}
$('year').textContent=new Date().getFullYear();document.querySelector('.menu-toggle').addEventListener('click',e=>{const nav=$('nav');const open=nav.classList.toggle('open');e.currentTarget.setAttribute('aria-expanded',String(open))});document.querySelectorAll('nav a').forEach(a=>a.addEventListener('click',()=>{$('nav').classList.remove('open');document.querySelector('.menu-toggle').setAttribute('aria-expanded','false')}));$('lightbox-close').onclick=()=>{$('lightbox').hidden=true;document.body.classList.remove('locked')};$('lightbox-prev').onclick=()=>switchImage(-1);$('lightbox-next').onclick=()=>switchImage(1);$('lightbox').addEventListener('click',e=>{if(e.target===$('lightbox'))$('lightbox-close').click()});document.addEventListener('keydown',e=>{if(!$('lightbox').hidden){if(e.key==='Escape')$('lightbox-close').click();if(e.key==='ArrowLeft')switchImage(1);if(e.key==='ArrowRight')switchImage(-1)}});loadSite();
