import json,pathlib,urllib.request,urllib.parse,concurrent.futures,zipfile,hashlib,time
root=pathlib.Path('outputs/fbi-archive/supplemental');root.mkdir(exist_ok=True)
masters=json.load(open('work/fbi/masters.json',encoding='utf8'))
keys=[]
for m in masters:
 if m['id']=='nibrs':continue
 keys.append((m['id'],'help',f"master_files/{m['id']}/{m['id']}-help.zip"))
 for y in range(m['minYear'],m['maxYear']+1):keys.append((m['id'],y,f"master_files/{m['id']}/{m['id']}-{y}.zip"))
 if m['id']!='pe':keys.append((m['id'],2026,f"master_files/{m['id']}/{m['id']}-2026.zip"))
def fetch(item):
 kind,y,k=item;p=root/f'{kind}-{y}.zip'
 for attempt in range(3):
  try:
   if not p.exists():
    u=json.load(urllib.request.urlopen('https://cde.ucr.cjis.gov/LATEST/s3/signedurl?'+urllib.parse.urlencode({'key':k}),timeout=60))[k]
    temp=p.with_suffix('.part')
    with urllib.request.urlopen(u,timeout=120) as r,temp.open('wb') as f:
     import shutil;shutil.copyfileobj(r,f)
    temp.replace(p)
   with zipfile.ZipFile(p) as z:
    if z.testzip():raise ValueError('Invalid CRC')
   return {'dataset':kind,'year':y,'file':p.name,'bytes':p.stat().st_size,'sha256':hashlib.file_digest(p.open('rb'),'sha256').hexdigest(),'status':'saved','scope':'National master archive containing Texas submissions; not yet normalized for public search'}
  except Exception as e:
   if attempt==2:return {'dataset':kind,'year':y,'status':'unavailable','error':type(e).__name__}
   time.sleep(2)
results=[]
with concurrent.futures.ThreadPoolExecutor(max_workers=4) as ex:
 for r in ex.map(fetch,keys):
  results.append(r);(root/'manifest.json').write_text(json.dumps(results,indent=2))
  if len(results)%20==0:print(len(results),'/',len(keys),flush=True)
print('done',len(results),sum(r.get('bytes',0) for r in results),flush=True)
