"""Validate and import case-index exports; statuses are source labels, not verdicts."""
import datetime as dt
import hashlib
import json
from pathlib import Path

from court_import import CASE, SOURCE, name_key, now, validate

CRIMINAL_TYPES = {'Misdemeanor', 'Felony Indictment', 'Felony Information'}


def validate_details(payload):
    people = payload.get('people')
    if not isinstance(people, list) or not 1 <= len(people) <= 10000:
        raise ValueError('Expected 1-10000 people')
    summaries, detail_sets = [], []
    for person in people:
        cases = person.get('cases')
        complete = person.get('record_complete')
        if type(complete) is not bool or not isinstance(cases, list) or len(cases) > 1000:
            raise ValueError('Invalid case set')
        cleaned, seen = [], set()
        for case in cases:
            row = {}
            for key, maximum in [('case_number',40),('style_defendant',1000),('file_date',10),
                                 ('case_type',200),('status',100),('party_name',300)]:
                value = case.get(key)
                if not isinstance(value,str) or not value.strip() or len(value)>maximum:
                    raise ValueError('Invalid case field: '+key)
                row[key] = value.strip()
            row['case_number'] = row['case_number'].upper()
            if not CASE.fullmatch(row['case_number']) or row['case_number'] in seen:
                raise ValueError('Invalid or duplicate case number within a person')
            seen.add(row['case_number'])
            parsed = dt.datetime.strptime(row['file_date'],'%Y-%m-%d').date().isoformat()
            if parsed != row['file_date']: raise ValueError('Invalid filing date')
            if type(case.get('subject_is_defendant')) is not bool:
                raise ValueError('Case-party flag must be boolean')
            row['subject_is_defendant'] = case['subject_is_defendant']
            same_name = name_key(row['party_name']) == name_key(person.get('roster_name',''))
            if row['case_type'] in CRIMINAL_TYPES:
                row['group'] = 'criminal' if same_name and row['subject_is_defendant'] else 'other_party'
            else:
                row['group'] = 'civil_other'
            # Do not use is_live: it incorrectly combines Active, Inactive and Filed.
            cleaned.append(row)
        cleaned.sort(key=lambda c:(c['file_date'],c['case_number']),reverse=True)
        summaries.append({'roster_name':person.get('roster_name'),'booked':person.get('booked'),
                          'cases_total':len(cleaned),'live_cases':0,
                          'live_case_numbers':[c['case_number'] for c in cleaned],'notes':''})
        detail_sets.append((cleaned,complete))
    normalized = validate({'dataset':'crimewatch_polk_court_lookups','generated':payload.get('generated'),
                           'source':payload.get('source'),'records':summaries,'record_count':len(summaries)})
    return list(zip(normalized,detail_sets))


def import_details(db, path, raw, payload):
    sets = validate_details(payload)  # Validate the entire batch before any writes.
    digest = hashlib.sha256(raw).hexdigest()
    if db.execute('SELECT 1 FROM court_batches WHERE digest=?',(digest,)).fetchone():
        return {'file':Path(path).name,'duplicate':True,'written':0}
    written = case_rows = 0
    with db:
        for r,(cases,complete) in sets:
            previous = db.execute('SELECT source_timestamp FROM court_case_sets WHERE lookup_id=?',(r[0],)).fetchone()
            if previous and previous[0] >= r[10]: continue
            old = db.execute('SELECT source_timestamp FROM court_lookups WHERE id=?',(r[0],)).fetchone()
            if not old or old[0] <= r[10]:
                db.execute('''INSERT INTO court_lookups
                  (id,name,name_key,booked,cases_total,grouped_cases,references_json,rejected_references,
                   review_state,observed_date,source_timestamp,source,imported_at,batch_digest)
                  VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?)
                  ON CONFLICT(id) DO UPDATE SET name=excluded.name,cases_total=excluded.cases_total,
                   grouped_cases=excluded.grouped_cases,references_json=excluded.references_json,
                   observed_date=excluded.observed_date,source_timestamp=excluded.source_timestamp,
                   imported_at=excluded.imported_at,batch_digest=excluded.batch_digest''',(*r,now(),digest))
            db.execute('''INSERT INTO court_case_sets VALUES(?,?,?,?,?,?,?)
              ON CONFLICT(lookup_id) DO UPDATE SET cases_json=excluded.cases_json,
              record_complete=excluded.record_complete,observed_date=excluded.observed_date,
              source_timestamp=excluded.source_timestamp,imported_at=excluded.imported_at,
              batch_digest=excluded.batch_digest''',
              (r[0],json.dumps(cases),int(complete),r[9],r[10],now(),digest))
            written += 1
            case_rows += len(cases)
        db.execute('INSERT INTO court_batches VALUES(?,?,?,?,?)',(digest,sets[0][0][9],now(),len(sets),SOURCE))
    return {'file':Path(path).name,'duplicate':False,'written':written,'case_rows':case_rows,
            'partial':sum(not detail[1] for _,detail in sets)}
