import test from 'node:test';
import assert from 'node:assert/strict';
import {readFileSync} from 'node:fs';
import vm from 'node:vm';
test('saving account preferences continues to reports even after notification permission fails', async()=>{
 const elements={}; const element=id=>elements[id]??=( {hidden:false,textContent:'',elements:{name:{value:''},area:{value:''}},append(){},insertBefore(){},after(){},scrollIntoView(){},querySelector(){return save;}} );
 const save={disabled:false,after(){}};let route='',saved;
 for(const id of ['signIn','profile','verify','accountEmail','profileForm','signInForm','signOut','deleteForm','enablePush','disablePush','pushPreference','message'])element(id);
 const reader={name:'',email:'test@example.com',area:'polk'};
 const context={document:{getElementById:element,createElement:()=>({hidden:false,innerHTML:'',after(){}})},window:{CWNotificationStatus:async()=>false,CWNotifications:async()=>{throw Error('Permission denied');}},localStorage:{setItem(){}},location:{replace:value=>route=value},FormData:class{*[Symbol.iterator](){yield ['name','Reader'];yield ['area','houston'];}},fetch:async(url,options)=>{if(options){saved=JSON.parse(options.body);return {ok:true,json:async()=>({reader:{...reader,...saved}})};}return {ok:true,json:async()=>({reader})};},setTimeout};
 vm.runInNewContext(readFileSync('mobile/member-account.js','utf8'),context);
 await new Promise(resolve=>setTimeout(resolve,0));
 await element('enablePush').onclick();assert.equal(element('pushPreference').textContent,'Permission denied');assert.equal(save.disabled,false);
 await element('profileForm').onsubmit({preventDefault(){},target:element('profileForm')});
 assert.deepEqual(saved,{name:'Reader',area:'houston'});assert.equal(route,'index.html');assert.equal(save.disabled,false);
});
