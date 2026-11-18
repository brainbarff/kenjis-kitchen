(() => {
 const list=document.getElementById('reviewList'), form=document.getElementById('reviewForm');
 if(!list || !form) return;
 let csrf='', loading=false;
 const SVG_NS='http://www.w3.org/2000/svg';
 const STAR_PATH='M12 2.4l2.92 5.92 6.53.95-4.72 4.6 1.11 6.5L12 17.3l-5.84 3.07 1.11-6.5-4.72-4.6 6.53-.95L12 2.4z';
 const text=(tag,value,className='')=>{const el=document.createElement(tag);el.textContent=value;if(className)el.className=className;return el;};
 const safeUrl=(url)=>typeof url==='string'&&(url.startsWith('/')||/^https?:\/\//i.test(url))?url:'';
 const initialOf=(name)=>{const m=String(name||'').trim().match(/[\p{L}\p{N}]/u);return m?m[0].toUpperCase():'C';};
 const createStar=(filled,className)=>{
  const svg=document.createElementNS(SVG_NS,'svg');
  svg.setAttribute('viewBox','0 0 24 24');
  svg.setAttribute('aria-hidden','true');
  svg.setAttribute('class',`${className} ${filled?'is-filled':'is-empty'}`);
  const path=document.createElementNS(SVG_NS,'path');
  path.setAttribute('d',STAR_PATH);
  svg.append(path);
  return svg;
 };
 const createReviewerAvatar=(review)=>{
  const wrap=document.createElement('div');
  wrap.className='review-avatar';
  const fallback=text('span',initialOf(review.name),'review-avatar-fallback');
  fallback.setAttribute('aria-hidden','true');
  const url=safeUrl(review.profile_photo_url);
  if(!url){wrap.append(fallback);return wrap;}
  const img=document.createElement('img');
  img.className='review-avatar-img';
  img.src=url;
  img.alt='';
  img.loading='lazy';
  img.addEventListener('error',()=>img.replaceWith(fallback),{once:true});
  wrap.append(img);
  return wrap;
 };
 function renderSummary(summary){
  const el=document.getElementById('reviewSummary');
  if(!el)return;
  el.replaceChildren();
  const total=Number(summary?.total||0);
  if(!total)return;
  const label=total===1?'1 review':`${total} reviews`;
  el.append(
   createStar(true,'review-summary-star'),
   text('strong',String(summary.average ?? '5.0'),'review-summary-score'),
   text('span',` / 5 · ${label}`,'review-summary-meta')
  );
 }
 async function refresh(){
  if(loading)return;loading=true;
  try{
   const response=await fetch('./api/reviews.php',{cache:'no-store'});
   const data=await response.json();
   if(!response.ok)throw new Error(data.message);
   csrf=data.csrf;
   list.replaceChildren();
   renderSummary(data.summary);
   const submitBtn=form.querySelector('button[type="submit"]');
   if(submitBtn)submitBtn.textContent=data.my_review?'Update review':'Post review';
   if(!data.reviews.length)list.append(text('p','No reviews yet. Share your experience with Kenji’s Kitchen.','review-empty'));
   data.reviews.forEach(review=>{
    const card=document.createElement('article');
    card.className='store-review';

    const head=document.createElement('div');
    head.className='review-card-head';
    const meta=document.createElement('div');
    meta.className='review-author-meta';
    const authorName=String(review.name||'Customer').trim().split(/\s+/)[0]||'Customer';
    const formattedDate=new Date(String(review.created_at||'').replace(' ','T')+'+08:00').toLocaleDateString('en-PH',{month:'short',day:'numeric',year:'numeric'});
    meta.append(text('strong',authorName,'review-author'),text('time',formattedDate,'review-date'));
    head.append(createReviewerAvatar(review),meta);

    const rating=Math.max(1,Math.min(5,Number(review.rating)||5));
    const stars=document.createElement('div');
    stars.className='review-stars';
    stars.setAttribute('aria-label',`${rating} out of 5 stars`);
    for(let i=0;i<5;i++)stars.append(createStar(i<rating,'review-star-svg'));

    const body=text('p',review.comment,'review-comment');
    card.append(head,stars,body);
    list.append(card);
   });
  }catch(error){
   if(!list.querySelector('.store-review'))list.replaceChildren(text('p',error.message||'Reviews are temporarily unavailable.'));
  }finally{loading=false;}
 }
 form.addEventListener('submit',async event=>{
  event.preventDefault();
  const button=form.querySelector('button'),message=document.getElementById('reviewMessage');
  button.disabled=true;
  message.className='';
  try{
   if(!csrf)await refresh();
   const response=await fetch('./api/reviews.php',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({csrf,rating:form.rating.value,comment:form.comment.value})});
   const data=await response.json();
   message.textContent=data.message;
   message.classList.toggle('is-success',response.ok);
   message.classList.toggle('is-error',!response.ok);
   if(response.ok){form.reset();await refresh();}
  }catch{
   message.textContent='Could not post your review. Please try again.';
   message.classList.add('is-error');
  }finally{button.disabled=false;}
 });
 window.addEventListener('kk:profile-updated',refresh);
 window.addEventListener('kk:reviews-updated',refresh);
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
 const starRow=document.querySelector('.star-rating-row');
 const ratingLabel=document.getElementById('starRatingLabel');
 const RATING_LABELS=['Tap a star to rate','1 out of 5','2 out of 5','3 out of 5','4 out of 5','5 out of 5'];
 function paint(hoverVal=0){
  const selected=stars.find(input=>input.checked);
  const selectedVal=selected?Number(selected.value):0;
  const activeVal=hoverVal||selectedVal;
  stars.forEach(input=>{
   const val=Number(input.value);
   input.parentElement.classList.toggle('selected',selectedVal>0&&val<=selectedVal);
   input.parentElement.classList.toggle('hovered',hoverVal>0&&val<=hoverVal);
  });
  if(ratingLabel)ratingLabel.textContent=RATING_LABELS[activeVal]||RATING_LABELS[0];
 }
 stars.forEach(input=>{
  input.addEventListener('change',()=>paint(0));
  input.parentElement.addEventListener('mouseenter',()=>paint(Number(input.value)));
  input.addEventListener('focus',()=>paint(Number(input.value)));
  input.addEventListener('blur',()=>paint(0));
 });
 if(starRow)starRow.addEventListener('mouseleave',()=>paint(0));
 document.getElementById('reviewForm').addEventListener('reset',()=>setTimeout(()=>paint(0),0));
 paint(0);
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
