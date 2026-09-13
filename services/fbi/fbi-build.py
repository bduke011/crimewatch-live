import pathlib,zipfile,csv,io,sqlite3,json,gzip,hashlib,concurrent.futures,datetime
root=pathlib.Path('outputs/fbi-archive');out=root/'search';out.mkdir(exist_ok=True)
directory={a['ori']:a for v in json.load(open('work/fbi/texas-agencies.json')).values() for a in v}
def schema(db):
 db.executescript('PRAGMA journal_mode=OFF;PRAGMA synchronous=OFF;CREATE TABLE agencies(id INTEGER PRIMARY KEY,ori TEXT,name TEXT,county TEXT);CREATE TABLE incidents(id INTEGER PRIMARY KEY,agency INTEGER,date TEXT,hour TEXT,report INTEGER);CREATE TABLE offenses(incident INTEGER,code TEXT,location TEXT,attempt TEXT);CREATE TABLE offense_types(code TEXT PRIMARY KEY,name TEXT);CREATE TABLE locations(code TEXT PRIMARY KEY,name TEXT);')
def finish(db,y,raw,extra):
 db.executescript('CREATE INDEX incident_agency_date ON incidents(agency,date DESC,id DESC);CREATE INDEX incident_date ON incidents(date DESC,id DESC);CREATE INDEX offense_incident ON offenses(incident);CREATE INDEX offense_code ON offenses(code,incident);')
 count=db.execute('select count(*) from incidents').fetchone()[0];n=db.execute('select count(*) from offenses').fetchone()[0]
 agencies=[dict(zip(['id','ori','name','county','incidents','latest'],r)) for r in db.execute('select a.*,count(i.id),max(i.date) from agencies a join incidents i on a.id=i.agency group by a.id order by a.name')]
 meta={'year':y,'incidents':count,'offenses':n,'agencies':agencies,'latest':db.execute('select max(date) from incidents').fetchone()[0],'collectedAt':datetime.datetime.now(datetime.timezone.utc).isoformat(),'rawBytes':raw.stat().st_size,'sha256':hashlib.file_digest(raw.open('rb'),'sha256').hexdigest(),**extra}
 db.commit();db.close();dest=out/f'{y}.sqlite'
 with dest.open('rb') as f,gzip.open(out/f'{y}.sqlite.gz','wb',compresslevel=6) as g:
  import shutil;shutil.copyfileobj(f,g)
 meta['databaseBytes']=dest.stat().st_size;(out/f'{y}.json').write_text(json.dumps(meta,separators=(',',':')),encoding='utf8');print(y,count,n,dest.stat().st_size,flush=True);return meta

