document.addEventListener('crimewatch:locate',async event=>{
 const {center,accuracy}=event.detail;if(!state.map||!Array.isArray(center))return;
 $('agency').value='all';$('period').value='30';$('search').value='';state.category='all';state.mapCell=null;state.offset=0;state.initialFit=true;
 document.querySelectorAll('[data-category]').forEach(b=>b.setAttribute('aria-pressed',String(b.dataset.category==='all')));
 setView('map');
 if(state.locationMarker)state.map.removeLayer(state.locationMarker);if(state.locationAccuracy)state.map.removeLayer(state.locationAccuracy);
 state.locationMarker=L.circleMarker(center,{radius:8,color:'#ffffff',weight:3,fillColor:'#408cff',fillOpacity:1}).addTo(state.map).bindTooltip('Your approximate location');
 state.locationAccuracy=L.circle(center,{radius:Math.max(accuracy,100),color:'#408cff',weight:1,fillOpacity:.08}).addTo(state.map);
 state.map.setView(center,accuracy>5000?10:13);$('mapShell').scrollIntoView({behavior:'smooth',block:'center'});
 await load();state.map.setView(center,accuracy>5000?10:13);
});
