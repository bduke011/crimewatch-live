(()=>{'use strict';
const $=id=>document.getElementById(id),esc=v=>String(v??'').replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
const date=v=>/^\d{4}-\d{2}-\d{2}$/.test(v||'')?new Date(v+'T12:00:00Z').toLocaleDateString('en-US',{timeZone:'UTC',month:'short',day:'numeric',year:'numeric'}):'Not reported';
const updated=v=>v&&!Number.isNaN(Date.parse(v))?new Date(v).toLocaleString('en-US',{timeZone:'America/Chicago',month:'short',day:'numeric',year:'numeric',hour:'numeric',minute:'2-digit'})+' CT':'Unknown';
const sourceName=s=>s==='archive'?'Booking archive':'Current roster';
const params=new URLSearchParams(location.search),sources=['archive','roster'];
let query='',generation=0,originName='',startingKey='',nameEdited=false;
const results={},controllers={},selected=new Map();
$('personName').value=(params.get('name')||'').slice(0,200);
function syncLinks(){const name=$('personName').value.trim(),q=encodeURIComponent(name);$('rosterLink').href='roster.html?q='+q;$('archiveLink').href='jail.html?q='+q;}
function referenceLink(r){return r.source==='roster'?'roster.html#'+encodeURIComponent(r.id):'jail.html?id='+encodeURIComponent(r.id);}
function review(){
 $('reviewCount').textContent=selected.size;$('clearReview').disabled=!selected.size;
 $('reviewList').innerHTML=selected.size?[...selected.values()].sort((a,b)=>(b.booked||'').localeCompare(a.booked||'')).map(r=>`<div class="review-item"><strong>${esc(r.name)}</strong><span>${esc(date(r.booked))} · ${sourceName(r.source)}</span><small>Marked by you for this review</small><button type="button" data-remove="${esc(r.key)}" aria-label="Remove ${esc(r.name)} from ${sourceName(r.source)}">Remove</button></div>`).join(''):'<p>No records selected.</p>';
}
function chargeHTML(c){return `<li><strong>${esc(c.description||'Charge not listed')}</strong><dl>${[['Agency',c.agency],['Reference',c.reference],['Reference type',c.referenceType],['Jurisdiction',c.jurisdiction],['Listed bond',[c.bond,c.bondType].filter(Boolean).join(' · ')],['Court date',c.courtDate],['Court',c.court]].filter(([,v])=>v).map(([k,v])=>`<div><dt>${k}</dt><dd>${esc(v)}</dd></div>`).join('')}</dl></li>`;}
function render(source){
 const d=results[source];if(!d)return;
 $(source+'Results').innerHTML=d.records.length?d.records.map((r,i)=>`<article class="candidate"><div class="candidate-heading"><h3>${esc(r.name)}</h3><span class="match-label">${r.key===startingKey?'Starting booking':'Possible match'}</span></div><dl class="candidate-facts"><div><dt>Booked</dt><dd>${esc(date(r.booked))}</dd></div><div><dt>Age in record</dt><dd>${esc(r.age??'Not reported')}</dd></div><div><dt>Locality</dt><dd>${esc(r.locality||'Not reported')}</dd></div></dl><p class="record-status">${source==='archive'?(r.released?'Release date recorded: '+esc(date(r.released)):'Release not reported in this snapshot'):'Listed on the roster as of '+esc(updated(d.checkedAt))}</p><details><summary>${r.charges.length} listed charge${r.charges.length===1?'':'s'} · View details</summary><ul class="research-charges">${r.charges.map(chargeHTML).join('')||'<li>No charge details are available.</li>'}</ul>${source==='archive'?`<p class="source-meta">Report snapshot: ${esc(date(r.report_date))}</p>`:`<p class="source-meta">Charge details last read: ${esc(updated(r.last_detail))}. Listed bonds and hearings may change.</p>`}</details><div class="candidate-actions"><a href="${esc(referenceLink(r))}">View full booking →</a><label><input type="checkbox" data-source="${source}" data-index="${i}" ${selected.has(r.key)?'checked':''}> I reviewed the identifiers; include this record</label></div></article>`).join(''):'<div class="source-empty">No matching records in this source for this search. Try a shorter name or another spelling. This is not a finding of no history.</div>';
 $(source+'Pages').hidden=d.pages<=1;$(source+'Page').textContent=`Page ${d.page} of ${d.pages}`;$(source+'Prev').disabled=d.page<=1;$(source+'Next').disabled=d.page>=d.pages;
}
async function searchSource(source,page=1){
 controllers[source]?.abort();const c=new AbortController();controllers[source]=c;const g=generation,currentQuery=query;delete results[source];$(source+'Meta').textContent='Waiting for this source to respond.';let timedOut=false;const timeout=setTimeout(()=>{timedOut=true;c.abort();},20000);
 $(source+'Badge').textContent='Searching…';$(source+'Badge').className='source-badge';$(source+'Panel').setAttribute('aria-busy','true');$(source+'Pages').hidden=true;$(source+'Results').textContent='Searching this source…';
 try{
  const response=await fetch('research-api.php?'+new URLSearchParams({name:currentQuery,source,page:String(page)}),{signal:c.signal});const data=await response.json();if(!response.ok)throw Error(data.error||'Source unavailable');
  if(c.signal.aborted||g!==generation)return;
  results[source]=data;$(source+'Badge').textContent=data.warnings.length?'Searched · coverage warning':'Searched';$(source+'Badge').className='source-badge '+(data.warnings.length?'warning':'success');
  $(source+'Meta').textContent=`${data.total} possible matching record${data.total===1?'':'s'}. Last collection: ${updated(data.checkedAt)}.`+(data.coverage?` Archive dates: ${date(data.coverage.earliest)} – ${date(data.coverage.latest)}; gaps may exist.`:'')+' '+data.warnings.join(' ');
  render(source);
 }catch(e){if((e.name==='AbortError'&&!timedOut)||g!==generation)return;delete results[source];$(source+'Badge').textContent='Unavailable';$(source+'Badge').className='source-badge warning';$(source+'Meta').textContent='This source was not searched successfully.';$(source+'Results').innerHTML=`<div class="source-empty">${esc(timedOut?'This source took too long to respond. Try again.':e.message)} <button type="button" data-retry="${source}">Retry source</button></div>`;}
 finally{clearTimeout(timeout);if(controllers[source]===c)$(source+'Panel').setAttribute('aria-busy','false');}
}
function search(){const name=$('personName').value.trim();if(name.length<2||new TextEncoder().encode(name).length>200){$('researchStatus').textContent='Enter at least two letters and a shorter name.';return;}query=name;generation++;selected.clear();review();$('researchStatus').textContent='';$('searchSummary').textContent='Results for “'+name+'” — possible matches until you review the identifiers.';if(originName&&originName!==name){$('startingBooking').hidden=true;startingKey='';}for(const source of sources)searchSource(source);}
$('researchForm').onsubmit=e=>{e.preventDefault();search();};$('personName').oninput=()=>{nameEdited=true;syncLinks();};
$('clearReview').onclick=()=>{selected.clear();review();sources.forEach(render);};
document.addEventListener('change',e=>{const t=e.target;if(!t.matches('[data-source][data-index]'))return;const r=results[t.dataset.source]?.records[Number(t.dataset.index)];if(!r)return;if(t.checked)selected.set(r.key,{...r});else selected.delete(r.key);review();});
document.addEventListener('click',e=>{const remove=e.target.closest('[data-remove]'),retry=e.target.closest('[data-retry]');if(remove){selected.delete(remove.dataset.remove);review();sources.forEach(render);}if(retry)searchSource(retry.dataset.retry);});
for(const s of sources){$(s+'Prev').onclick=()=>searchSource(s,results[s].page-1);$(s+'Next').onclick=()=>searchSource(s,results[s].page+1);}
async function start(){
 const source=params.get('source'),id=params.get('record');
 if(id&&((source==='archive'&&/^[a-f0-9]{24}$/.test(id))||(source==='roster'&&/^[A-Za-z0-9%]{1,64}$/.test(id)))){
  $('startingBooking').hidden=false;$('startingBooking').textContent='Loading the starting booking…';
  try{const response=await fetch('research-api.php?'+new URLSearchParams({source,record:id}),{signal:AbortSignal.timeout(20000)});if(!response.ok)throw Error();const d=await response.json();const r=d.records?.[0];if(!r)throw Error();
   originName=r.name;startingKey=source+':'+id;
   $('startingBooking').innerHTML=`<span class="eyebrow">STARTING BOOKING / ${sourceName(source).toUpperCase()}</span><h2>${esc(r.name)}</h2><p>Booked ${esc(date(r.booked||r.admit_date))} · Age in record: ${esc(r.age??'Not reported')} · ${esc(r.locality||'Locality not reported')}</p><small>Use this record as a reference when comparing possible matches. It does not establish that other same-name records belong to this person.</small>`;
   if(!generation&&!nameEdited){$('personName').value=r.name;syncLinks();}else if($('personName').value.trim()!==r.name){$('startingBooking').hidden=true;startingKey='';}
  }catch{$('startingBooking').textContent='The starting booking could not be retrieved. It may no longer be listed. You can still run a name search below.';}
 }
 if(!generation&&!nameEdited&&$('personName').value.trim())search();
}
syncLinks();start();
})();
