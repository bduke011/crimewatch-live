import {Geolocation} from '@capacitor/geolocation';
import {locationPoint} from './location.mjs';
export async function currentLocation(){
 try{return locationPoint(await Geolocation.getCurrentPosition({enableHighAccuracy:true,timeout:15000,maximumAge:30000}));}
 catch(e){if(/denied|permission|0003|0009/i.test(String(e)))throw Error('Location access is off. Allow it in iPhone Settings → Privacy & Security → Location Services → CrimeWatch, or choose an area manually.');throw Error('Could not get your location. Check Location Services and try again.');}
}
export function initLocationButton(){
 const anchor=document.getElementById('fitMap');if(!anchor)return;
 const button=document.createElement('button');button.id='myLocation';button.className='favorite-area';button.textContent='◎ My location';anchor.after(button);
 const status=document.createElement('p');status.id='locationStatus';status.className='location-status';status.setAttribute('role','status');document.getElementById('mapShell')?.before(status);
 button.onclick=async()=>{button.disabled=true;status.textContent='Finding your location…';try{const point=await currentLocation();document.dispatchEvent(new CustomEvent('crimewatch:locate',{detail:point}));status.textContent=`Your approximate location is shown${point.accuracy>1000?' (location accuracy is limited)':''}. Reports are available only from participating agencies; an empty map does not mean no crime.`;}catch(e){status.textContent=e.message;}finally{button.disabled=false;}};
}
