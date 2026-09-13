'use strict';

const $ = id => document.getElementById(id);

const categories = {property:'Property',person:'Person',drugs:'Drugs / alcohol',other:'Other activity'};

const colors = {property:'#c6f56c',person:'#ff947d',drugs:'#b9a1ff',other:'#6cc8dc'};

const state = {data:null,filtered:[],category:'all',view:'map',selected:null,map:null,layer:null,markers:new Map(),offset:0,request:0,controller:null,timer:null,backfillTimer:null,selectedRecord:null};

const escapeHtml = value => String(value ?? '').replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));

const dateLabel = date => new Date(date+'T12:00:00').toLocaleDateString('en-US',{month:'short',day:'numeric',...($('period').value==='archive'?{year:'numeric'}:{})});

const title = text => text.toLowerCase().replace(/\b\w/g,c=>c.toUpperCase()).replace(/\bCs\b/g,'CS').replace(/\bDwi\b/g,'DWI');

function initMap(){

 if(!window.L){$('mapWarning').hidden=false;$('mapWarning').textContent='Map software could not load. All reports remain available in the list.';return;}

 state.map=L.map('map',{zoomControl:true,scrollWheelZoom:false}).setView([30.79,-94.90],10);

 L.tileLayer('https://tile.openstreetmap.org/{z}/{x}/{y}.png',{maxZoom:17,attribution:'&copy; <a href="https://www.openstreetmap.org/copyright" target="_blank" rel="noopener">OpenStreetMap</a> contributors'}).on('tileerror',()=>{$('mapWarning').hidden=false;$('mapWarning').textContent='Some map tiles are unavailable. You can still browse the incident list.';}).addTo(state.map);

 state.layer=(L.markerClusterGroup?L.markerClusterGroup({maxClusterRadius:48,showCoverageOnHover:false,animate:false,iconCreateFunction:cluster=>{const count=cluster.getAllChildMarkers().reduce((n,m)=>n+(m.options.reportCount||1),0);return L.divIcon({className:'report-pin cluster-pin',html:`<span class="pin-core" style="--pin:#6ce5ed">${count.toLocaleString()}</span>`,iconSize:[46,46],iconAnchor:[23,23]});}}):L.layerGroup()).addTo(state.map);

}

function filterReports(){state.mapCell=null;state.offset=0;state.initialFit=false;return load();}

function card(r){return `<button class="incident ${state.selected===r.id?'selected':''}" data-id="${escapeHtml(r.id)}" aria-label="View details: ${escapeHtml(title(r.offense))}, ${dateLabel(r.date)}"><div class="incident-top"><i class="${r.category}"></i>${categories[r.category]}<time>${dateLabel(r.date)}</time></div><h3>${escapeHtml(title(r.offense))}</h3><p>${escapeHtml(title(r.location))}</p><span class="tag">${escapeHtml(r.agencyLabel)}</span><div class="incident-bottom"><span>${escapeHtml(r.time)} CT</span><span>View details ↗</span></div></button>`;}

