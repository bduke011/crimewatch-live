"""Import owner-supplied court lookup snapshots; never contacts court portals.

Raw exports, review notes and descriptors remain in private server storage.
The public representation contains only lookup summaries and case references.
"""
import argparse
import datetime as dt
import hashlib
import json
import os
from pathlib import Path
import re
import sqlite3
import sys

SOURCE = 'Polk County TX Tyler Odyssey Public Portal'
CASE = re.compile(r'(?=.{1,40}$)(?=.*\d)[A-Z0-9]+(?:-[A-Z0-9]+)*$', re.I)

def name_key(name):
    return ' '.join(sorted(re.findall(r'[^\W_]+', name.lower(), re.UNICODE)))

def now():
    return dt.datetime.now(dt.timezone.utc).isoformat()

def schema(db):
    db.executescript('''
    CREATE TABLE IF NOT EXISTS court_batches (
      digest TEXT PRIMARY KEY, observed_date TEXT NOT NULL, imported_at TEXT NOT NULL,
      record_count INTEGER NOT NULL, source TEXT NOT NULL);
    CREATE TABLE IF NOT EXISTS court_lookups (
      id TEXT PRIMARY KEY, name TEXT NOT NULL, name_key TEXT NOT NULL, booked TEXT NOT NULL,
      cases_total INTEGER NOT NULL, grouped_cases INTEGER NOT NULL, references_json TEXT NOT NULL,
      rejected_references INTEGER NOT NULL, review_state TEXT NOT NULL,
      observed_date TEXT NOT NULL, source_timestamp TEXT NOT NULL, imported_at TEXT NOT NULL,
      source TEXT NOT NULL, batch_digest TEXT NOT NULL,
      UNIQUE(name_key,booked,source));
    CREATE TABLE IF NOT EXISTS court_suppressions (lookup_id TEXT PRIMARY KEY, reason TEXT, suppressed_at TEXT);
    CREATE INDEX IF NOT EXISTS court_name_key ON court_lookups(name_key);
    ''')

def validate(payload):
    if payload.get('dataset') != 'crimewatch_polk_court_lookups':
        raise ValueError('Unsupported dataset; use the documented court lookup format')
    rows = payload.get('records')
    if not isinstance(rows, list) or not 1 <= len(rows) <= 10000:
        raise ValueError('Expected 1-10000 lookup records')
    if payload.get('record_count') != len(rows):
        raise ValueError('record_count does not match rows')
    stamp = payload.get('generated', '')
    if not isinstance(stamp, str) or not re.fullmatch(r'\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d+)?(?:Z|[+-]\d{2}:\d{2})?', stamp):
        raise ValueError('generated must be an ISO timestamp')
    observed = dt.datetime.strptime(stamp[:10], '%Y-%m-%d').date().isoformat()
    if observed > dt.datetime.now(dt.timezone.utc).date().isoformat():
        raise ValueError('Future export date')
    source = payload.get('source', '')
    if not isinstance(source,str) or 'Polk' not in source or 'Tyler' not in source:
        raise ValueError('Unexpected source')
    normalized, seen = [], set()
    for r in rows:
        name = r.get('roster_name')
        if not isinstance(name,str) or not 2 <= len(name) <= 200 or not name_key(name):
            raise ValueError('Invalid name')
        booked = dt.datetime.strptime(r.get('booked',''), '%Y-%m-%d').date().isoformat()
        total, grouped = r.get('cases_total'), r.get('live_cases')
        if type(total) is not int or type(grouped) is not int or not 0 <= grouped <= total <= 100000:
            raise ValueError('Invalid case counts')
        refs = r.get('live_case_numbers')
        if not isinstance(refs,list) or len(refs)>1000 or any(not isinstance(x,str) for x in refs):
            raise ValueError('Invalid references')
        valid = sorted(set(x.strip().upper() for x in refs if CASE.fullmatch(x.strip())))
        rejected = sum(not CASE.fullmatch(x.strip()) for x in refs)
        note = r.get('notes','')
        if not isinstance(note,str): raise ValueError('Invalid notes')
        # Explicitly ambiguous uploads are quarantined; descriptors never establish identity.
        ambiguous = bool(re.search(r'needs?\s+(?:a\s+)?DOB|two\s+spellings|ambiguous|uncertain|matching caution',note,re.I))
        if grouped and not valid: ambiguous = True
        key = name_key(name)
        identity = key+'|'+booked+'|'+SOURCE
        if identity in seen: raise ValueError('Duplicate lookup in input')
        seen.add(identity)
        normalized.append((hashlib.sha256(identity.encode()).hexdigest()[:24],name.strip(),key,booked,total,grouped,json.dumps(valid),rejected,'held' if ambiguous else 'unverified',observed,stamp,SOURCE))
    return normalized

