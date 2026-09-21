"""Integration tests against the actual PHP endpoint using synthetic SQLite data.

Run: python services/jail/test_research_api.py --php /path/to/php
"""
import argparse
import json
import os
from pathlib import Path
import sqlite3
import subprocess
import tempfile
import unittest
from datetime import datetime, timezone

parser = argparse.ArgumentParser()
parser.add_argument('--php', default='php')
options, remaining = parser.parse_known_args()
ENDPOINT = Path(__file__).resolve().parents[2] / 'public' / 'research-api.php'

class ResearchAPI(unittest.TestCase):
    def setUp(self):
        self.tmp = tempfile.TemporaryDirectory()
        self.dir = Path(self.tmp.name)
        self.db = sqlite3.connect(self.dir / 'jail.sqlite')
        self.db.executescript('''
        CREATE TABLE bookings(id TEXT PRIMARY KEY,name TEXT,age INTEGER,booked TEXT,released TEXT,locality TEXT,details TEXT,report_date TEXT,bid TEXT);
        CREATE TABLE roster(bid TEXT PRIMARY KEY,name TEXT,age INTEGER,admit_date TEXT,admit_time TEXT,locality TEXT,details TEXT,last_detail TEXT,in_custody INTEGER);
        CREATE TABLE takedowns(bid TEXT PRIMARY KEY);
        ''')
        charges = json.dumps([{'agency': 'Fixture Agency', 'charges': [{'description': 'Example charge', 'warrantNumber': 'FIXTURE-123'}]}])
        for i in range(23):
            self.db.execute('INSERT INTO bookings VALUES(?,?,?,?,?,?,?,?,?)',
                (f'{i:024x}', 'EXAMPLE, ALEX', 35, '2026-09-19', None, 'Fixture town', charges, '2026-09-20', f'b{i}'))
        self.db.execute('INSERT INTO bookings VALUES(?,?,?,?,?,?,?,?,?)',
            ('f'*24, 'UNRELATED, PERSON', 42, '2026-09-19', None, 'Alex Example', charges, '2026-09-20', 'unrelated'))
        details = json.dumps({'charges': [{'description': 'Example charge', 'bond': '$1,000', 'courtDate': '10/01/2026', 'docket': 'FIXTURE-123'}]})
        for bid,custody in [('b0',1),('b1',1),('released',0)]:
            self.db.execute('INSERT INTO roster VALUES(?,?,?,?,?,?,?,?,?)', (bid,'EXAMPLE, ALEX',35,'2026-09-19','12:00','Fixture town',details,'2026-09-20T01:00:00Z',custody))
        self.db.execute("INSERT INTO takedowns VALUES('b1')")
        self.db.commit()
        status = {'checkedAt': datetime.now(timezone.utc).isoformat(), 'complete': True, 'failedReports': 0}
        for file in ['status.json','roster-status.json']:
            (self.dir/file).write_text(json.dumps(status))

    def tearDown(self):
        self.db.close()
        self.tmp.cleanup()

    def request(self, **params):
        harness = '''$_GET=json_decode(getenv('TEST_QUERY'),true); $_SERVER['REQUEST_METHOD']='GET';
        register_shutdown_function(function(){fwrite(STDERR,"STATUS=".(http_response_code()?:200));});
        include getenv('TEST_ENDPOINT');'''
        env = dict(os.environ, CW_JAIL_DIR=str(self.dir), TEST_QUERY=json.dumps(params), TEST_ENDPOINT=str(ENDPOINT.resolve()))
        p = subprocess.run([options.php,'-r',harness],env=env,capture_output=True,text=True,check=True)
        status = int(p.stderr.split('STATUS=')[-1])
        return status, json.loads(p.stdout)

    def test_name_order_pagination_and_exclusions(self):
        status, body = self.request(source='archive',name='Alex Example')
        self.assertEqual(status,200)
        self.assertEqual(body['total'],22)
        self.assertEqual(len(body['records']),20)
        self.assertEqual(body['pages'],2)
        self.assertEqual(body['warnings'],[])
        self.assertEqual(body['records'][0]['charges'][0]['reference'],'FIXTURE-123')
        _, reverse = self.request(source='archive',name='example, ALEX',page='2')
        self.assertEqual(len(reverse['records']),2)
        self.assertFalse(set(r['id'] for r in body['records']) & set(r['id'] for r in reverse['records']))

    def test_roster_custody_details_and_takedowns(self):
        status, body = self.request(source='roster',name='Example')
        self.assertEqual(status,200)
        self.assertEqual(body['total'],1)
        self.assertEqual(body['records'][0]['charges'][0]['courtDate'],'10/01/2026')
        self.assertEqual(body['records'][0]['key'],'roster:b0')

    def test_record_lookup_respects_exclusions(self):
        for source,record in [('archive',f'{1:024x}'),('roster','b1')]:
            status,body = self.request(source=source,record=record)
            self.assertEqual(status,200)
            self.assertEqual(body['total'],0)
        status,body = self.request(source='archive',record=f'{0:024x}')
        self.assertEqual(status,200)
        self.assertEqual(body['records'][0]['name'],'EXAMPLE, ALEX')

    def test_invalid_queries(self):
        for params in [dict(source='bad',name='Example'),dict(source='archive',name=['Example']),dict(source='archive',name='%_'),dict(source='archive',name='Example',page='-1'),dict(source='archive',record="' OR 1=1")]:
            self.assertEqual(self.request(**params)[0],400)

    def test_empty_results_and_failed_source_are_distinct(self):
        status,body = self.request(source='archive',name='Nobody')
        self.assertEqual(status,200)
        self.assertEqual(body['total'],0)
        self.db.execute('DROP TABLE roster')
        self.db.commit()
        status,body = self.request(source='roster',name='Nobody')
        self.assertEqual(status,503)
        self.assertIn('error',body)
        self.assertNotIn('total',body)

    def test_incomplete_and_stale_collection(self):
        (self.dir/'roster-status.json').write_text(json.dumps({'checkedAt':'2020-01-01','complete':False,'errors':['fixture']}))
        status,body = self.request(source='roster',name='Example')
        self.assertEqual(status,200)
        self.assertEqual(len(body['warnings']),3)

if __name__ == '__main__':
    unittest.main(argv=[__file__]+remaining)
