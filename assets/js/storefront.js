(() => {
 const list=document.getElementById('reviewList'), form=document.getElementById('reviewForm');
 if(!list || !form) return;
 let csrf='', loading=false;
 const text=(tag,value,className='')=>{const el=document.createElement(tag);el.textContent=value;el.className=className;return el;};
 async function refresh(){
  if(loading)return;loading=true;
  try{const response=await fetch('./api/reviews.php',{cache:'no-store'});const data=await response.json();if(!response.ok)throw new Error(data.message);csrf=data.csrf;
   list.replaceChildren();document.getElementById('reviewSummary').textContent=Number(data.summary.total)?`${data.summary.average} / 5 · ${data.summary.total} reviews`:'';
   if(!data.reviews.length)list.append(text('p','No reviews yet. Share your experience with Kenji’s Kitchen.'));
   data.reviews.forEach(review=>{const card=text('article','','store-review');const stars=text('div','★'.repeat(review.rating)+'☆'.repeat(5-review.rating),'review-stars');stars.setAttribute('aria-label',`${review.rating} out of 5 stars`);card.append(stars,text('p',review.comment),text('strong',review.name),text('small',' · '+new Date(review.created_at.replace(' ','T')+'+08:00').toLocaleDateString('en-PH',{month:'short',day:'numeric',year:'numeric'})));list.append(card);});
  }catch(error){if(!list.querySelector('.store-review'))list.replaceChildren(text('p',error.message||'Reviews are temporarily unavailable.'));}finally{loading=false;}
 }
 form.addEventListener('submit',async event=>{event.preventDefault();const button=form.querySelector('button'),message=document.getElementById('reviewMessage');button.disabled=true;try{if(!csrf)await refresh();const response=await fetch('./api/reviews.php',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({csrf,rating:form.rating.value,comment:form.comment.value})});const data=await response.json();message.textContent=data.message;if(response.ok){form.reset();await refresh();}}catch{message.textContent='Could not post your review. Please try again.';}finally{button.disabled=false;}});
 refresh();setInterval(()=>{if(!document.hidden)refresh();},30000);
 // Put menu search beside the catalog, away from the landing-page header.
 const search=document.querySelector('.search-wrapper');if(search){document.getElementById('menu').after(search);search.style.setProperty('display','flex','important');search.style.marginBottom='18px';}
})();


(() => {
  const reducedMotion = matchMedia('(prefers-reduced-motion: reduce)');
  const header = document.querySelector('.navbar');
  const syncHeader = () => document.documentElement.style.setProperty('--store-header-height', `${header.offsetHeight}px`);
  new ResizeObserver(syncHeader).observe(header);
  syncHeader();

  const data = JSON.parse(document.getElementById('bestsellerData')?.textContent || '[]');
  if (!data.length) return;
  const image=document.getElementById('bestsellerImage');
  const progress=document.createElement('div');progress.className='bestseller-progress';progress.setAttribute('aria-hidden','true');
  data.forEach(()=>progress.append(document.createElement('span')));
  if(data.length>1)document.getElementById('bestsellerCarousel').append(progress);
  data.forEach(item=>{const preload=new Image();preload.src=item.image_url;});
  let index=0;
  function render(){[...progress.children].forEach((dot,i)=>dot.classList.toggle("active",i===index));const item=data[index];image.src=item.image_url;image.alt=item.name;document.getElementById('bestsellerName').textContent=item.name;document.getElementById('bestsellerDescription').textContent=item.description||item.category_name;}
  image.addEventListener('error',()=>{if(!image.src.endsWith('/assets/img/food-placeholder.svg'))image.src='../assets/img/food-placeholder.svg';});
  render();
  if(data.length>1)setInterval(()=>{if(!document.hidden&&!reducedMotion.matches){index=(index+1)%data.length;render();image.animate([{opacity:.4},{opacity:1}],{duration:380,easing:'ease-out'});}},3500);
})();

