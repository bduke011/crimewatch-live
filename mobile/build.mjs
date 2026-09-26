import {mkdir,readFile,writeFile,readdir,cp,rm} from 'node:fs/promises';
import {resolve,dirname} from 'node:path';
import {fileURLToPath} from 'node:url';
import {build} from 'esbuild';
// Only this generated directory is replaced; source and private data are untouched.
const root=resolve(dirname(fileURLToPath(import.meta.url)),'..');
const output=resolve(root,'dist-mobile');
if(process.cwd()!==root || dirname(output)!==root)throw Error('Build must run in the project root');
await rm(output,{recursive:true,force:true});await mkdir('dist-mobile',{recursive:true});
for(const file of await readdir('community-ui')){
 if(!/\.(html|css|js|svg)$/.test(file))continue;
 let s=await readFile('community-ui/'+file,'utf8');
 if(file.endsWith('.html')){s=s.replaceAll('account.php','account.html').replace('width=device-width,initial-scale=1','width=device-width,initial-scale=1,viewport-fit=cover').replace('</head>','<link rel="stylesheet" href="member-mobile.css"><script src="mobile.js"></script></head>');s=s.replace(/<a[^>]*href="admin.php"[^>]*>.*?<\/a>/g,'');}
 if(file.endsWith('.js')){s=s.replaceAll('account.php','account.html').replaceAll('src="jail-photo.php?', 'data-native-image="jail-photo.php?').replaceAll('src="ads-image.php?', 'data-native-image="ads-image.php?');if(file==='app.js')s=s.replace('navigator.geolocation.getCurrentPosition(', 'window.CWGetCurrentPosition(');}
 if(file.endsWith('.css'))s=s.replace(/@import\s+url\([^)]*fonts.googleapis[^)]*\);?/g,'');
 await writeFile('dist-mobile/'+file,s);
}
let account=(await readFile('community-ui/account.php','utf8')).replace(/<\?php[\s\S]*?\?>/,'').replace('width=device-width,initial-scale=1','width=device-width,initial-scale=1,viewport-fit=cover').replace(/<script[^>]*src="account.js[^"]*"[^>]*><\/script>/,'<script src="mobile.js"></script><script defer src="member-account.js"></script><link rel="stylesheet" href="member-mobile.css">').replaceAll('account.php','account.html').replace('secure sign-in link','sign-in code').replace('Email my sign-in link','Email my sign-in code');
await writeFile('dist-mobile/account.html',account);
await mkdir('dist-mobile/vendor',{recursive:true});
await cp('node_modules/leaflet/dist','dist-mobile/vendor',{recursive:true});
await cp('node_modules/leaflet.markercluster/dist','dist-mobile/vendor',{recursive:true});
await cp('node_modules/leaflet/LICENSE','dist-mobile/vendor/LEAFLET-LICENSE');
await cp('node_modules/leaflet.markercluster/MIT-LICENCE.txt','dist-mobile/vendor/CLUSTER-LICENSE');
await cp('mobile/member-mobile.css','dist-mobile/member-mobile.css');
await cp('mobile/member-account.js','dist-mobile/member-account.js');
await build({entryPoints:['mobile/member-bridge.js'],bundle:true,format:'iife',outfile:'dist-mobile/mobile.js',target:'safari15',minify:true});
console.log('Bundled CrimeWatch 1.2: required accounts, private profiles, direct ads, map, daily reports, opt-in native notifications.');
