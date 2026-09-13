export function locationPoint(position){
 const c=position?.coords;
 if(!c||!Number.isFinite(c.latitude)||!Number.isFinite(c.longitude)||c.latitude< -90||c.latitude>90||c.longitude< -180||c.longitude>180)throw Error('Your location is unavailable. Please try again.');
 return {center:[Number(c.latitude.toFixed(3)),Number(c.longitude.toFixed(3))],accuracy:Math.max(0,Number(c.accuracy)||0),updatedAt:Math.floor(Date.now()/1000)};
}
export function freshLocation(settings,now=Math.floor(Date.now()/1000)) {return settings.locationMode!=='current'||(Number.isFinite(settings.locationUpdatedAt)&&settings.locationUpdatedAt>now-21600&&settings.locationUpdatedAt<=now+300);}
