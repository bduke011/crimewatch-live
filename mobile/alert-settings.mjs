export const categoryNames={person:'Person',property:'Property',drugs:'Drugs / alcohol',other:'Other activity'};
export const defaultAlerts=()=>({enabled:false,nearby:true,daily:true,updates:true,agency:'polk',center:[30.79,-94.90],radius:5,categories:Object.keys(categoryNames),hour:18,timezone:Intl.DateTimeFormat().resolvedOptions().timeZone||'America/Chicago'});
export function normalizeAlerts(value={}) {
 const d=defaultAlerts(),v={...d,...value};
 return {...d,...v,enabled:v.enabled===true,nearby:v.nearby===true,daily:v.daily===true,updates:v.updates===true,
 center:Array.isArray(v.center)&&v.center.length===2&&v.center.every(Number.isFinite)&&v.center[0]>=25&&v.center[0]<=37&&v.center[1]>=-107&&v.center[1]<=-93?v.center:d.center,
 radius:[1,3,5,10,25].includes(Number(v.radius))?Number(v.radius):5,
 categories:Array.isArray(v.categories)?[...new Set(v.categories.filter(c=>c in categoryNames))]:d.categories,
 hour:Number.isInteger(Number(v.hour))&&Number(v.hour)>=0&&Number(v.hour)<=23?Number(v.hour):18};
}
