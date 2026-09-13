import {mkdir,readFile,writeFile,readdir,cp} from 'node:fs/promises';
import {build} from 'esbuild';
import sharp from 'sharp';
await mkdir('dist-mobile',{recursive:true});
for(const file of await readdir('public')) {
 if(!/\.(html|css|js|svg)$/.test(file))continue;
 let content=await readFile('public/'+file,'utf8');
 if(file.endsWith('.html')) {
  content=content.replace('width=device-width,initial-scale=1','width=device-width,initial-scale=1,viewport-fit=cover');
  content=content.replace(/https:\/\/unpkg.com\/leaflet@1.9.4\/dist\//g,'vendor/leaflet/').replace(/https:\/\/unpkg.com\/leaflet.markercluster@1.5.3\/dist\//g,'vendor/cluster/');
  content=content.replace(/\s+integrity="[^"]*"/g,'');
  content=content.replace('</head>','<link rel="stylesheet" href="mobile.css"><script src="mobile.js"></script></head>');
  if(file==='index.html')content=content.replace(/<h3>Privacy<\/h3><p>[\s\S]*?<\/p>/,'<h3>Privacy</h3><p>No account is needed. Searches are sent to CrimeWatch’s server to search public records. Saved reports and your preferred area stay on this device. Map tiles load from OpenStreetMap; map software and the interface are bundled with the app. There are no advertising or analytics trackers. The web host may retain normal access logs. <a href="privacy.html">Read the app privacy information</a>.</p>');
 }
 if(file==='app.js') content=content.replace("state.selectedRecord=r;","state.selectedRecord=r;document.dispatchEvent(new CustomEvent('crimewatch:detail',{detail:r}));");
 if(file.endsWith('.css'))content=content.replace(/@import\s+url\([^)]*fonts.googleapis[^)]*\);?/g,'');
 await writeFile('dist-mobile/'+file,content);
}
for(const file of ['saved.html','privacy.html','mobile.css'])await cp('mobile/'+file,'dist-mobile/'+file);
await mkdir('dist-mobile/vendor',{recursive:true});
await cp('node_modules/leaflet/dist','dist-mobile/vendor/leaflet',{recursive:true});
await cp('node_modules/leaflet.markercluster/dist','dist-mobile/vendor/cluster',{recursive:true});
await cp('node_modules/leaflet/LICENSE','dist-mobile/vendor/leaflet/LICENSE');
await cp('node_modules/leaflet.markercluster/MIT-LICENCE.txt','dist-mobile/vendor/cluster/MIT-LICENCE.txt');
await build({entryPoints:['mobile/bridge.js'],bundle:true,format:'iife',outfile:'dist-mobile/mobile.js',target:'safari15',minify:true});
await mkdir('mobile/assets',{recursive:true});
const svg='<svg xmlns="http://www.w3.org/2000/svg" width="1024" height="1024" viewBox="0 0 1024 1024"><rect width="1024" height="1024" fill="#080e18"/><circle cx="512" cy="512" r="352" fill="#102536"/><path d="M735 288a316 316 0 1 0 0 448M512 334v178l196-112" fill="none" stroke="#6ce5ed" stroke-width="65" stroke-linecap="round"/><circle cx="512" cy="512" r="48" fill="#6ce5ed"/></svg>';
await sharp(Buffer.from(svg)).png().toFile('mobile/assets/AppIcon.png');
const splashIcon=await sharp(Buffer.from(svg)).resize(420,420).png().toBuffer();
const splash=await sharp({create:{width:2732,height:2732,channels:3,background:'#080e18'}}).composite([{input:splashIcon,gravity:'centre'}]).png().toBuffer();
for(const name of ['splash-2732x2732.png','splash-2732x2732-1.png','splash-2732x2732-2.png'])await writeFile('ios/App/App/Assets.xcassets/Splash.imageset/'+name,splash);
await cp('mobile/assets/AppIcon.png','ios/App/App/Assets.xcassets/AppIcon.appiconset/AppIcon-512@2x.png');
console.log('Bundled CrimeWatch: local UI, local map libraries, HTTPS data transport, saved reports.');