function render(){

 const rows=state.filtered, summary=state.data.summary;
 if(!state.mapCell)state.overviewMap=state.data.map;
 const mapData=state.overviewMap||state.data.map;
 $('reportsTitle').textContent=state.mapCell?'Selected location':'Reports';
 $('mapSelection').hidden=!state.mapCell;
 $('mapSelectionText').textContent=state.mapCell?`${summary.total.toLocaleString()} reports here · Select a report for details.`:'';

 const chosen=$('agency').value;

 $('agency').innerHTML='<option value="all">All participating areas</option>'+state.data.regions.map(r=>`<option value="${escapeHtml(r.id)}">${escapeHtml(r.label)}</option>`).join('');$('agency').value=chosen;

 const region=state.data.regions.find(r=>r.id===chosen);

 $('coverageNote').textContent=state.data.coverage;

 $('areaLatest').textContent=region?`${region.total.toLocaleString()} saved · Latest report: ${region.latest||'none published in collected log'}`:'Coverage varies by area';

 document.querySelector('.county').textContent=region?.label||'Texas communities';

 document.querySelector('.map-label strong').textContent=region?.label||'Texas communities';

 if(!rows.length&&region?.center&&state.map)state.map.setView(region.center,11);



 $('total').textContent=summary.total;$('propertyTotal').textContent=summary.property;$('personTotal').textContent=summary.person;$('latest').textContent=summary.latest?dateLabel(summary.latest):'—';$('rangeLabel').textContent=$('period').value==='archive'?'Saved history':`Last ${$('period').value} days`;$('resultCount').textContent=`${summary.total} reports`;

 $('archiveFilters').hidden=$('period').value!=='archive';

 const archived=state.data.archive;$('archiveInfo').textContent=archived.startedAt?`${archived.total} saved reports · Archive started ${new Date(archived.startedAt).toLocaleDateString('en-US',{month:'short',day:'numeric',year:'numeric',timeZone:'America/Chicago'})}. Retained indefinitely.`:'No reports saved yet.';

 $('historyProgress').textContent=state.data.history?.message||'Choose both dates to collect missing historical records from the source.';

 $('pagination').hidden=summary.total<=state.data.page.limit;$('pageLabel').textContent=rows.length?`Showing ${state.offset+1}–${state.offset+rows.length} of ${summary.total} reports. Use Next for more reports.`:'No reports on this page.';$('previousPage').disabled=state.offset===0;$('nextPage').disabled=!state.data.page.hasMore;

 const mapped=state.data.map.mapped;document.querySelector('.feed-bottom').textContent=`All matches · ${mapped.toLocaleString()} mapped · ${state.data.map.unmapped.toLocaleString()} without coordinates. Nearby reports are grouped.`;

 $('feedItems').innerHTML=rows.length?rows.map(card).join(''):'<div class="empty">No matching reports.<br>Try a different location or filter.<button id="clearFilters">Clear filters</button></div>';

 $('tableBody').innerHTML=rows.map(r=>`<tr><td>${escapeHtml(title(r.offense))}</td><td>${escapeHtml(title(r.location))}</td><td>${dateLabel(r.date)}<br><span class="tag">${escapeHtml(r.time)} CT</span></td><td><span class="tag"><i class="${r.category}"></i>${categories[r.category]}</span></td><td><button data-id="${escapeHtml(r.id)}">Details ↗</button></td></tr>`).join('');$('tableEmpty').hidden=rows.length>0;

 if(state.layer){state.layer.clearLayers();state.markers.clear();mapData.points.forEach(p=>{

  const active=Object.keys(categories).filter(k=>p[k]>0),color=active.length===1?colors[active[0]]:'#dfe7f2';

  const chosen=state.mapCell&&p.lat===state.mapCell.lat&&p.lng===state.mapCell.lng;
  const marker=L.marker([p.lat,p.lng],{icon:L.divIcon({className:'report-pin',html:`<span class="pin-core ${chosen?'pin-selected':''}" style="--pin:${color}">${p.count}</span>`,iconSize:[38,38],iconAnchor:[19,19]}),reportCount:p.count,title:`Explore ${p.count} ${p.count===1?'report':'reports'} near this point`,keyboard:true}).addTo(state.layer);

  marker.bindTooltip(`${p.count.toLocaleString()} ${p.count===1?'report':'reports'}${p.count>1?' near here':''}`,{direction:'top'});

  if(p.count===1&&rows.some(r=>r.id===p.id)){marker.on('click',()=>showDetail(p.id));state.markers.set(p.id,marker);}

  else marker.on('click',async()=>{state.mapCell={lat:p.lat,lng:p.lng,precision:mapData.precision};state.offset=0;if(await load()){if(p.count===1)showDetail(state.data.incidents[0]?.id);else if(window.innerWidth<=760)document.querySelector('.feed').scrollIntoView({behavior:'smooth',block:'start'});}});

 });}



}

function setView(view){state.view=view;const mapView=view==='map';$('mapView').setAttribute('aria-pressed',String(mapView));$('listView').setAttribute('aria-pressed',String(!mapView));$('mapShell').hidden=!mapView;$('tableView').hidden=mapView;document.querySelector('.explorer').classList.toggle('list-mode',!mapView);if(mapView&&state.map)requestAnimationFrame(()=>state.map.invalidateSize());}

function setCategory(category){state.category=category;document.querySelectorAll('[data-category]').forEach(b=>b.setAttribute('aria-pressed',String(b.dataset.category===category)));return filterReports();}

function resetFilters(){$('search').value='';$('archiveFrom').value='';$('archiveTo').value='';return setCategory('all');}

