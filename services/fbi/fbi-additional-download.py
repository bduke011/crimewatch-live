import pathlib,json,urllib.request,urllib.parse,concurrent.futures,csv,io,zipfile,gzip,hashlib
root=pathlib.Path('outputs/fbi-archive/additional');root.mkdir(exist_ok=True)
def fetch(d):
 k=d['awsFile'];p=root/pathlib.PurePosixPath(k).name
 if not p.exists():
  u=json.load(urllib.request.urlopen('https://cde.ucr.cjis.gov/LATEST/s3/signedurl?'+urllib.parse.urlencode({'key':k}),timeout=60))[k]
  with urllib.request.urlopen(u,timeout=120) as r,p.open('wb') as f:
   import shutil;shutil.copyfileobj(r,f)
 print(d['id'],p.stat().st_size,flush=True)
 return {'id':d['id'],'title':d['title'],'file':p.name,'bytes':p.stat().st_size,'years':d['year_range'],'sha256':hashlib.file_digest(p.open('rb'),'sha256').hexdigest()}
d=[d for d in json.load(open('work/fbi/downloads.json',encoding='utf8')) if d['id']!='territories']
with concurrent.futures.ThreadPoolExecutor(max_workers=3) as ex:r=list(ex.map(fetch,d))
(root/'manifest.json').write_text(json.dumps(r,indent=2))
for p in root.iterdir():
 if p.suffix=='.csv':
  with p.open(encoding='utf-8-sig') as f:print(p.name,next(csv.reader(f)),flush=True)
 elif p.suffix=='.zip':
  with zipfile.ZipFile(p) as z:
   for n in z.namelist():
    if n.lower().endswith('.csv'):
     print(p.name,n,next(csv.reader(io.TextIOWrapper(z.open(n),encoding='utf-8-sig'))),flush=True)
