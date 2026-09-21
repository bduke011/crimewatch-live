import ast, sqlite3, unittest
from pathlib import Path

tree=ast.parse(Path(__file__).with_name('import_jail.py').read_text())
strings=[n.value for n in ast.walk(tree) if isinstance(n,ast.Constant) and isinstance(n.value,str)]
schema=next(s for s in strings if s.startswith('CREATE TABLE IF NOT EXISTS bookings'))
insert=next(s for s in strings if s.startswith('INSERT INTO bookings'))

class BookingSchemaTest(unittest.TestCase):
    def test_import_supports_original_and_roster_extended_schema(self):
        for extended in [False,True]:
            with self.subTest(extended=extended),sqlite3.connect(':memory:') as db:
                db.executescript(schema)
                if extended: db.execute('ALTER TABLE bookings ADD COLUMN bid TEXT')
                row=['test','Example Person',30,'2026-09-18',None,'Example','[]','Example','first','last','2026-09-19']
                db.execute(insert,row)
                if extended: db.execute("UPDATE bookings SET bid='existing-link'")
                row[4]='2026-09-19'; db.execute(insert,row)
                self.assertEqual(db.execute('SELECT COUNT(*),released FROM bookings').fetchone(),(1,'2026-09-19'))
                row[4]=None; db.execute(insert,row)
                self.assertEqual(db.execute('SELECT released FROM bookings').fetchone()[0],'2026-09-19')
                if extended:self.assertEqual(db.execute('SELECT bid FROM bookings').fetchone()[0],'existing-link')

if __name__=='__main__':unittest.main()