def import_file(db, path):
    raw = Path(path).read_bytes()
    if len(raw)>20_000_000: raise ValueError('Export too large')
    payload = json.loads(raw)
    records = validate(payload)
    digest = hashlib.sha256(raw).hexdigest()
    if db.execute('SELECT 1 FROM court_batches WHERE digest=?',(digest,)).fetchone():
        return {'file':Path(path).name,'duplicate':True,'written':0}
    written = 0
    with db:
        for r in records:
            old = db.execute('SELECT source_timestamp FROM court_lookups WHERE id=?',(r[0],)).fetchone()
            if old and old[0] > r[10]: continue
            db.execute('''INSERT INTO court_lookups
             (id,name,name_key,booked,cases_total,grouped_cases,references_json,rejected_references,review_state,observed_date,source_timestamp,source,imported_at,batch_digest)
             VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?)
             ON CONFLICT(id) DO UPDATE SET name=excluded.name,cases_total=excluded.cases_total,
             grouped_cases=excluded.grouped_cases,references_json=excluded.references_json,
             rejected_references=excluded.rejected_references,review_state=excluded.review_state,
             observed_date=excluded.observed_date,source_timestamp=excluded.source_timestamp,
             imported_at=excluded.imported_at,batch_digest=excluded.batch_digest''', (*r,now(),digest))
            written += 1
        db.execute('INSERT INTO court_batches VALUES(?,?,?,?,?)',(digest,records[0][9],now(),len(records),SOURCE))
    return {'file':Path(path).name,'duplicate':False,'written':written,'held':sum(r[8]=='held' for r in records)}

def rebuild_queue(db, directory):
    hidden = set()
    for name, in db.execute('SELECT r.name FROM roster r JOIN takedowns t ON t.bid=r.bid'):
        hidden.add(name_key(name))
    imported = {(r[0],r[1]):(r[2],r[3]) for r in db.execute('SELECT name_key,booked,observed_date,review_state FROM court_lookups')}
    cutoff = (dt.datetime.now(dt.timezone.utc).date()-dt.timedelta(days=7)).isoformat()
    queue = []
    for bid,name,booked in db.execute('SELECT bid,name,admit_date FROM roster WHERE in_custody=1 ORDER BY admit_date DESC,bid'):
        key = name_key(name)
        if key in hidden: continue
        prev = imported.get((key,booked))
        if prev and prev[1]=='held': reason='identity_review_required'
        elif not prev: reason='not_yet_imported'
        elif prev[0]<cutoff: reason='snapshot_older_than_7_days'
        else: continue
        queue.append({'bid':bid,'name':name,'booked':booked,'reason':reason})
    target = directory/'court-pending.json'
    temporary = target.with_suffix('.tmp')
    temporary.write_text(json.dumps({'generatedAt':now(),'collection':'not_automated','records':queue},indent=2),encoding='utf-8')
    temporary.replace(target)
    return len(queue)

def run(directory, files=()):
    directory = Path(directory)
    if not (directory/'jail.sqlite').is_file(): raise ValueError('Existing jail database required')
    db = sqlite3.connect(str(directory/'jail.sqlite'),timeout=30)
    schema(db)
    inbox = directory/'court-inbox'
    inbox.mkdir(exist_ok=True)
    results, errors = [], []
    for path in list(files) + sorted(inbox.glob('*.json')):
        try: results.append(import_file(db,path))
        except Exception as e: errors.append({'file':Path(path).name,'error':str(e)})
    pending = rebuild_queue(db,directory)
    counts = dict(db.execute('SELECT review_state,COUNT(*) FROM court_lookups GROUP BY review_state'))
    status = {'processedAt':now(),'collection':'Uploaded snapshots; portal collection is not automated','results':results,'errors':errors,'counts':counts,'pending':pending}
    target = directory/'court-import-status.json'
    temp = target.with_suffix('.tmp')
    temp.write_text(json.dumps(status,indent=2),encoding='utf-8');temp.replace(target)
    db.close()
    return status

if __name__ == '__main__':
    parser = argparse.ArgumentParser()
    parser.add_argument('--data-dir', default=os.environ.get('CW_JAIL_DIR',str(Path(__file__).parent/'data')))
    parser.add_argument('files',nargs='*')
    args = parser.parse_args()
    report = run(args.data_dir,args.files)
    print(json.dumps(report))
    sys.exit(1 if report['errors'] else 0)
