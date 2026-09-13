import test from 'node:test';
import assert from 'node:assert/strict';
import {apiURL,normalizeSaved,toggleSaved,shareText} from './data.mjs';
test('routes bundled PHP requests to production and preserves encoded filters',()=>{
 assert.equal(apiURL('api.php?q=A%26B&agency=polk','capacitor://localhost/index.html'),'https://crimewatch.live/api.php?q=A%26B&agency=polk');
 assert.equal(apiURL('jail-api.php?page=2'),'https://crimewatch.live/jail-api.php?page=2');
 assert.equal(apiURL('fbi-api.php?year=2024'),'https://crimewatch.live/fbi-api.php?year=2024');
 assert.equal(apiURL('https://example.com/api.php'),'https://example.com/api.php');
 assert.equal(apiURL('vendor/leaflet/leaflet.css','capacitor://localhost/index.html'),'capacitor://localhost/vendor/leaflet/leaflet.css');
});
test('saved reports toggle without duplicate IDs and retain a bounded offline archive',()=>{
 const r={id:'a',offense:'Reported incident',date:'2026-09-12'};
 const saved=toggleSaved([],r);assert.equal(saved.length,1);assert.ok(saved[0].savedAt);
 assert.deepEqual(toggleSaved(saved,r),[]);
 const rows=Array.from({length:100},(_,i)=>({...r,id:String(i)}));
 assert.equal(toggleSaved(rows,r).length,100);assert.equal(toggleSaved(rows,r)[0].id,'a');
 assert.deepEqual(normalizeSaved([null,{},r]),[r]);assert.deepEqual(normalizeSaved({}),[]);
});
test('shared reports retain source and qualification',()=>{const text=shareText({offense:'Report',date:'2026-09-12'});assert.match(text,/not a finding of guilt/);assert.match(text,/https:\/\/crimewatch.live/);});
