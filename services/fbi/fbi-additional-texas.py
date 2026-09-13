import pathlib,csv,zipfile,io,json,gzip,openpyxl,hashlib,collections,sqlite3
root=pathlib.Path('outputs/fbi-archive/additional');out=root/'texas';out.mkdir(exist_ok=True)
manifest=[]
for meta in json.load(open('work/fbi/downloads.json',encoding='utf8')):
 if meta['id']=='territories':continue
 p=root/pathlib.PurePosixPath(meta['awsFile']).name
 sources=[]
 if zipfile.is_zipfile(p):
  z=zipfile.ZipFile(p)
  if '[Content_Types].xml' in z.namelist():
   w=openpyxl.load_workbook(p.open('rb'),read_only=True,data_only=True)
   for sh in w:sources.append((sh.title,iter(sh.values)))
  else:
   for n in z.namelist():
    if n.lower().endswith('.csv'):sources.append((pathlib.PurePosixPath(n).stem,csv.reader(io.TextIOWrapper(z.open(n),encoding='utf-8-sig'))))
 else:sources=[(p.stem,csv.reader(p.open(encoding='utf-8-sig')))]
 for name,rows in sources:
  heads=[str(h).lower() for h in next(rows)];state=next(i for i,h in enumerate(heads) if h in ('state_abbr','state_name','abbr'))
  records=[]
  for r in rows:
   if len(r)!=len(heads):raise RuntimeError('Column count changed '+name)
   if str(r[state]).strip().upper() not in ('TX','TEXAS'):continue
   records.append(list(r))
  dest=out/(name+'.json.gz')
  with gzip.open(dest,'wt',encoding='utf8') as f:json.dump({'columns':heads,'rows':records},f,separators=(',',':'),default=str)
  years=[int(r[heads.index('year' if 'year' in heads else 'data_year')]) for r in records]
  m={'id':meta['id'],'title':meta['title'],'table':name,'rows':len(records),'firstYear':min(years) if years else None,'lastYear':max(years) if years else None,'file':dest.name,'bytes':dest.stat().st_size,'sourceFile':p.name,'sha256':hashlib.file_digest(p.open('rb'),'sha256').hexdigest()};manifest.append(m);print(m['id'],len(records),m['firstYear'],m['lastYear'],dest.stat().st_size,flush=True)
(out/'catalog.json').write_text(json.dumps(manifest,indent=2))
