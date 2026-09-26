const $=id=>document.getElementById(id);
const native=!!window.CW_NATIVE,home=native?'index.html':'./';
let csrf='',reader=null,hasPassword=false,challenge='',setup=false;
const verifyToken=new URLSearchParams(location.hash.slice(1)).get('verify')||'';
if(location.hash)history.replaceState(null,'',location.pathname);
function message(text,error=false){$('message').hidden=!text;$('message').textContent=text;$('message').classList.toggle('error',error);if(text)$('message').scrollIntoView({block:'start',behavior:'smooth'});}
async function request(endpoint,action,body){
 const options={cache:'no-store'};
 if(body!==undefined){options.method='POST';options.headers={'Content-Type':'application/json','X-CSRF-Token':csrf};options.body=JSON.stringify(endpoint==='reader-auth-api.php'?{...body,native}:body);}
 const r=await fetch(endpoint+'?action='+action,options);const d=await r.json();if(!r.ok)throw Error(d.error||'Please try again.');if(d.csrf)csrf=d.csrf;return d;
}
const auth=(action,body)=>request('reader-auth-api.php',action,body);
const profile=(action,body)=>request(native?'member-api.php':'account-api.php',action,body);
function render(){
 $('signIn').hidden=!!reader||setup||!!verifyToken;$('passwordSetup').hidden=!setup;$('profile').hidden=!reader||setup;$('verify').hidden=native||!!reader||setup||!verifyToken;
 if(reader){$('accountEmail').textContent=reader.email;$('profileForm').elements.name.value=reader.name;$('profileForm').elements.area.value=reader.area;localStorage.setItem('cw-area',reader.area);$('passwordNotice').hidden=hasPassword;}
}
async function accept(d,continueHome){if(native&&d.token)await window.CWStoreSession(d.token);reader=d.reader;hasPassword=!!d.hasPassword;setup=false;render();if(continueHome)location.replace(home);else message('Your email and password are saved. Choose your community, then tap Save and view reports.');}
function openSetup(title){setup=true;challenge='';$('passwordTitle').textContent=title;$('passwordForm').hidden=false;$('passwordCodeForm').hidden=true;$('passwordForm').reset();$('passwordForm').elements.email.value=reader?.email||$('signInForm').elements.email.value;message('');render();}
$('showRegister').onclick=()=>openSetup('Create your reader account');$('showReset').onclick=()=>openSetup('Set or reset your password');$('profilePassword').onclick=()=>openSetup('Set your password');$('backToSignIn').onclick=()=>{setup=false;message('');render();};
$('signInForm').onsubmit=async ev=>{ev.preventDefault();const b=ev.target.querySelector('button');b.disabled=true;try{await accept(await auth('login',{email:ev.target.elements.email.value,password:ev.target.elements.password.value}),true);ev.target.elements.password.value='';}catch(e){message(e.message,true);}finally{b.disabled=false;}};
$('passwordForm').onsubmit=async ev=>{ev.preventDefault();const f=ev.target,b=f.querySelector('button');b.disabled=true;try{if(f.elements.password.value!==f.elements.confirmPassword.value)throw Error('The passwords do not match.');const d=await auth('request-password',{email:f.elements.email.value,password:f.elements.password.value,agree:f.elements.agree.checked});challenge=d.challenge;f.elements.password.value='';f.elements.confirmPassword.value='';f.hidden=true;$('passwordCodeForm').hidden=false;message('Check your inbox for an 8-digit code. It expires in 10 minutes. Check spam if needed.');}catch(e){message(e.message,true);}finally{b.disabled=false;}};
$('passwordCodeForm').onsubmit=async ev=>{ev.preventDefault();const b=ev.target.querySelector('button');b.disabled=true;try{await accept(await auth('confirm-password',{challenge,code:ev.target.elements.code.value}),false);ev.target.reset();}catch(e){message(e.message,true);}finally{b.disabled=false;}};
$('verifyButton').onclick=async()=>{try{const d=await request('account-api.php','verify',{token:verifyToken});reader=d.reader;render();message('Signed in. Set a password for future sign-ins.');}catch(e){message(e.message,true);}};
$('profileForm').onsubmit=async ev=>{ev.preventDefault();const b=ev.target.querySelector('button[type=submit]');b.disabled=true;try{reader=(await profile('save',Object.fromEntries(new FormData(ev.target)))).reader;render();location.replace(home);}catch(e){message(e.message,true);}finally{b.disabled=false;}};
$('signOut').onclick=async()=>{try{if(native)await window.CWSignOut();else await auth('logout',{});location.href=native?'account.html':'account.php';}catch(e){message(e.message,true);}};
$('deleteForm').onsubmit=async ev=>{ev.preventDefault();try{await profile('delete',{confirm:ev.target.elements.confirm.value});if(native)await window.CWClearSession();location.href=native?'account.html':'account.php';}catch(e){message(e.message,true);}};
const saveButton=$('profileForm').querySelector('button');saveButton.type='submit';
if(native){
 const notifications=document.createElement('section');notifications.innerHTML='<h2>App notifications</h2><p>Optional announcements for your saved community.</p><button type="button" id="enablePush">Enable notifications</button><button type="button" id="disablePush">Turn off notifications</button><p id="pushPreference" role="status"></p>';$('profileForm').insertBefore(notifications,saveButton);
 for(const [id,enabled]of [['enablePush',true],['disablePush',false]])$(id).onclick=async()=>{$(id).disabled=true;try{await window.CWNotifications(enabled);$('pushPreference').textContent=enabled?'Notifications are on.':'Notifications are off.';}catch(e){$('pushPreference').textContent=e.message;}finally{$(id).disabled=false;}};
}
(async()=>{try{const d=await auth('session');reader=d.reader;hasPassword=d.hasPassword;csrf=d.csrf||'';render();if(native)$('pushPreference').textContent=await window.CWNotificationStatus()?'Notifications are on.':'Notifications are off.';}catch(e){message(e.message,true);}})();
