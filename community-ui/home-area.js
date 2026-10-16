import {escapeHtml as e} from './model.js';
import {locationError} from './location.js';
let counties=[],saved=null,csrf='',reader=false;
export function countyCapability(area,view){const c=counties.find(c=>c.id===area);return !!c?.[view==='map'?'map':'arrests'];}
export function activeCounties(){return counties.filter(c=>c.active);}
export function allCounties(){return counties;}
export function countyAtPoint(rows,lng,lat){
 const ringContains=ring=>{let inside=false;for(let i=0,j=ring.length-1;i<ring.length;j=i++){const [x,y]=ring[i],[a,b]=ring[j];if(((y>lat)!==(b>lat))&&(lng<(a-x)*(lat-y)/(b-y)+x))inside=!inside;}return inside;};
 const contains=polygon=>ringContains(polygon[0])&&!polygon.slice(1).some(ringContains);
 return rows.find(c=>c.geometry?.type==='Polygon'?contains(c.geometry.coordinates):c.geometry?.type==='MultiPolygon'&&c.geometry.coordinates.some(contains));
}
export async function loadHomeArea(){
 const r=await fetch('area-api.php?action=catalog',{cache:'no-store'});if(!r.ok)throw Error('County availability could not load. Please refresh.');counties=(await r.json()).counties;
 const h=await fetch('area-api.php?action=home',{cache:'no-store'});if(h.ok){const d=await h.json();saved=d.area;reader=true;csrf=d.csrf;return saved||d.preferredArea;}return null;
}
export function paintCountyBanner(){const area=document.getElementById('area').value,c=counties.find(c=>c.id===area);const banner=document.getElementById('countyBanner');banner.hidden=!c;if(!c)return;
 document.getElementById('countyTitle').textContent=c.label;document.getElementById('countyFeatures').textContent=[c.map?'Incident map':null,c.arrests?'Daily arrests':null].filter(Boolean).join(' · ')||'Coming soon';
 const image=document.getElementById('countyPhoto');image.hidden=!c.photo;if(c.photo){image.src=c.photo;image.alt=c.name+' courthouse';}document.getElementById('countyCredit').textContent=c.credit||'';
}
export async function chooseHomeArea(force=false){
 const button=document.getElementById('changeHome');button.onclick=()=>chooseHomeArea(true);
 if(!reader||saved&&counties.some(c=>c.id===saved&&c.active)&&!force)return;
 return new Promise(resolve=>{
 const dialog=document.createElement('dialog');dialog.className='home-dialog';dialog.setAttribute('aria-labelledby','homeTitle');dialog.innerHTML=`<h2 id="homeTitle">Your home area</h2><p>Choose the county you want CrimeWatch to open to. Your choice follows your account on the app and website.</p><button type="button" id="suggestCounty">Use my location</button><p class="muted">Optional. We use your location on this device to suggest a county. Coordinates are not saved or sent for this setup.</p><label>Choose my county<select id="homeCounty">${activeCounties().map(c=>`<option value="${e(c.id)}">${e(c.label)}</option>`).join('')}</select></label><p id="homeStatus" role="status"></p><details><summary>Counties coming soon</summary><label>Find a county<input id="comingSearch" type="search" placeholder="County name"></label><p id="comingCounties"></p></details><div class="home-actions"><button type="button" id="saveHome">Set home area</button><button type="button" id="laterHome">${saved?'Cancel':'Choose later'}</button></div>`;
 document.body.append(dialog);const find=id=>dialog.querySelector('#'+id),finish=()=>{dialog.close();dialog.remove();resolve();};dialog.addEventListener('cancel',ev=>{ev.preventDefault();finish();});
 const pending=()=>{const q=find('comingSearch').value.toLowerCase();find('comingCounties').textContent=counties.filter(c=>!c.active&&c.name.toLowerCase().includes(q)).map(c=>c.name).join(' · ')||'No matching counties.';};find('comingSearch').oninput=pending;pending();find('homeCounty').value=activeCounties().some(c=>c.id===saved)?saved:document.getElementById('area').value;find('saveHome').disabled=!activeCounties().length;
 find('suggestCounty').onclick=async()=>{const b=find('suggestCounty');b.disabled=true;find('homeStatus').textContent='Finding your county…';try{const r=await fetch('texas-counties.json');if(!r.ok)throw Error('County boundaries could not load. Choose your county manually.');const boundaries=await r.json();const get=window.CWGetCurrentPosition||navigator.geolocation?.getCurrentPosition.bind(navigator.geolocation);if(!get)throw Error('Location is unavailable. Choose your county manually.');const position=await new Promise((ok,no)=>get(ok,no,{enableHighAccuracy:false,timeout:15000,maximumAge:60000}));const c=countyAtPoint(boundaries,position.coords.longitude,position.coords.latitude);if(!c){find('homeStatus').textContent='We could not confirm a Texas county. Choose your county manually.';return;}const current=counties.find(x=>x.id===c.id);if(!current?.active){find('homeStatus').textContent=c.name+', Texas is coming soon. Choose an available county below.';return;}find('homeCounty').value=c.id;find('homeStatus').textContent='Suggested: '+c.name+', Texas. Confirm with Set home area. Approximate location can be inaccurate near county boundaries.';}catch(error){find('homeStatus').textContent=error.code?locationError(error):error.message;}finally{b.disabled=false;}};
 find('laterHome').onclick=finish;find('saveHome').onclick=async()=>{const b=find('saveHome');b.disabled=true;try{const r=await fetch('area-api.php?action=home',{method:'POST',headers:{'Content-Type':'application/json','X-CSRF-Token':csrf},body:JSON.stringify({area:find('homeCounty').value})});const d=await r.json();if(!r.ok)throw Error(d.error);saved=d.area;try{localStorage.setItem('cw-area',saved);}catch{}const area=document.getElementById('area');area.value=saved;area.dispatchEvent(new Event('change',{bubbles:true}));paintCountyBanner();finish();}catch(error){find('homeStatus').textContent=error.message;b.disabled=false;}};
 dialog.showModal();
 });
}
