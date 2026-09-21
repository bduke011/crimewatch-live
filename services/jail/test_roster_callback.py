import unittest
import sqlite3
import ast
from pathlib import Path
from unittest.mock import patch
import roster

class CallbackTest(unittest.TestCase):
    def test_sort_arguments_are_individually_encoded(self):
        state={'keys':'[]','state':'state','__VIEWSTATE':'v','__VIEWSTATEGENERATOR':'g','__EVENTVALIDATION':None}
        with patch.object(roster,'fetch',return_value=(b'',None)) as fetch:
            roster.grid_callback(state,['8','','DSC','true'],'https://example.test',command='SORT')
        self.assertIn('4|SORT1|80|3|DSC4|true',fetch.call_args.args[1]['__CALLBACKPARAM'])

    def test_paging_keeps_single_argument_encoding(self):
        state={'keys':'[]','state':'state','__VIEWSTATE':'v','__VIEWSTATEGENERATOR':'g','__EVENTVALIDATION':None}
        with patch.object(roster,'fetch',return_value=(b'',None)) as fetch:
            roster.grid_callback(state,'PN1','https://example.test')
        self.assertIn('12|PAGERONCLICK3|PN1',fetch.call_args.args[1]['__CALLBACKPARAM'])

class PhotoMatchTest(unittest.TestCase):
    def test_missing_released_photos_are_retried_but_hidden_records_are_not(self):
        tree=ast.parse(Path(roster.__file__).read_text())
        sql=next(n.value for n in ast.walk(tree) if isinstance(n,ast.Constant) and isinstance(n.value,str) and n.value.startswith('SELECT bid, detail_url, photo FROM roster'))
        with sqlite3.connect(':memory:') as db:
            db.executescript('CREATE TABLE roster(bid TEXT,detail_url TEXT,photo TEXT,in_custody INTEGER,last_detail TEXT,release_date TEXT,admit_date TEXT);CREATE TABLE takedowns(bid TEXT);')
            for bid,photo,last in [('retry',None,'2026-09-18'),('hidden',None,'2026-09-18'),('recent',None,'2026-09-20'),('has-photo','photo.jpg','2026-09-18')]:
                db.execute('INSERT INTO roster VALUES(?,?,?,0,?,?,?)',(bid,'url',photo,last,'2026-09-19','2026-09-18'))
            db.execute("INSERT INTO takedowns VALUES('hidden')")
            rows=db.execute(sql,('2026-09-19','2026-09-13','2026-09-19',150)).fetchall()
            self.assertEqual([r[0] for r in rows],['retry'])

    def test_ambiguous_or_different_identity_does_not_get_a_photo(self):
        with sqlite3.connect(':memory:') as db:
            db.executescript('CREATE TABLE bookings(id TEXT,name TEXT,booked TEXT,age INTEGER,bid TEXT); CREATE TABLE roster(bid TEXT,name TEXT,admit_date TEXT,age INTEGER);')
            db.executemany('INSERT INTO bookings VALUES(?,?,?,?,NULL)',[
                ('good','TEST, PERSON JR','2026-09-19',30),
                ('suffix','TEST, PERSON SR','2026-09-19',30),
                ('age','TEST, PERSON JR','2026-09-19',50),
                ('ambiguous','OTHER, PERSON','2026-09-19',30)])
            db.executemany('INSERT INTO roster VALUES(?,?,?,?)',[
                ('right','PERSON TEST JR','2026-09-19',30),
                ('a','OTHER, PERSON','2026-09-18',30),
                ('b','OTHER, PERSON','2026-09-20',30)])
            self.assertEqual(roster.link_bookings(db),1)
            self.assertEqual(dict(db.execute('SELECT id,bid FROM bookings')),{'good':'right','suffix':None,'age':None,'ambiguous':None})

if __name__=='__main__':unittest.main()
