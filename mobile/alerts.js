import {Capacitor,registerPlugin} from '@capacitor/core';
import {Geolocation} from '@capacitor/geolocation';
import {currentLocation} from './location.js';
import {locationPoint} from './location.mjs';
import {Preferences} from '@capacitor/preferences';
import {PushNotifications} from '@capacitor/push-notifications';
import {App} from '@capacitor/app';
import {normalizeAlerts,categoryNames} from './alert-settings.mjs';
const endpoint='https://crimewatch.live/alerts-api.php';
let prefs,identity,token,registration,serial=Promise.resolve();
const NativeLocation=registerPlugin('CrimeWatchLocation');
let locationWatch,watchStarting=false,lastLocationSync=0,appActive=true;
window.addEventListener('pagehide',()=>{appActive=false;stopLocationWatch().catch(()=>{});});
async function backgroundLocation(requestPermission=false){
 if(!Capacitor.isNativePlatform())return;
 const enabled=!!(prefs.enabled&&prefs.locationMode==='current'&&prefs.backgroundLocation&&identity);
 const result=await NativeLocation.configure({enabled,id:identity?.id,secret:identity?.secret,requestPermission});
 const el=document.getElementById('alertLocationStatus');if(el&&enabled&&result.authorization!=='always')el.textContent='For background updates, choose Always in iPhone Settings → Privacy & Security → Location Services → CrimeWatch. While Using only updates while the app is open.';
}
async function stopLocationWatch(){if(locationWatch!==undefined){const id=locationWatch;locationWatch=undefined;await Geolocation.clearWatch({id});}}
async function watchLocation(){
 if(!Capacitor.isNativePlatform()||!appActive||watchStarting||locationWatch!==undefined)return;
 if(!prefs.enabled||prefs.locationMode!=='current')return;
 const permission=await Geolocation.checkPermissions();if(permission.location!=='granted'&&permission.coarseLocation!=='granted')return;
 watchStarting=true;try{locationWatch=await Geolocation.watchPosition({enableHighAccuracy:false,maximumAge:30000,timeout:20000},async(position,error)=>{
 if(error||!position||!appActive||!prefs.enabled||prefs.locationMode!=='current'||Date.now()-lastLocationSync<60000)return;
 const point=locationPoint(position);if(point.accuracy>5000)return;lastLocationSync=Date.now();prefs.center=point.center;prefs.locationUpdatedAt=point.updatedAt;
 await Preferences.set({key:'crimewatch.alerts',value:JSON.stringify(prefs)});document.dispatchEvent(new CustomEvent('crimewatch:position',{detail:point}));
 if(identity)request({action:'location',center:point.center,locationUpdatedAt:point.updatedAt}).catch(()=>message('Location update will retry when connected.'));
 });if(!appActive)await stopLocationWatch();}finally{watchStarting=false;}
}
const load=Promise.all(['crimewatch.alerts','crimewatch.pushIdentity','crimewatch.pushToken'].map(key=>Preferences.get({key}))).then(([p,i,t])=>{try{prefs=normalizeAlerts(JSON.parse(p.value||'{}'));}catch{prefs=normalizeAlerts();}try{identity=JSON.parse(i.value||'null');}catch{}token=t.value;});
const message=text=>{const el=document.getElementById('pushStatus');if(el)el.textContent=text;};
async function request(body,method='POST') {
 const response=await fetch(endpoint,{method,headers:{'Content-Type':'application/json',...(identity?{'X-CrimeWatch-Secret':identity.secret,'X-CrimeWatch-Device':identity.id}:{})},...(method==='POST'?{body:JSON.stringify(body)}:{})});
 if(response.status===401&&identity){identity=null;await Preferences.remove({key:'crimewatch.pushIdentity'});if(body?.action==='disable')return {ok:true};if(body?.action==='save')return request(body,method);return {reports:[]};}
 if(!response.ok)throw Error(response.status===503?'Alert service is not available yet. Your settings are saved locally.':'Could not sync alerts. Check your connection and try again.');
 return response.json();
}
async function syncNow(){await load;if(!Capacitor.isNativePlatform())return;
 if(!prefs.enabled){if(identity){await request({action:'disable'});message('All alerts are off.');}return;}
 if(!token)throw Error('Your iPhone has not registered for notifications yet. Please try again.');
 const {value}=await Preferences.get({key:'crimewatch.saved'});let rows=[];try{rows=JSON.parse(value||'[]');}catch{}
 const result=await request({action:'save',token,settings:prefs,watched:prefs.updates?rows.map(r=>r.id).slice(0,100):[]});
 if(result.identity){identity=result.identity;await Preferences.set({key:'crimewatch.pushIdentity',value:JSON.stringify(identity)});}
 await backgroundLocation();
 message('Alert settings synced. Delivery follows source publication and collection times.');
}
export function syncAlerts(){const task=serial.catch(()=>{}).then(syncNow);serial=task;return task;}
export async function refreshWatched(){await load;if(!prefs.enabled||!prefs.updates||!identity)return[];const r=await request(null,'GET');return r.reports||[];}
async function register(){
 if(registration)return registration;
 registration=(async()=>{
  let resolveToken,rejectToken,timer,success,failure;
  const pending=new Promise((resolve,reject)=>{resolveToken=resolve;rejectToken=reject;});
  try{
   success=await PushNotifications.addListener('registration',async result=>{try{token=result.value;await Preferences.set({key:'crimewatch.pushToken',value:token});resolveToken();}catch(e){rejectToken(e);}});
   failure=await PushNotifications.addListener('registrationError',()=>rejectToken(Error('Apple could not register notifications. Please try again.')));
   timer=setTimeout(()=>rejectToken(Error('Notification registration timed out. Try saving again.')),15000);
   await PushNotifications.register();await pending;
  }finally{clearTimeout(timer);await success?.remove();await failure?.remove();registration=null;}
 })();return registration;
}
async function resume(){await load;if(!Capacitor.isNativePlatform())return;
 if(prefs.enabled&&prefs.locationMode==='current'){try{const point=await currentLocation();if(point.accuracy<=5000){prefs.center=point.center;prefs.locationUpdatedAt=point.updatedAt;await Preferences.set({key:'crimewatch.alerts',value:JSON.stringify(prefs)});}}catch{message('Location could not refresh. Nearby alerts pause when the last location is over 6 hours old.');}}
 if(prefs.enabled){const p=await PushNotifications.checkPermissions();if(p.receive==='granted')await register();else{message('Notifications are disabled in iPhone Settings.');if(identity)await request({action:'disable'});return;}}
 await syncAlerts();
 await watchLocation();
}
export async function initAlerts(){await load;
 if(Capacitor.isNativePlatform()){
 await PushNotifications.addListener('pushNotificationActionPerformed',event=>{location.href=event.notification.data?.kind==='updates'?'saved.html?refresh=1':'index.html?alerts=1';});
 await App.addListener('appStateChange',({isActive})=>{appActive=isActive;if(isActive)resume().catch(e=>message(e.message));else stopLocationWatch().catch(()=>{});});
 resume().catch(e=>message(e.message));
 }
 const form=document.getElementById('alertSettings');if(!form)return;
 const $=id=>document.getElementById(id);
 const mapping={alertsEnabled:'enabled',alertsNearby:'nearby',alertsDaily:'daily',alertsUpdates:'updates'};
 for(const [id,key] of Object.entries(mapping))$(id).checked=prefs[key];
 $('alertLocationMode').value=prefs.locationMode;$('backgroundLocation').checked=prefs.backgroundLocation;
 $('alertRadius').value=String(prefs.radius);
 $('summaryHour').innerHTML=Array.from({length:24},(_,h)=>`<option value="${h}">${h%12||12}:00 ${h<12?'AM':'PM'}</option>`).join('');$('summaryHour').value=prefs.hour;
 prefs.timezone=Intl.DateTimeFormat().resolvedOptions().timeZone||prefs.timezone;$('summaryTimezone').textContent='Time zone: '+prefs.timezone+'. Arrives after this time when the server checks (about every 5 minutes).';
 for(const [key,label] of Object.entries(categoryNames)){const el=document.createElement('label');el.className='alert-category';const input=document.createElement('input');input.type='checkbox';input.name='category';input.value=key;input.checked=prefs.categories.includes(key);el.append(input,document.createTextNode(label));$('alertCategories').append(el);}
 let center=[...prefs.center],regions=[],map,circle,marker;
 function locationControls(){const follow=$('alertLocationMode').value==='current';$('alertAgency').disabled=follow;$('backgroundLocation').disabled=!follow;$('alertLocationStatus').textContent=follow?'Alerts use your latest approximate location across participating agencies. Nearby and daily alerts pause if that location is more than 6 hours old.':'Alerts stay centered on your chosen place.';}
 locationControls();$('alertLocationMode').onchange=locationControls;
 $('updateAlertLocation').onclick=async()=>{const b=$('updateAlertLocation');b.disabled=true;try{const point=await currentLocation();center=point.center;draw();map?.setView(center,12);$('alertLocationStatus').textContent='Current location selected. Save your settings to use it for alerts.';}catch(e){$('alertLocationStatus').textContent=e.message;}finally{b.disabled=false;}};
 document.addEventListener('crimewatch:position',e=>{if($('alertLocationMode').value==='current'){center=e.detail.center;draw();}});
 function draw(){center=center.map(n=>Math.round(n*1000)/1000);$('alertCenter').textContent=`Center: ${center[0].toFixed(3)}, ${center[1].toFixed(3)} · approximate location`;if(map){marker.setLatLng(center);circle.setLatLng(center).setRadius(Number($('alertRadius').value)*1609.344);}}
 if(window.L){map=L.map('alertMap').setView(center,11);L.tileLayer('https://tile.openstreetmap.org/{z}/{x}/{y}.png',{maxZoom:17,attribution:'© OpenStreetMap contributors'}).addTo(map);marker=L.marker(center).addTo(map);circle=L.circle(center,{radius:prefs.radius*1609.344,color:'#6ce5ed',fillOpacity:.13}).addTo(map);map.on('click',e=>{if(e.latlng.lat<25||e.latlng.lat>37||e.latlng.lng< -107||e.latlng.lng> -93)return;center=[e.latlng.lat,e.latlng.lng];draw();});}draw();$('alertRadius').onchange=draw;
 void (async()=>{try{const r=await fetch('api.php?agency=all&period=7');if(!r.ok)throw Error();regions=(await r.json()).regions;$('alertAgency').replaceChildren(...regions.map(r=>{const o=document.createElement('option');o.value=r.id;o.textContent=r.label;return o;}));$('alertAgency').value=prefs.agency;if(!$('alertAgency').value)$('alertAgency').value='polk';}catch{$('settingsResult').textContent='Area list could not refresh. Your saved area is still available.';const o=document.createElement('option');o.value=prefs.agency;o.textContent=prefs.agency;$('alertAgency').append(o);$('alertAgency').value=prefs.agency;}})();
 $('alertAgency').onchange=()=>{const r=regions.find(r=>r.id===$('alertAgency').value);if(r?.center){center=r.center;draw();map?.setView(center,11);}};
 if(!Capacitor.isNativePlatform())message('Preview only. Push notifications require the iPhone app.');
 form.onsubmit=async e=>{e.preventDefault();const result=$('settingsResult'),button=form.querySelector('[type=submit]');button.disabled=true;
 try{const next=normalizeAlerts({...prefs,...Object.fromEntries(Object.entries(mapping).map(([id,key])=>[key,$(id).checked])),center,locationMode:$('alertLocationMode').value,backgroundLocation:$('backgroundLocation').checked,agency:$('alertAgency').value,radius:Number($('alertRadius').value),hour:Number($('summaryHour').value),categories:[...form.querySelectorAll('[name=category]:checked')].map(el=>el.value)});
 if(next.enabled&&next.locationMode==='current'){const point=await currentLocation();if(point.accuracy>5000)throw Error('Location is too imprecise for nearby alerts. Try again or choose a fixed place.');next.center=point.center;next.locationUpdatedAt=point.updatedAt;}
 if(next.enabled&&!next.nearby&&!next.daily&&!next.updates)throw Error('Choose at least one alert type or turn off all alerts.');
 if(next.enabled&&(next.nearby||next.daily)&&!next.categories.length)throw Error('Choose at least one incident category.');
 if(next.enabled&&Capacitor.isNativePlatform()){let p=await PushNotifications.checkPermissions();if(p.receive==='prompt'||p.receive==='prompt-with-rationale')p=await PushNotifications.requestPermissions();if(p.receive!=='granted')throw Error('Allow notifications in iPhone Settings → Notifications → CrimeWatch Live, then save again.');await register();}
 prefs=next;await Preferences.set({key:'crimewatch.alerts',value:JSON.stringify(prefs)});await stopLocationWatch();if(!prefs.enabled||prefs.locationMode!=='current'||!prefs.backgroundLocation)await backgroundLocation();await syncAlerts();await backgroundLocation(true);await watchLocation();if(!prefs.enabled&&Capacitor.isNativePlatform())await PushNotifications.removeAllDeliveredNotifications();result.textContent=Capacitor.isNativePlatform()?(prefs.enabled?'Saved. Your alert choices are active.':'Saved. All alerts are off.'):'Preview settings saved. Install the new iPhone build to enable delivery.';
 }catch(err){result.textContent=err.message+(prefs?.enabled===false&&identity?' If you are offline, also turn off CrimeWatch in iPhone notification settings until this change syncs.':'');}finally{button.disabled=false;}};
}
