const $=id=>document.getElementById(id);
let timer, loading=false;
const when=value=>value?new Date(typeof value==='number'?value*1000:value).toLocaleString('en-US',{timeZone:'America/Chicago',month:'short',day:'numeric',hour:'numeric',minute:'2-digit'})+' Central':'Not yet';
async function api(action, post=false) {
  const options={cache:'no-store',credentials:'same-origin'};
  if(post){
    const session=await (await fetch('ads-api.php?action=session',{cache:'no-store'})).json();
    options.method='POST';options.headers={'X-CSRF-Token':session.csrf};
  }
  const response=await fetch('pdf-admin-api.php?action='+action,options);
  const data=await response.json();
  if(!response.ok)throw Error(data.error||'Unable to check reports.');
  return data;
}
function render(data){
  const j=data.job,r=j?.result||{},busy=j&&['queued','running'].includes(j.state);
  $('pdfLastCheck').textContent='Last importer check: '+when(data.lastImportCheck)+'. Automatic checks continue at midnight, 5 a.m., and noon Central.';
  $('pdfCheck').disabled=!!busy;
  $('pdfImport').hidden=!(j?.action==='check'&&['complete','partial'].includes(j.state)&&r.available>0);
  $('pdfImport').disabled=!!busy;
  $('pdfFiles').replaceChildren();
  for(const report of r.reports||[]){const li=document.createElement('li');li.textContent=report.name+' — '+report.kind;$('pdfFiles').append(li);}
  let message='Check the source for new or changed PDFs. Nothing is published until you choose Import available reports.';
  if(j){
    if(j.state==='queued')message='Request queued. It normally starts within one minute; it may wait for a scheduled collection to finish. You can leave this page and return.';
    else if(j.state==='running')message=j.action==='check'?'Checking PDF files for new or changed reports…':'Importing reports and photos…';
    else if(j.state==='failed')message=r.message||'The request failed. Please check again.';
    else if(j.action==='check')message=r.available?`${r.available} new or changed PDF(s) available. Checked ${when(j.finished)}.`:r.failed?`No new reports confirmed; the check was incomplete. Checked ${when(j.finished)}.`:`No new or changed PDFs found. Checked ${when(j.finished)}.`;
    else message=`Imported ${r.processed||0} new or changed report(s), including available photos. Finished ${when(j.finished)}.`;
    if(r.failed)message+=` ${r.failed} file(s) could not be checked or imported. Try another check; this result is incomplete.`;
    if(busy&&Date.now()/1000-j.created>1200)message+=' This is taking longer than expected. The request is still pending; do not repeatedly submit it.';
  }
  $('pdfStatus').textContent=message;
  clearTimeout(timer);if(busy)timer=setTimeout(load,5000);
}
async function load(){if($('dashboard').hidden||loading)return;loading=true;try{render(await api('status'));}catch(e){$('pdfStatus').textContent=e.message;}finally{loading=false;}}
async function start(action){clearTimeout(timer);$('pdfCheck').disabled=true;$('pdfImport').disabled=true;try{render(await api(action,true));}catch(e){$('pdfStatus').textContent=e.message;$('pdfCheck').disabled=false;$('pdfImport').disabled=false;}}
$('pdfCheck').onclick=()=>start('check');$('pdfImport').onclick=()=>start('import');
new MutationObserver(load).observe($('dashboard'),{attributes:true,attributeFilter:['hidden']});load();