(() => {
 const status=document.getElementById('storeOpenStatus');
 function update(){
  const parts=new Intl.DateTimeFormat('en-US',{timeZone:'Asia/Manila',weekday:'short',hour:'numeric',hourCycle:'h23'}).formatToParts(new Date());
  const day=parts.find(p=>p.type==='weekday').value,hour=Number(parts.find(p=>p.type==='hour').value);
  // Saturday service continues until 3 AM Sunday; Monday opens at 9 AM.
  const open=(hour>=9&&day!=='Sun')||(hour<3&&day!=='Mon');
  status.textContent=open?'Open now':'Closed now';status.classList.toggle('is-open',open);
 }
 update();setInterval(update,30000);
 const stars=[...document.querySelectorAll('.star-rating input')];
 function paint(){const selected=stars.find(input=>input.checked);stars.forEach(input=>input.parentElement.classList.toggle('selected',selected&&Number(input.value)<=Number(selected.value)));}
 stars.forEach(input=>input.addEventListener('change',paint));
 document.getElementById('reviewForm').addEventListener('reset',()=>setTimeout(paint,0));
})();

(() => {
 const gallery = document.querySelector('.about-gallery');
 if (!gallery) return;
 const button = gallery.querySelector('.about-photo-stack');
 const photos = [...gallery.querySelectorAll('.about-gallery-photo')];

 const reduced = window.matchMedia('(prefers-reduced-motion: reduce)');
 let index = 0, timer, visible = false;
 function next() {
  photos[index].classList.remove('is-current');
  photos[index].setAttribute('aria-hidden', 'true');
  index = (index + 1) % photos.length;
  photos[index].classList.add('is-current');
  photos[index].removeAttribute('aria-hidden');

 }
 function schedule() {
  clearInterval(timer);
  if (visible && !document.hidden && !reduced.matches && photos.length > 1) timer = setInterval(next, 3500);
 }
 button.addEventListener('click', () => { next(); schedule(); });
 document.addEventListener('visibilitychange', schedule);
 reduced.addEventListener('change', schedule);
 new IntersectionObserver(entries => { visible = entries[0].isIntersecting; schedule(); }, {threshold: .2}).observe(gallery);
})();

// Keep saved older links working while using readable section names publicly.
(() => {
 const aliases = {heroSection:'home', menuSection:'menu', aboutSpotlight:'about-us', storeLocationSec:'visit-us'};
 function updateNavigation() {
  const old = location.hash.slice(1);
  const name = aliases[old] || old || 'home';
  if (aliases[old]) { history.replaceState(null, '', '#' + name); document.getElementById(name)?.scrollIntoView(); }
  document.querySelectorAll('.top-nav-link').forEach(link => {
   if (link.hash === '#' + name) link.setAttribute('aria-current', 'location');
   else link.removeAttribute('aria-current');
  });
 }
  window.addEventListener('hashchange', updateNavigation);
  updateNavigation();
})();

// Render the Visit Us store location map using Leaflet instead of third-party Google Places embeds.
(() => {
  const el = document.getElementById('storeVisitMap');
  if (!el || typeof window.L === 'undefined') return;
  const lat = Number(el.dataset.lat) || 14.729954;
  const lng = Number(el.dataset.lng) || 121.066199;
  const tileUrl = el.dataset.tileUrl || 'https://tile.openstreetmap.org/{z}/{x}/{y}.png';
  const map = window.L.map(el, {
    center: [lat, lng],
    zoom: 17,
    scrollWheelZoom: false
  });
  window.L.tileLayer(tileUrl, {
    maxZoom: 19,
    attribution: '&copy; <a href="https://www.openstreetmap.org/copyright" target="_blank" rel="noopener noreferrer">OpenStreetMap</a> contributors'
  }).addTo(map);
  const storeIcon = window.L.divIcon({
    className: '',
    html: '<div class="kk-map-pin-store" title="Kenji\'s Kitchen (Greater Lagro)"><i class="bi bi-shop"></i></div>',
    iconSize: [30, 30],
    iconAnchor: [15, 15]
  });
  const marker = window.L.marker([lat, lng], { icon: storeIcon }).addTo(map);
  marker.bindPopup("<strong>Kenji's Kitchen</strong><br>Blk 79 Lot 34 Florante at Laura St.<br>Brgy. Greater Lagro, Quezon City").openPopup();
  if (typeof ResizeObserver !== 'undefined') {
    new ResizeObserver(() => map.invalidateSize()).observe(el);
  }
})();
