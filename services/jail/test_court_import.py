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