function showDetail(id){const r=state.data?.incidents.find(r=>r.id===id);if(!r)return;state.selected=id;state.selectedRecord=r;$('detailContent').innerHTML=`<span class="tag"><i class="${r.category}"></i>${categories[r.category]}</span><h2 id="detailTitle">${escapeHtml(title(r.offense))}</h2><dl class="detail-grid"><div><dt>Incident / log date</dt><dd>${new Date(r.date+'T12:00:00').toLocaleDateString('en-US',{month:'long',day:'numeric',year:'numeric'})}</dd></div><div><dt>Time</dt><dd>${escapeHtml(r.time)} Central time</dd></div><div class="wide"><dt>Reported location</dt><dd>${escapeHtml(title(r.location))}</dd></div><div><dt>Offense code</dt><dd>${escapeHtml(r.offenseCode||'Not published')}</dd></div><div><dt>Last verified</dt><dd>${new Date(r.lastSeen).toLocaleDateString('en-US',{month:'short',day:'numeric',year:'numeric',timeZone:'America/Chicago'})}</dd></div><div class="wide"><dt>Area / coverage</dt><dd>${escapeHtml(r.agencyLabel)}</dd></div></dl>${r.details?.summary?`<p>${escapeHtml(r.details.summary)}</p>`:''}${Object.entries(r.details||{}).filter(([k])=>k!=='summary').map(([k,v])=>`<p><strong>${escapeHtml(title(k.replace(/([A-Z])/g,' $1')))}:</strong> ${escapeHtml(v)}</p>`).join('')}<p class="record-status">${r.agency!=='polk'?'Saved published record. See the last verified date and area coverage above.':r.sourceState==='aged_out'?'Saved history. This report has aged out of the current 30-day source window.':r.sourceState==='not_listed'?'Archived copy. No longer listed by the source for these dates; it may have been corrected or removed. Verify it with the agency.':'Present in the most recently collected source listing.'}</p><p>Details reflect the published summary; a full police narrative may not be available.</p><p>Publicly reported activity. A report does not establish guilt. Map positions are approximate and do not identify a verified property.</p>`;$('showOnMap').disabled=!state.map||!Number.isFinite(r.lat)||!Number.isFinite(r.lng);const index=state.data.incidents.findIndex(item=>item.id===id);$('previousReport').disabled=index<=0;$('nextReport').disabled=index>=state.data.incidents.length-1;if(!$('detailDialog').open)$('detailDialog').showModal();}

function fitMap(){if(!state.map)return;const points=(state.overviewMap?.points||state.data?.map.points||[]).map(r=>[r.lat,r.lng]);if(points.length)state.map.fitBounds(points,{padding:[65,80],maxZoom:12});else state.map.setView([30.79,-94.90],10);}

async function load(){

 clearTimeout(state.timer);clearTimeout(state.backfillTimer);state.controller?.abort();state.controller=new AbortController();const request=++state.request;

 $('refresh').disabled=true;document.querySelector('.explorer').setAttribute('aria-busy','true');const archive=$('period').value==='archive';$('archiveFilters').hidden=!archive;

 const params=new URLSearchParams({period:$('period').value,category:state.category,q:$('search').value.trim(),offset:String(state.offset),agency:$('agency').value});

 if(state.mapCell){params.set('mapLat',String(state.mapCell.lat));params.set('mapLng',String(state.mapCell.lng));params.set('mapPrecision',String(state.mapCell.precision));}
 if(archive){if($('archiveFrom').value)params.set('from',$('archiveFrom').value);if($('archiveTo').value)params.set('to',$('archiveTo').value);}

 try{

  const response=await fetch('api.php?'+params,{headers:{Accept:'application/json'},signal:state.controller.signal});const data=await response.json();if(!response.ok)throw new Error(response.status===400?data.error:'Feed unavailable');if(!Array.isArray(data.incidents)||!data.summary||!data.page||!data.range||!data.fetchedAt||!Array.isArray(data.map?.points))throw new Error('Invalid feed');if(request!==state.request)return false;

  state.data=data;state.filtered=data.incidents;$('freshness').textContent=data.stale?'Saved reports · collection pending':'Archive connected';const updated=new Date(data.fetchedAt);$('updated').textContent=`Checked ${updated.toLocaleDateString('en-US',{month:'short',day:'numeric',timeZone:'America/Chicago'})}, ${updated.toLocaleTimeString('en-US',{hour:'numeric',minute:'2-digit',timeZone:'America/Chicago'})} CT`;

  $('notice').hidden=!data.stale;if(data.stale)$('notice').textContent='Collection is pending or temporarily unavailable for this area. Saved reports remain searchable.';

  render();if(!state.initialFit){fitMap();state.initialFit=true;}

  if(data.history?.status==='collected') state.backfillTimer=setTimeout(()=>{if(request===state.request)load();},600);

  return true;

 }catch(error){

  if(error.name==='AbortError'||request!==state.request)return false;

  $('notice').hidden=false;$('notice').textContent=error.message==='Start date must be before end date.'?error.message:state.data?'Could not load these filters. Previous results are still shown; try Refresh again.':'We could not load reports. Please try Refresh in a moment.';

  if(!state.data){$('freshness').textContent='Feed temporarily unavailable';$('feedItems').innerHTML='<div class="empty">Reports are unavailable right now.<br>Use Refresh to try again.</div>';$('resultCount').textContent='Unavailable';}return false;

 }finally{if(request===state.request){$('refresh').disabled=false;document.querySelector('.explorer').setAttribute('aria-busy','false');}}

}

