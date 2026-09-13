import {createServer} from 'node:http';
import {readFile} from 'node:fs/promises';
import {resolve,extname} from 'node:path';
const root=resolve('dist-mobile');
createServer(async(req,res)=>{try{
 const url=new URL(req.url,'http://localhost');
 if(/^\/(api|jail-api|fbi-api|fbi-extra-api)\.php$/.test(url.pathname)){
  const response=await fetch('https://crimewatch.live'+url.pathname+url.search);res.writeHead(response.status,{'Content-Type':'application/json'});res.end(await response.text());return;
 }
 const path=resolve(root,'.'+(url.pathname==='/'?'/index.html':decodeURIComponent(url.pathname)));
 if(!path.startsWith(root+'/')&&!path.startsWith(root+'\\')){res.writeHead(403).end();return;}
 let content=await readFile(path);if(path.endsWith('.html'))content=Buffer.from(content.toString().replace('<head>','<head><script>window.CRIMEWATCH_PREVIEW=true;</script>'));
 res.writeHead(200,{'Content-Type':({'.html':'text/html','.js':'text/javascript','.css':'text/css','.svg':'image/svg+xml','.png':'image/png'})[extname(path)]||'application/octet-stream'});res.end(content);
 }catch{res.writeHead(404).end('Not found');}}).listen(4173,'127.0.0.1',()=>console.log('CrimeWatch preview http://127.0.0.1:4173'));
