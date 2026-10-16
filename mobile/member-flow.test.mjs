import test from 'node:test';
import assert from 'node:assert/strict';
import {readFileSync} from 'node:fs';
import vm from 'node:vm';
test('saving account preferences continues to reports even after notification permission fails', async()=>{
 const elements={}; const element=id=>elements[id]??=( {hidden:false,textContent:'',classList:{toggle(){}},elements:{name:{value:''},area:{value:'',replaceChildren(){}}},append(){},insertBefore(){},after(){},scrollIntoView(){},querySelector(){return save;}} );
 const save={disabled:false,after(){}};let route='',saved;
 for(const id of ['signIn','profile','verify','accountEmail','profileForm','signInForm','signOut','deleteForm','enablePush','disablePush','pushPreference','message'])element(id);
 const reader={name:'',email:'test@example.com',area:'polk'};
 const context={Option:class{constructor(label,value){this.value=value;}},URLSearchParams,document:{getElementById:element,createElement:()=>({hidden:false,innerHTML:'',after(){}})},window:{CW_NATIVE:true,CWNotificationStatus:async()=>false,CWNotifications:async()=>{throw Error('Permission denied');}},localStorage:{setItem(){}},location:{hash:'',replace:value=>route=value},FormData:class{*[Symbol.iterator](){yield ['name','Reader'];yield ['area','houston'];}},fetch:async(url,options)=>{if(url.includes('area-api.php'))return {ok:true,json:async()=>url.includes('catalog')?{counties:[{id:'polk',label:'Polk County',active:true},{id:'houston',label:'Houston',active:true}]}:{area:'houston',csrf:'test'}};if(options){saved=JSON.parse(options.body);return {ok:true,json:async()=>({reader:{...reader,...saved}})};}return {ok:true,json:async()=>({reader})};},setTimeout};
 vm.runInNewContext(readFileSync('mobile/member-account.js','utf8'),context);
 await new Promise(resolve=>setTimeout(resolve,0));
 await element('enablePush').onclick();assert.equal(element('pushPreference').textContent,'Permission denied');assert.equal(save.disabled,false);
 await element('profileForm').onsubmit({preventDefault(){},target:element('profileForm')});
 assert.deepEqual(saved,{name:'Reader',area:'houston'});assert.equal(route,'index.html');assert.equal(save.disabled,false);
});
