import pathlib,json,urllib.request,urllib.parse,concurrent.futures,time,zipfile,shutil
root=pathlib.Path('outputs/fbi-archive/raw');root.mkdir(parents=True,exist_ok=True)
shutil.copyfile('work/fbi/texas-2026-master.txt.gz',root/'TX-2026-master.txt.gz')
def geturl(k):return json.load(urllib.request.urlopen('https://cde.ucr.cjis.gov/LATEST/s3/signedurl?'+urllib.parse.urlencode({'key':k}),timeout=40))[k]
def fetch(y):
 dest=root/f'TX-{y}.zip'
 if dest.exists() and zipfile.is_zipfile(dest):return {'year':y,'status':'saved','bytes':dest.stat().st_size}
 try:
  u=geturl(f'nibrs/incident/{y}/TX-{y}.zip')
  with urllib.request.urlopen(urllib.request.Request(u,headers={'Range':'bytes=0-0'}),timeout=60) as r:
   total=int(r.headers.get('Content-Range','').split('/')[-1]) if r.status==206 else int(r.headers['Content-Length'])
  partsize=8*1024*1024
  def part(i):
   start=i*partsize;end=min(total,start+partsize)-1;f=root/f'TX-{y}-{i}.part'
   if f.exists() and f.stat().st_size==end-start+1:return f
   for attempt in range(3):
    try:
     with urllib.request.urlopen(urllib.request.Request(u,headers={'Range':f'bytes={start}-{end}'}),timeout=90) as r,f.open('wb') as out:
      if r.status!=206:raise RuntimeError('Range not supported')
      shutil.copyfileobj(r,out,1024*1024)
     if f.stat().st_size!=end-start+1:raise RuntimeError('Short response')
     return f
    except Exception:
     if attempt==2:raise
   return f
  with concurrent.futures.ThreadPoolExecutor(max_workers=4) as ex:parts=list(ex.map(part,range((total+partsize-1)//partsize)))
  with dest.open('wb') as out:
   for f in parts:
    with f.open('rb') as inp:shutil.copyfileobj(inp,out)
  if not zipfile.is_zipfile(dest):raise RuntimeError('Invalid ZIP')
  for f in parts:f.unlink()
  result={'year':y,'status':'saved','bytes':total};print(result,flush=True);return result
 except Exception as e:
  result={'year':y,'status':'failed','error':type(e).__name__};print(result,flush=True);return result
results=[]
with concurrent.futures.ThreadPoolExecutor(max_workers=3) as ex:
 for r in ex.map(fetch,range(2025,1996,-1)):
  results.append(r);pathlib.Path('outputs/fbi-archive/download-status.json').write_text(json.dumps(results,indent=2))
