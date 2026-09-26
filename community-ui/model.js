export const escapeHtml=v=>String(v??'').replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
export const charges=r=>(r.arrests||[]).flatMap(a=>(a.charges||[]).map(c=>({...c,agency:a.agency,arrestDate:a.dateTime})));
export const validPoint=p=>Number.isFinite(p.lat)&&Number.isFinite(p.lng)&&Math.abs(p.lat)<=90&&Math.abs(p.lng)<=180;
export const dayLabel=v=>/^\d{4}-\d{2}-\d{2}$/.test(v||'')?new Date(v+'T12:00:00Z').toLocaleDateString('en-US',{month:'long',day:'numeric',year:'numeric',timeZone:'UTC'}):'Not supplied';
export const arrestDates=r=>[...new Set((r.arrests||[]).map(a=>a.dateTime).filter(Boolean))];
