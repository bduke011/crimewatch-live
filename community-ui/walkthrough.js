export async function startWalkthrough(){
 const steps=[
  {target:'#mapTab',title:'Explore the Map',text:'The Map button is above the report filters. Choose your community, date range and report type. Tap a numbered marker to see reports at that location.',note:'Use my location is optional and centers the map. Coverage varies; an empty map does not mean no crime.'},
  {target:'#arrestsTab',title:'Read Daily Arrests',text:'Daily Arrests is beside Map. Choose a published report date, search by name, or tap Latest available report. Tap a person’s card to read their listed charges.',note:'Daily arrest reports currently cover Polk County. Charges are not convictions. Arrests are not map pins unless a verified offense location is available.'},
  {target:'.reader-link',title:'Find your Settings',text:'Settings is at the top of the page, beside the CrimeWatch name. Open it to change your display name and preferred community, manage your password, or sign out.',note:window.CW_NATIVE?'You can also enable or turn off app notifications there. Tap Save and view reports to return. You can replay this lesson from Settings.':'Tap Save and view reports to return. You can replay this lesson from Settings.'}
 ];
 const dialog=document.createElement('dialog');dialog.className='walkthrough';dialog.setAttribute('aria-labelledby','lessonTitle');
 dialog.innerHTML='<div class="lesson-target" aria-hidden="true"></div><section class="lesson-card"><p class="lesson-progress"></p><h2 id="lessonTitle" tabindex="-1">Getting your lesson ready…</h2><p class="lesson-text"></p><p class="lesson-note"></p><p class="lesson-error" role="alert"></p><div class="lesson-actions"><button type="button" class="lesson-back">Back</button><button type="button" class="lesson-next">Next</button></div></section>';
 document.body.append(dialog);const find=s=>dialog.querySelector(s),next=find('.lesson-next'),back=find('.lesson-back'),spot=find('.lesson-target'),card=find('.lesson-card');
 let step=0,csrf='',completed=false,ready=false,target=null,allowClose=false;
 const replay=new URLSearchParams(location.search).get('lesson')==='1';
 dialog.addEventListener('cancel',ev=>ev.preventDefault());
 dialog.addEventListener('keydown',ev=>{if(ev.key==='Escape'){ev.preventDefault();ev.stopPropagation();}});
 dialog.addEventListener('close',()=>{if(!allowClose&&dialog.isConnected){dialog.showModal();position();}});
 function position(){
  if(!target)return;const rect=target.getBoundingClientRect(),available=card.getBoundingClientRect().top-12;
  spot.hidden=rect.bottom>available||rect.top<0;
  if(!spot.hidden)Object.assign(spot.style,{top:rect.top+'px',left:rect.left+'px',width:rect.width+'px',height:rect.height+'px'});
 }
 function show(){
  const s=steps[step];target=document.querySelector(s.target);
  if(step<2)target.click();
  window.scrollTo({top:Math.max(0,window.scrollY+target.getBoundingClientRect().top-90),behavior:'instant'});
  spot.textContent=target.textContent;spot.hidden=false;
  find('.lesson-progress').textContent=`QUICK START · ${step+1} OF ${steps.length}`;
  find('#lessonTitle').textContent=s.title;find('.lesson-text').textContent=s.text;find('.lesson-note').textContent=s.note;
  find('.lesson-error').textContent='';back.hidden=step===0;next.textContent=step===2?'Finish and view reports':'Next';next.disabled=false;
  requestAnimationFrame(position);find('#lessonTitle').focus({preventScroll:true});
 }
 function close(){allowClose=true;dialog.close();dialog.remove();window.removeEventListener('resize',position);window.removeEventListener('scroll',position);window.visualViewport?.removeEventListener('resize',position);if(replay){const url=new URL(location.href);url.searchParams.delete('lesson');history.replaceState(null,'',url);}document.getElementById('mapTab').click();document.getElementById('mapTab').scrollIntoView({block:'start'});document.getElementById('mapTab').focus({preventScroll:true});}
 async function load(){
  next.disabled=true;
  try{
   const r=await fetch('reader-auth-api.php?action=session',{cache:'no-store'});if(!r.ok)throw Error('Unable to load your lesson. Check your connection and try again.');const d=await r.json();
   if(!d.reader){close();return;}csrf=d.csrf;completed=!!d.lessonCompleted;
   if(completed&&!replay){close();return;}ready=true;show();
  }catch(e){find('.lesson-error').textContent=e.message;next.textContent='Try again';next.disabled=false;}
 }
 next.onclick=async()=>{
  if(!ready){await load();return;}if(step<2){step++;show();return;}
  next.disabled=true;back.disabled=true;find('.lesson-error').textContent='';
  try{
   if(!completed){const r=await fetch('reader-auth-api.php?action=complete-lesson',{method:'POST',headers:{'Content-Type':'application/json','X-CSRF-Token':csrf},body:JSON.stringify({native:!!window.CW_NATIVE,version:1})});if(!r.ok)throw Error('Your progress could not be saved. Check your connection, then tap Finish again.');}
   close();
  }catch(e){find('.lesson-error').textContent=e.message;next.disabled=false;back.disabled=false;}
 };
 back.onclick=()=>{if(step>0){step--;show();}};
 window.addEventListener('resize',position);window.addEventListener('scroll',position);window.visualViewport?.addEventListener('resize',position);
 back.hidden=true;spot.hidden=true;dialog.showModal();await load();
}
