import argparse
import copy
import json
import os
from pathlib import Path
import sqlite3
import subprocess
import tempfile
import unittest
import court_import as court

parser=argparse.ArgumentParser()
parser.add_argument('--php',default='php')
options,rest=parser.parse_known_args()

class CourtImport(unittest.TestCase):
    def setUp(self):
        self.tmp=tempfile.TemporaryDirectory()
        self.dir=Path(self.tmp.name)
        self.db=sqlite3.connect(str(self.dir/'jail.sqlite'))
        self.db.executescript('''CREATE TABLE roster(bid TEXT,name TEXT,admit_date TEXT,in_custody INTEGER);
          CREATE TABLE bookings(bid TEXT,name TEXT); CREATE TABLE takedowns(bid TEXT);
          INSERT INTO roster VALUES('fixture','EXAMPLE, ALEX','2026-09-19',1);''')
        court.schema(self.db)
        self.payload={'dataset':'crimewatch_polk_court_lookups','generated':'2026-09-21T00:49:30','source':court.SOURCE,'record_count':1,'records':[
          {'booked':'2026-09-19','roster_name':'Example, Alex','cases_total':2,'live_cases':1,'live_case_numbers':['CR26-0001'],'descriptors':'PRIVATE TEST','notes':'MATCH'}]}

    def tearDown(self):
        self.db.close();self.tmp.cleanup()

    def ingest(self):
        path=self.dir/'upload.json';path.write_text(json.dumps(self.payload),encoding='utf-8')
        return court.import_file(self.db,path)

    def api(self, **params):
        endpoint=Path(__file__).resolve().parents[2]/'public/court-api.php'
        env=dict(os.environ,CW_JAIL_DIR=str(self.dir),TEST_QUERY=json.dumps(params),TEST_ENDPOINT=str(endpoint))
        code="$_GET=json_decode(getenv('TEST_QUERY'),true);register_shutdown_function(function(){fwrite(STDERR,'STATUS='.(http_response_code()?:200));});include getenv('TEST_ENDPOINT');"
        p=subprocess.run([options.php,'-r',code],env=env,capture_output=True,text=True,check=True)
        return int(p.stderr.split('STATUS=')[-1]),json.loads(p.stdout)

    def test_repeat_and_revised_export(self):
        self.assertEqual(self.ingest()['written'],1)
        self.assertTrue(self.ingest()['duplicate'])
        self.payload['records'][0]['cases_total']=3
        self.payload['generated']='2026-09-21T01:00:00'
        self.ingest()
        self.assertEqual(self.db.execute('SELECT COUNT(*),MAX(cases_total) FROM court_lookups').fetchone(),(1,3))
        self.payload['generated']='2026-09-20T01:00:00'
        self.payload['records'][0]['cases_total']=1
        self.assertEqual(self.ingest()['written'],0)
        self.assertEqual(self.db.execute('SELECT cases_total FROM court_lookups').fetchone()[0],3)

    def test_ambiguous_and_placeholder_references_held(self):
        self.payload['records'][0]['notes']='TWO spellings - needs DOB match'
        self.payload['records'][0]['live_case_numbers']=['2 inactive felonies']
        self.assertEqual(self.ingest()['held'],1)
        status,data=self.api(name='Example')
        self.assertEqual(status,200);self.assertEqual(data['total'],0)

    def test_search_and_private_fields(self):
        self.ingest()
        status,data=self.api(name='alex example')
        self.assertEqual(status,200);self.assertEqual(data['total'],1)
        self.assertEqual(data['records'][0]['references'],['CR26-0001'])
        self.assertNotIn('PRIVATE TEST',json.dumps(data))
        self.assertNotIn('notes',data['records'][0]);self.assertNotIn('grouped_cases',data['records'][0])

    def test_booking_date_conflict_is_visible_without_identity_merge(self):
        self.ingest()
        self.db.execute("UPDATE roster SET admit_date='2026-09-08'");self.db.commit()
        status,data=self.api(name='Example')
        self.assertEqual(status,200)
        self.assertEqual(data['records'][0]['booking_comparison'],'different_date')
        self.assertEqual(data['records'][0]['booked'],'2026-09-19')
        self.assertEqual(data['records'][0]['roster_dates'],['2026-09-08'])

    def test_takedown_matches_normalized_name_and_stays_suppressed(self):
        self.ingest()
        self.db.execute("UPDATE roster SET name='ALEX EXAMPLE'")
        self.db.execute("INSERT INTO takedowns VALUES('fixture')");self.db.commit()
        self.assertEqual(self.api(name='Example')[1]['total'],0)
        self.assertEqual(court.rebuild_queue(self.db,self.dir),0)

    def test_court_suppression_survives_reimport(self):
        self.ingest()
        id=self.db.execute('SELECT id FROM court_lookups').fetchone()[0]
        self.db.execute('INSERT INTO court_suppressions VALUES(?,?,?)',(id,'fixture',court.now()));self.db.commit()
        self.payload['generated']='2026-09-21T02:00:00';self.ingest()
        self.assertEqual(self.api(name='Example')[1]['total'],0)

    def detailed_payload(self):
        return {'dataset':'crimewatch_polk_court_cases_detail','generated':'2026-09-21T01:43:02',
          'source':court.SOURCE,'people':[{'roster_name':'Example, Alex','booked':'2026-09-19',
          'record_complete':False,'cases':[
           {'case_number':'CR26-0001','style_defendant':'EXAMPLE, ALEX','party_name':'ALEX EXAMPLE',
            'file_date':'2026-09-01','case_type':'Felony Indictment','status':'Disposed',
            'subject_is_defendant':True,'is_live':False},
           {'case_number':'CR26-0002','style_defendant':'OTHER, SAM','party_name':'OTHER, SAM',
            'file_date':'2026-09-02','case_type':'Felony Indictment','status':'Active',
            'subject_is_defendant':False,'is_live':True},
           {'case_number':'CIV123','style_defendant':'Civil example','party_name':'EXAMPLE, ALEX',
            'file_date':'2025-09-02','case_type':'Motor Vehicle Accident','status':'Disposed',
            'subject_is_defendant':True,'is_live':False}]}]}

    def test_case_index_import_groups_and_repeated_batches(self):
        self.ingest();self.payload=self.detailed_payload()
        self.assertEqual(self.ingest()['case_rows'],3)
        self.assertTrue(self.ingest()['duplicate'])
        status,data=self.api(name='Example')
        self.assertEqual(status,200);r=data['records'][0]
        self.assertEqual({c['case_number']:c['group'] for c in r['cases']},
                         {'CR26-0001':'criminal','CR26-0002':'other_party','CIV123':'civil_other'})
        self.assertFalse(r['record_complete'])
        self.assertEqual(next(c for c in r['cases'] if c['case_number']=='CR26-0001')['status'],'Disposed')
        self.assertNotIn('is_live',json.dumps(r))

    def test_case_index_partial_update_and_older_file(self):
        self.payload=self.detailed_payload();self.ingest()
        self.payload['generated']='2026-09-21T01:44:02';self.payload['people'][0]['cases'][0]['status']='Dismissed'
        self.ingest()
        self.payload['generated']='2026-09-21T01:42:02';self.payload['people'][0]['cases'][0]['status']='Active'
        self.assertEqual(self.ingest()['written'],0)
        cases=self.api(name='Example')[1]['records'][0]['cases']
        self.assertEqual(next(c for c in cases if c['case_number']=='CR26-0001')['status'],'Dismissed')

    def test_bad_case_batch_is_atomic(self):
        self.ingest();self.payload=self.detailed_payload()
        self.payload['people'][0]['cases'][1]['file_date']='2026-02-30'
        with self.assertRaises(ValueError):self.ingest()
        self.assertEqual(self.db.execute('SELECT COUNT(*) FROM court_case_sets').fetchone()[0],0)
        self.assertEqual(self.db.execute('SELECT cases_total FROM court_lookups').fetchone()[0],2)

    def test_details_preserve_existing_identity_hold_and_suppression(self):
        self.payload['records'][0]['notes']='TWO spellings - needs DOB match';self.ingest()
        self.payload=self.detailed_payload();self.ingest()
        self.assertEqual(self.api(name='Example')[1]['total'],0)
        self.assertEqual(self.db.execute('SELECT COUNT(*) FROM court_case_sets').fetchone()[0],1)
        self.db.execute("UPDATE court_lookups SET review_state='unverified'")
        lookup=self.db.execute('SELECT id FROM court_lookups').fetchone()[0]
        self.db.execute('INSERT INTO court_suppressions VALUES(?,?,?)',(lookup,'fixture',court.now()));self.db.commit()
        self.payload['generated']='2026-09-21T01:44:02';self.ingest()
        self.assertEqual(self.api(name='Example')[1]['total'],0)

    def test_other_party_takedown_applies_to_case_details(self):
        self.payload=self.detailed_payload();self.ingest()
        self.db.execute("INSERT INTO roster VALUES('other','SAM OTHER','2026-09-19',1)")
        self.db.execute("INSERT INTO takedowns VALUES('other')");self.db.commit()
        r=self.api(name='Example')[1]['records'][0]
        self.assertEqual(len(r['cases']),2)
        self.assertNotIn('CR26-0002',json.dumps(r))

    def test_bad_batch_is_atomic(self):
        row=copy.deepcopy(self.payload['records'][0]);row['roster_name']='Other, Person';row['cases_total']=-1
        self.payload['records'].append(row);self.payload['record_count']=2
        with self.assertRaises(ValueError):self.ingest()
        self.assertEqual(self.db.execute('SELECT COUNT(*) FROM court_lookups').fetchone()[0],0)

    def test_queue_and_missing_source(self):
        self.assertEqual(court.rebuild_queue(self.db,self.dir),1)
        self.assertEqual(json.loads((self.dir/'court-pending.json').read_text())['collection'],'not_automated')
        self.db.execute('DROP TABLE court_lookups');self.db.commit()
        self.assertEqual(self.api(name='Example')[0],503)
        self.assertEqual(self.api(name=['Example'])[0],400)

if __name__=='__main__':unittest.main(argv=[__file__]+rest)
