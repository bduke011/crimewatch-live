let countyCatalog=null;
export function setCommunityCatalog(rows){countyCatalog=rows;}
import {escapeHtml} from './model.js';

export const initialCommunities=[
 {id:'polk',label:'Polk County, TX'}, {id:'liberty',label:'Liberty County, TX'}, {id:'angelina',label:'Angelina County, TX'},
 {id:'shsu',label:'Huntsville - SHSU'}, {id:'houston',label:'Houston'},
 {id:'shsu-conroe',label:'Conroe - SHSU'}, {id:'shsu-woodlands',label:'The Woodlands - SHSU'},
 {id:'sfa',label:'Nacogdoches - SFA'}, {id:'forney',label:'Forney'},
 {id:'nolan',label:'Nolan County'}, {id:'kaufman',label:'Kaufman County'}
];

export function communityOptions(regions=initialCommunities){
 if(countyCatalog)return countyCatalog.filter(r=>r.active).map(r=>`<option value="${escapeHtml(r.id)}">${escapeHtml(r.label)}</option>`).join('');
 const rows=new Map([['all',{id:'all',label:'All covered communities'}]]);
 for(const row of regions)if(!['liberty','angelina'].includes(row.id))rows.set(row.id,row);
 rows.set('liberty',{id:'liberty',label:'Liberty County, TX'});
 rows.set('angelina',{id:'angelina',label:'Angelina County, TX'});
 return [...rows.values()].map(row=>`<option value="${escapeHtml(row.id)}">${escapeHtml(row.label)}</option>`).join('');
}

export const libertyAvailability={
 title:'Liberty County reports',
 arrests:'Individual Liberty County booking records are not available here yet. Bluebonnet News report metadata has been reviewed, but individual bookings have not been imported. This does not establish that no arrests occurred.',
 map:'Liberty County incident map coverage is not connected yet. Booking reports do not supply verified incident locations. An empty map does not mean no crime.',
 coverage:'Liberty County, TX — coverage being prepared. Source: Bluebonnet News.',
 freshness:'Liberty County collection is not connected. No live booking check time is available.'
};
