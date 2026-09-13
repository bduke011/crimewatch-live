import test from 'node:test';
import assert from 'node:assert/strict';
import {defaultAlerts,normalizeAlerts} from './alert-settings.mjs';
test('notifications require opt-in and safe location preferences',()=>{
 assert.equal(defaultAlerts().enabled,false);
 const bad=normalizeAlerts({enabled:'true',radius:500,center:[91,-1],categories:['property','bad','property']});
 assert.equal(bad.enabled,false);assert.equal(bad.radius,5);assert.deepEqual(bad.center,[30.79,-94.90]);assert.deepEqual(bad.categories,['property']);
});
test('individual alert choices, valid radius and daily hour persist',()=>{
 const s=normalizeAlerts({enabled:true,nearby:false,daily:true,updates:false,radius:10,hour:8,center:[30.8,-94.9]});
 assert.equal(s.enabled,true);assert.equal(s.nearby,false);assert.equal(s.updates,false);assert.equal(s.daily,true);assert.equal(s.hour,8);assert.equal(s.radius,10);
});
