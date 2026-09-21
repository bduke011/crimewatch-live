import {initLocationButton} from './location.js';
import { Capacitor } from '@capacitor/core';
import { Preferences } from '@capacitor/preferences';
import { Share } from '@capacitor/share';
import { Browser } from '@capacitor/browser';
import { apiURL, normalizeSaved, toggleSaved, shareText } from './data.mjs';
import { initAlerts, syncAlerts, refreshWatched } from './alerts.js';

// Run before the bundled website scripts. PHP stays on the HTTPS server.
const originalFetch = window.fetch.bind(window);
window.fetch = (input, options) => originalFetch(window.CRIMEWATCH_PREVIEW && location.hostname === '127.0.0.1' ? input : typeof input === 'string' || input instanceof URL ? apiURL(String(input),location.href) : input,options);
const esc = value => String(value ?? '').replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
let saved = [], selected;
const ready = Preferences.get({key:'crimewatch.saved'}).then(({value})=>{try{saved=normalizeSaved(JSON.parse(value||'[]'));}catch{saved=[];}});
let statusTimer;
function announce(message){const el=document.getElementById('mobileStatus');if(el){clearTimeout(statusTimer);el.textContent=message;statusTimer=setTimeout(()=>el.textContent='',4500);}}
async function share(record){try{await Share.share({title:'CrimeWatch reported incident',text:shareText(record),dialogTitle:'Share report'});}catch(e){if(!/cancel|dismiss/i.test(String(e)))announce('Sharing is unavailable. Try again from your iPhone.');}}
function renderSaved(){
 const list=document.getElementById('savedList');if(!list)return;
 list.innerHTML=saved.length?saved.map(r=>`<article class="saved-card"><span class="mobile-eyebrow">SAVED ${esc(new Date(r.savedAt).toLocaleDateString())}</span><h2>${esc(r.offense)}</h2><p>${esc(r.date)} · ${esc(r.agencyLabel)}</p><p>${esc(r.location)}</p><p class="saved-context">${r.refreshedAt?'Refreshed from the archive.':'Saved copy; the source may have changed.'} A report does not establish guilt.</p><div class="saved-actions"><button data-saved-share="${esc(r.id)}">Share</button><button data-saved-remove="${esc(r.id)}">Remove</button></div></article>`).join(''):'<div class="saved-empty"><span aria-hidden="true">☆</span><h2>Keep a report handy</h2><p>Open an incident and tap Save report. Your saved copies stay on this device and are available offline.</p><a href="incidents.html">Explore incidents →</a></div>';
}
document.addEventListener('crimewatch:detail',async event=>{
 selected=event.detail;await ready;
 const bottom=document.querySelector('#detailDialog .dialog-bottom');if(!bottom)return;
 let actions=document.getElementById('nativeActions');
 if(!actions){actions=document.createElement('div');actions.id='nativeActions';actions.className='saved-actions';bottom.before(actions);}
 actions.innerHTML=`<button id="saveIncident">${saved.some(r=>r.id===selected.id)?'Remove saved report':'Save report'}</button><button id="shareIncident">Share report</button>`;
 document.getElementById('saveIncident').onclick=async()=>{try{const next=toggleSaved(saved,selected);await Preferences.set({key:'crimewatch.saved',value:JSON.stringify(next)});saved=next;document.dispatchEvent(new Event('crimewatch:saved-changed'));document.getElementById('saveIncident').textContent=saved.some(r=>r.id===selected.id)?'Remove saved report':'Save report';announce('Saved reports updated.');}catch{announce('Could not save this report. Please try again.');}};
 document.getElementById('shareIncident').onclick=()=>share(selected);
});
document.addEventListener('DOMContentLoaded',async()=>{
 document.body.classList.add('mobile-app');
 const page=location.pathname.split('/').pop()||'index.html';
 const tabs=[['index.html','◎','Home'],['roster.html','▤','Roster'],['research.html','⌕','Research'],['saved.html','☆','Saved'],['settings.html','⚙','Settings']];
 const nav=document.createElement('nav');nav.className='mobile-tabs';nav.setAttribute('aria-label','App navigation');
 nav.innerHTML=tabs.map(([url,icon,label])=>`<a href="${url}" ${page===url||page==='fbi-collections.html'&&url==='fbi.html'?'aria-current="page"':''}><span aria-hidden="true">${icon}</span>${label}</a>`).join('');document.body.append(nav);
 const status=document.createElement('p');status.id='mobileStatus';status.setAttribute('role','status');status.className='mobile-status';document.body.append(status);
 const offline=document.createElement('div');offline.className='mobile-offline';offline.textContent='You’re offline. Saved reports are still available.';offline.hidden=navigator.onLine;document.body.prepend(offline);
 window.addEventListener('offline',()=>offline.hidden=false);window.addEventListener('online',()=>offline.hidden=true);
 await ready;renderSaved();initLocationButton();
 initAlerts().catch(()=>announce('Alert settings could not load. Please try again.'));
 if(page==='saved.html')refreshWatched().then(async reports=>{if(!reports.length)return;let changes=0;const next=saved.map(old=>{const current=reports.find(r=>r.id===old.id);if(!current||current.changedAt===old.changedAt)return old;changes++;return {...old,...current,refreshedAt:new Date().toISOString()};});if(changes){await Preferences.set({key:'crimewatch.saved',value:JSON.stringify(next)});saved=next;document.dispatchEvent(new Event('crimewatch:saved-changed'));renderSaved();announce('Saved reports refreshed from the archive.');}}).catch(()=>announce('Could not refresh saved reports. Showing your offline copies.'));
 document.addEventListener('crimewatch:saved-changed',()=>syncAlerts().catch(()=>announce('Report saved locally. Alert watch list will sync when you reconnect.')));
 const area=document.getElementById('agency');
 if(area){const fav=document.createElement('button');fav.className='favorite-area';fav.textContent='Save area';area.after(fav);fav.onclick=async()=>{await Preferences.set({key:'crimewatch.area',value:area.value});announce('Area saved as your starting view.');};
 const {value}=await Preferences.get({key:'crimewatch.area'});if(value){const apply=()=>{if([...area.options].some(o=>o.value===value)){area.value=value;area.dispatchEvent(new Event('change'));return true;}return false;};if(!apply()){const observer=new MutationObserver(()=>{if(apply())observer.disconnect();});observer.observe(area,{childList:true});setTimeout(()=>observer.disconnect(),30000);}}}
 document.addEventListener('click',async e=>{
  const remove=e.target.closest('[data-saved-remove]'),shareButton=e.target.closest('[data-saved-share]');
  if(remove){try{const next=saved.filter(r=>r.id!==remove.dataset.savedRemove);await Preferences.set({key:'crimewatch.saved',value:JSON.stringify(next)});saved=next;document.dispatchEvent(new Event('crimewatch:saved-changed'));renderSaved();}catch{announce('Could not remove the saved report.');}}
  if(shareButton){const r=saved.find(r=>r.id===shareButton.dataset.savedShare);if(r)await share(r);}
 });
});
document.addEventListener('click',e=>{const a=e.target.closest('a[href]');if(!a)return;const url=new URL(a.getAttribute('href'),location.href);if(['http:','https:'].includes(url.protocol)&&url.origin!==location.origin&&Capacitor.isNativePlatform()){e.preventDefault();Browser.open({url:url.href}).catch(()=>announce('Could not open this link.'));}});