$('agency').addEventListener('change',()=>{state.initialFit=false;$('search').value='';$('archiveFrom').value='';$('archiveTo').value='';filterReports();});

$('search').addEventListener('input',()=>{clearTimeout(state.timer);state.timer=setTimeout(filterReports,250);});$('archiveFrom').addEventListener('change',filterReports);$('archiveTo').addEventListener('change',filterReports);$('period').addEventListener('change',filterReports);document.querySelectorAll('[data-category]').forEach(b=>b.addEventListener('click',()=>setCategory(b.dataset.category)));$('mapView').onclick=()=>setView('map');$('listView').onclick=()=>setView('list');$('fitMap').onclick=fitMap;$('refresh').onclick=load;

document.addEventListener('click',event=>{const trigger=event.target.closest('[data-id]');if(trigger)showDetail(trigger.dataset.id);if(event.target.id==='clearFilters')resetFilters();});

document.querySelectorAll('.close-dialog').forEach(b=>b.onclick=()=>b.closest('dialog').close());[$('aboutButton'),$('dataNotes')].forEach(b=>b.onclick=()=>$('aboutDialog').showModal());document.querySelectorAll('dialog').forEach(d=>d.addEventListener('click',event=>{if(event.target===d){const box=d.getBoundingClientRect();if(event.clientX<box.left||event.clientX>box.right||event.clientY<box.top||event.clientY>box.bottom)d.close();}}));

$('showOnMap').onclick=()=>{const r=state.selectedRecord;if(!r)return;$('detailDialog').close();setView('map');requestAnimationFrame(()=>{state.map.setView([r.lat,r.lng],14);const marker=state.markers.get(r.id);if(marker){if(state.layer.zoomToShowLayer)state.layer.zoomToShowLayer(marker,()=>marker.openTooltip());else marker.openTooltip();}$('mapShell').scrollIntoView({behavior:'smooth',block:'center'});});};

$('clearMapSelection').onclick=()=>{state.mapCell=null;state.offset=0;load();};
$('resetWorkspace').onclick=()=>{$('period').value='30';resetFilters();};
for(const [id,step] of [['previousReport',-1],['nextReport',1]])$(id).onclick=()=>{const index=state.data.incidents.findIndex(r=>r.id===state.selected);const next=state.data.incidents[index+step];if(next)showDetail(next.id);};
$('previousPage').onclick=()=>{state.offset=Math.max(0,state.offset-state.data.page.limit);load();};$('nextPage').onclick=()=>{state.offset+=state.data.page.limit;load();};

// Browser tabs check the cache; the collector's Central-time schedule controls new source collection.

setInterval(()=>{if(document.visibilityState==='visible'&&!$('detailDialog').open&&!$('aboutDialog').open)load();},300000);

const context=document.modelContext;

if(context?.registerTool){Promise.resolve(context.registerTool({name:'filter_incidents',description:'Search the visible incident map and list, including saved historical reports with period archive.',inputSchema:{type:'object',properties:{query:{type:'string',maxLength:200},category:{type:'string',enum:['all','property','person','drugs','other']},period:{type:'string',enum:['7','14','30','archive']}},additionalProperties:false},annotations:{readOnlyHint:false,untrustedContentHint:true},async execute(input){if(!input||typeof input!=='object'||Object.keys(input).some(k=>!['query','category','period'].includes(k))||input.query!==undefined&&(typeof input.query!=='string'||input.query.length>200)||input.category!==undefined&&!['all',...Object.keys(categories)].includes(input.category)||input.period!==undefined&&!['7','14','30','archive'].includes(input.period))throw new Error('Invalid filters');if(input.query!==undefined)$('search').value=input.query;if(input.period!==undefined)$('period').value=input.period;if(!await setCategory(input.category??state.category))throw new Error('Reports could not be loaded');return {count:state.data.summary.total,shown:state.filtered.length,view:state.view};}})).catch(()=>{});}

initMap();load();

