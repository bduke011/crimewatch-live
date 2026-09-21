"""Private jail media importer. Raw PDFs and provenance stay outside the public site."""
import os,re,json,hashlib,sqlite3,datetime,urllib.request,urllib.parse,html,sys,subprocess,shutil
from pathlib import Path
ROOT=Path(os.environ.get('CW_JAIL_DIR',str(Path(__file__).parent/'data')))
INDEX='https://polkcountytoday.com/daily-arrest-report/'
def fetch(url):
    req=urllib.request.Request(url,headers={'User-Agent':'CrimeWatch public booking archive/1.0'})
    with urllib.request.urlopen(req,timeout=45) as r:
        data=r.read(12_000_001)
        if len(data)>12_000_000:raise ValueError('Source exceeds size limit')
        return data

def iso(s):return datetime.datetime.strptime(s,'%m/%d/%Y').date().isoformat()
def parse(text):
    if 'POLK COUNTY JAIL' not in text or 'Booked During Period' not in text:raise ValueError('Unexpected report format')
    records=[];current=None;arrest=None;charge=None;bounds=None;mode=None;expected=0
    def finish():
        if current and current.get('name'):
            if not current.get('booked') or not current['arrests']:raise ValueError('Incomplete booking '+current['name'])
            records.append(current)
    for raw in text.splitlines():
        line=raw.strip()
        if not line or line.startswith(('POLK COUNTY JAIL','Booked During Period','From:','Jail Report ')):continue
        if 'Name and Address' in line and 'Booked On' in line:
            mode='person';continue
        m=re.match(r'^(.+?)\s{2,}(\d{1,3})\s+(\d{2}/\d{2}/\d{4})(?:\s+(\d{2}/\d{2}/\d{4}))?\s*$',line)
        if m and mode=='person':
            if current and current['name']==m[1].strip() and current['booked']==iso(m[3]):
                mode='locality';continue
            finish();expected+=1;current={'name':m[1].strip(),'age':int(m[2]),'booked':iso(m[3]),'released':iso(m[4]) if m[4] else None,'locality':'','arrests':[]};arrest=None;charge=None;mode='locality';continue
        if line.startswith('Arresting Agency:'):
            if not current:raise ValueError('Arrest without person')
            parts=re.split(r'Arrest Date/Time:',line)
            arrest={'agency':parts[0].replace('Arresting Agency:','').strip(),'dateTime':parts[1].strip() if len(parts)>1 else '', 'charges':[]}
            current['arrests'].append(arrest);mode='agency';charge=None;continue
        if line.startswith('Charge Description'):
            if not arrest:raise ValueError('Charges without arrest')
            bounds=[max(0,raw.index(x)-2) for x in ['Jurisdiction','Type Warrant','Warrant Number']];mode='charges';continue
        if mode=='locality' and current:
            if line not in current['locality']:current['locality']=(current['locality']+' '+line).strip()
            continue
        if mode=='agency' and arrest:
            arrest['agency']+=' '+line;continue
        if mode=='charges' and arrest and bounds:
            a,b,c=bounds;cols=[raw[:a].strip(),raw[a:b].strip(),raw[b:c].strip(),raw[c:].strip()]
            if not any(cols):continue
            if cols[0] and (cols[1] or cols[2] or cols[3]) or charge is None:
                charge=dict(zip(['description','jurisdiction','warrantType','warrantNumber'],cols));arrest['charges'].append(charge)
            else:
                for k,v in zip(['description','jurisdiction','warrantType','warrantNumber'],cols):
                    if v:charge[k]=(charge[k]+' '+v).strip()
    finish()
    if not records:raise ValueError('No booking records parsed; review required')
    merged={}
    for r in records:
        combined={}
        for a in r['arrests']:
            k=(a['agency'],a['dateTime'])
            if k not in combined:combined[k]={'agency':a['agency'],'dateTime':a['dateTime'],'charges':[]}
            for c in a['charges']:
                if c not in combined[k]['charges']:combined[k]['charges'].append(c)
        r['arrests']=list(combined.values())
        if not any(a['charges'] for a in r['arrests']):raise ValueError('Missing charges; review required')
        key='|'.join([r['name'],str(r['age']),r['booked'],r['arrests'][0]['dateTime']]);r['id']=hashlib.sha256(key.encode()).hexdigest()[:24]
        if r['id'] in merged:
            old=merged[r['id']]
            for a in r['arrests']:
                if a not in old['arrests']:old['arrests'].append(a)
        else:merged[r['id']]=r
    return list(merged.values())

def extract(path):
    from pypdf import PdfReader
    reader=PdfReader(str(path))
    if len(reader.pages)>100:raise ValueError('Too many pages')
    return '\n'.join(p.extract_text(extraction_mode='layout') for p in reader.pages)