def annual(y):
 dest=out/f'{y}.sqlite';raw=root/'raw'/f'TX-{y}.zip'
 if (out/f'{y}.json').exists():return json.loads((out/f'{y}.json').read_text())
 if dest.exists():dest.unlink()
 db=sqlite3.connect(dest);schema(db)
 with zipfile.ZipFile(raw) as z:
  names={pathlib.PurePosixPath(n).name.lower():n for n in z.namelist()}
  def rows(n):
   reader=csv.DictReader(io.TextIOWrapper(z.open(names[n]),encoding='utf-8-sig'));reader.fieldnames=[k.lower() for k in reader.fieldnames];return reader
  ars=list(rows('agencies.csv' if 'agencies.csv' in names else 'cde_agencies.csv'))
  for a in ars:
   ori=a['ori'];d=directory.get(ori,{})
   db.execute('insert or replace into agencies values(?,?,?,?)',(a['agency_id'],ori,a.get('pub_agency_name') or a.get('agency_name') or a.get('ucr_agency_name') or d.get('agency_name',ori),a.get('county_name') or d.get('counties','')))
  types=list(rows('nibrs_offense_type.csv'));type_map={r.get('offense_type_id',r['offense_code']):r['offense_code'] for r in types}
  db.executemany('insert or replace into offense_types values(?,?)',((r['offense_code'],r['offense_name']) for r in types))
  locs=list(rows('nibrs_location_type.csv'));lm={r['location_id']:r['location_code'] for r in locs};db.executemany('insert or replace into locations values(?,?)',((r['location_code'],r['location_name']) for r in locs))
  db.executemany('insert into incidents values(?,?,?,?,?)',((r['incident_id'],r['agency_id'],(r['incident_date'][:10] if r['incident_date'][4:5]=='-' else datetime.datetime.strptime(r['incident_date'],'%d-%b-%y').date().isoformat()),r.get('incident_hour',''),int(r.get('report_date_flag','').lower() in ('t','true','y','1'))) for r in rows('nibrs_incident.csv')))
  db.executemany('insert into offenses values(?,?,?,?)',((r['incident_id'],r.get('offense_code') or type_map[r['offense_type_id']],lm.get(r['location_id'],'00'),r.get('attempt_complete_flag','')) for r in rows('nibrs_offense.csv')))
  bad=db.execute('select count(*) from offenses o left join incidents i on o.incident=i.id where i.id is null').fetchone()[0]
  if bad:raise RuntimeError(f'{y}: {bad} orphan offenses')
  missing=db.execute('select count(*) from incidents i left join agencies a on i.agency=a.id where a.id is null').fetchone()[0]
  if missing:
   db.execute("INSERT INTO agencies SELECT DISTINCT i.agency,'Not supplied','FBI agency ' || i.agency || ' (name unavailable)','' FROM incidents i LEFT JOIN agencies a ON i.agency=a.id WHERE a.id IS NULL")
  # Reading every member verifies its CRC, including preserved non-public tables.
  failure=z.testzip()
  if failure:raise RuntimeError(f'CRC failure {failure}')
 return finish(db,y,raw,{'format':'Texas NIBRS annual CSV archive','rawTables':len(names)})

def current():
 y=2026;dest=out/f'{y}.sqlite';raw=root/'raw'/'TX-2026-master.txt.gz'
 if (out/f'{y}.json').exists():return json.loads((out/f'{y}.json').read_text())
 if dest.exists():dest.unlink()
 db=sqlite3.connect(dest);schema(db);aid={ori:i+1 for i,ori in enumerate(directory)}
 db.executemany('insert into agencies values(?,?,?,?)',((aid[o],o,d['agency_name'],d.get('counties','')) for o,d in directory.items()))
 with zipfile.ZipFile(root/'raw'/'TX-2025.zip') as z:
  for table,file,keys in [('offense_types','NIBRS_OFFENSE_TYPE.csv',['offense_code','offense_name']),('locations','NIBRS_LOCATION_TYPE.csv',['location_code','location_name'])]:
   db.executemany(f'insert or replace into {table} values(?,?)',(tuple(r[k] for k in keys) for r in csv.DictReader(io.TextIOWrapper(z.open(file),encoding='utf8'))))
 ids={};seq=0;segments={}
 with gzip.open(raw,'rt',encoding='ascii',errors='strict') as f:
  for line in f:
   seg=line[:2];segments[seg]=segments.get(seg,0)+1
   if seg not in ('01','02'):continue
   ori=line[4:13];key=ori+line[13:25]
   if ori not in aid:
    aid[ori]=len(aid)+1;db.execute('insert into agencies values(?,?,?,?)',(aid[ori],ori,ori,''))
   if seg=='01':
    seq+=1;ids[key]=seq;d=line[25:33];date=f'{d[:4]}-{d[4:6]}-{d[6:8]}'
    datetime.date.fromisoformat(date)
    db.execute('insert into incidents values(?,?,?,?,?)',(seq,aid[ori],date,line[34:36].strip(),int(line[33]=='R')))
   else:
    if key not in ids:raise RuntimeError('Offense without prior incident')
    db.execute('insert into offenses values(?,?,?,?)',(ids[key],line[33:36],line[40:42],line[36]))
 return finish(db,y,raw,{'format':'Texas subset of 2026 national NIBRS master','segments':segments,'provisional':True})
if __name__=='__main__':
 with concurrent.futures.ProcessPoolExecutor(max_workers=3) as ex:results=list(ex.map(annual,range(2025,1996,-1)))
 results.insert(0,current());(out/'catalog.json').write_text(json.dumps({'years':[{k:v for k,v in r.items() if k!='agencies'} for r in results]},separators=(',',':')),encoding='utf8')