def run():
    ROOT.mkdir(parents=True,exist_ok=True);(ROOT/'pdf').mkdir(exist_ok=True)
    db=sqlite3.connect(str(ROOT/'jail.sqlite'));db.execute('PRAGMA busy_timeout=10000');db.execute('PRAGMA journal_mode=WAL')
    db.executescript('''CREATE TABLE IF NOT EXISTS bookings(id TEXT PRIMARY KEY,name TEXT,age INTEGER,booked TEXT,released TEXT,locality TEXT,details TEXT,search_text TEXT,first_seen TEXT,last_seen TEXT,report_date TEXT);CREATE INDEX IF NOT EXISTS booking_date ON bookings(booked DESC);CREATE TABLE IF NOT EXISTS sources(url TEXT PRIMARY KEY,hash TEXT,checked TEXT,count INTEGER);CREATE TABLE IF NOT EXISTS versions(id TEXT,hash TEXT,seen TEXT,payload TEXT,PRIMARY KEY(id,hash));''')
    page=fetch(INDEX).decode('utf8',errors='replace');urls=list(dict.fromkeys(html.unescape(x) for x in re.findall(r'href=["\']([^"\']+\.pdf)["\']',page,re.I)))
    urls=[u for u in urls if urllib.parse.urlparse(u).hostname in ['polkcountytoday.com','www.polkcountytoday.com'] and re.search(r'MEDIA.REPORT',u,re.I)]
    if not urls:raise ValueError('No media links found')
    prior=json.loads((ROOT/'status.json').read_text()) if (ROOT/'status.json').exists() else {}
    errors=[];processed=0
    for url in reversed(urls):
        try:
            known=db.execute('SELECT hash FROM sources WHERE url=?',(url,)).fetchone()
            raw=fetch(url)
            if not raw.startswith(b'%PDF'):raise ValueError('Invalid PDF response')
            digest=hashlib.sha256(raw).hexdigest();path=ROOT/'pdf'/(digest+'.pdf');path.write_bytes(raw);now=datetime.datetime.now(datetime.timezone.utc).isoformat()
            if known and known[0]==digest and prior.get('parserVersion')==2:continue
            text=extract(path);date_match=re.search(r'through\s+(\d{1,2}/\d{1,2}/\d{4})',text)
            if not date_match:raise ValueError('Missing report period')
            report_date=iso(date_match[1]);rows=parse(text)
            with db:
                for r in rows:
                    payload=json.dumps(r,ensure_ascii=False);rh=hashlib.sha256(payload.encode()).hexdigest();search=r['name']+' '+r['locality']+' '+json.dumps(r['arrests'],ensure_ascii=False)
                    db.execute('''INSERT INTO bookings(id,name,age,booked,released,locality,details,search_text,first_seen,last_seen,report_date) VALUES(?,?,?,?,?,?,?,?,?,?,?) ON CONFLICT(id) DO UPDATE SET released=COALESCE(excluded.released,bookings.released),details=excluded.details,search_text=excluded.search_text,last_seen=excluded.last_seen,report_date=excluded.report_date WHERE excluded.report_date>=bookings.report_date''',(r['id'],r['name'],r['age'],r['booked'],r['released'],r['locality'],json.dumps(r['arrests']),search,now,now,report_date))
                    db.execute('INSERT OR IGNORE INTO versions VALUES(?,?,?,?)',(r['id'],rh,now,payload))
                db.execute('INSERT OR REPLACE INTO sources VALUES(?,?,?,?)',(url,digest,now,len(rows)))
            processed+=1;print(json.dumps({'date':report_date,'records':len(rows)}),flush=True)
        except Exception as e:errors.append({'url':url,'error':str(e)});print(str(e),file=sys.stderr,flush=True)
    # Attach already collected roster photos immediately, without waiting for
    # the next roster collection after new PDF bookings are imported.
    if db.execute("SELECT count(*) FROM sqlite_master WHERE type='table' AND name='roster'").fetchone()[0]:
        from roster import link_bookings
        with db:link_bookings(db)
    status={'parserVersion':2,'checkedAt':datetime.datetime.now(datetime.timezone.utc).isoformat(),'processed':processed,'linkedReports':len(urls),'failedReports':len(errors),'errors':errors,'total':db.execute('SELECT count(*) FROM bookings').fetchone()[0]}
    (ROOT/'status.json').write_text(json.dumps(status,indent=2));db.execute('PRAGMA wal_checkpoint(TRUNCATE)');db.close();print(json.dumps({k:v for k,v in status.items() if k!='errors'}))
    if errors:sys.exit(1)
if __name__=='__main__':run()
