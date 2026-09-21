"""Private live-roster importer for the Polk County DCN inmate site.

Pulls the current in-custody list, opens each booking's detail page, saves the
booking photo privately, and links roster entries to the PDF booking archive.
Raw pages and photos never go under public_html.

Usage:
  python3 roster.py                 normal import (used by run_scheduled.py)
  python3 roster.py --debug         also save raw HTML to data/debug/
  python3 roster.py --hide BID "reason"   take a record + photo off the site
  python3 roster.py --unhide BID
"""
import os, re, sys, json, time, html, sqlite3, hashlib, datetime, urllib.request, urllib.parse, http.cookiejar
from pathlib import Path

ROOT = Path(os.environ.get('CW_JAIL_DIR', str(Path(__file__).parent / 'data')))
BASE = 'https://inmates.polkcountyso.net:8443/DCN/'
UA = os.environ.get('CW_ROSTER_UA', 'CrimeWatch.live public-record archive (contact: editor@crimewatch.live)')
DELAY = float(os.environ.get('CW_ROSTER_DELAY', '2'))      # seconds between requests
DETAIL_REFRESH_HOURS = 24                                  # re-read charges/bond this often
RELEASED_DAYS = int(os.environ.get('CW_ROSTER_RELEASED_DAYS', '7'))
DETAIL_BUDGET = int(os.environ.get('CW_ROSTER_DETAIL_BUDGET', '150'))  # max detail pages per run
PARSER = 2
DEBUG = '--debug' in sys.argv

_jar = http.cookiejar.CookieJar()
_opener = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(_jar))
_last = 0.0


def now():
    return datetime.datetime.now(datetime.timezone.utc).isoformat(timespec='seconds')


def _throttle():
    global _last
    wait = DELAY - (time.monotonic() - _last)
    if wait > 0:
        time.sleep(wait)
    _last = time.monotonic()


def fetch(url, data=None, referer=None, limit=6_000_000):
    _throttle()
    headers = {'User-Agent': UA, 'Accept': '*/*'}
    if referer:
        headers['Referer'] = referer
    body = urllib.parse.urlencode(data).encode() if data is not None else None
    if body is not None:
        headers['Content-Type'] = 'application/x-www-form-urlencoded'
    req = urllib.request.Request(url, data=body, headers=headers)
    with _opener.open(req, timeout=60) as r:
        raw = r.read(limit + 1)
        if len(raw) > limit:
            raise ValueError('Response exceeds size limit: ' + url)
        return raw, r.headers.get('Content-Type', '')


def dump(name, raw):
    if DEBUG:
        d = ROOT / 'debug'; d.mkdir(parents=True, exist_ok=True)
        (d / name).write_bytes(raw if isinstance(raw, bytes) else raw.encode())


# ---------- HTML helpers ----------
TAG = re.compile(r'<[^>]+>')


def text(s):
    s = TAG.sub(' ', s or '')
    s = html.unescape(s).replace('\xa0', ' ')
    return re.sub(r'\s+', ' ', s).strip()


def js_unescape(s):
    """DevExpress callback results are JS string literals."""
    s = re.sub(r'\\x([0-9a-fA-F]{2})', lambda m: chr(int(m[1], 16)), s)
    s = re.sub(r'\\u([0-9a-fA-F]{4})', lambda m: chr(int(m[1], 16)), s)
    return s.replace("\\'", "'").replace('\\"', '"').replace('\\r', '\r').replace('\\n', '\n').replace('\\t', '\t').replace('\\/', '/').replace('\\\\', '\\')


def iso_mdy(s):
    s = (s or '').strip()
    for fmt in ('%m/%d/%Y', '%m-%d-%Y'):
        try:
            return datetime.datetime.strptime(s, fmt).date().isoformat()
        except ValueError:
            pass
    return None


def name_key(name):
    toks = re.findall(r'[A-Z0-9]+', (name or '').upper())
    return ' '.join(sorted(toks))


# ---------- roster list ----------
ROW = re.compile(
    r"<tr id=\"gvInmates_DXDataRow\d+\".*?<a href='(/DCN/inmate-details\?[^']+)'>\s*(.*?)\s*</a>\s*</td>"
    r"<td[^>]*>(\d*)</td><td[^>]*>([^<]*)</td><td[^>]*>([^<]*)</td><td[^>]*>([\d/]*)</td>", re.S)
PAGER = re.compile(r'Page (\d+) of (\d+) \((\d+) items\)')


def parse_rows(page):
    rows = []
    for href, name, age, race, sex, admit in ROW.findall(page):
        q = urllib.parse.parse_qs(urllib.parse.urlparse(html.unescape(href)).query)
        bid = q.get('bid', [''])[0]
        iid = q.get('id', [''])[0]
        if not bid:
            continue
        rows.append({'bid': bid, 'inmate_id': iid, 'href': html.unescape(href), 'name': text(name),
                     'age': int(age) if age.isdigit() else None, 'race': race.strip(), 'sex': sex.strip(),
                     'admit_date': iso_mdy(admit)})
    return rows


CELL = re.compile(r'<td[^>]*class="dxgv[^"]*"[^>]*>(.*?)</td>', re.S)
RROW = re.compile(r'<tr id="gvInmates_DXDataRow\d+"(.*?)</tr>', re.S)
LINK = re.compile(r"<a href='(/DCN/inmate-details[^']*)'>\s*(.*?)\s*</a>", re.S)


def parse_released_rows(page):
    """Rows of the released grid: name, age, race, sex, admit, release, days."""
    rows = []
    for body in RROW.findall(page):
        m = LINK.search(body)
        if not m:
            continue
        cells = [text(c) for c in CELL.findall(body) if 'inmate-details' not in c and 'dxbButton' not in c]
        q = urllib.parse.parse_qs(urllib.parse.urlparse(html.unescape(m[1])).query)
        bid = q.get('bid', [''])[0]
        dates = [iso_mdy(c) for c in cells if iso_mdy(c)]
        if not bid or len(dates) < 2:
            continue
        rows.append({'bid': bid, 'inmate_id': q.get('id', [''])[0], 'href': html.unescape(m[1]), 'name': text(m[2]),
                     'age': int(cells[0]) if cells and cells[0].isdigit() else None,
                     'race': cells[1] if len(cells) > 1 else '', 'sex': cells[2] if len(cells) > 2 else '',
                     'admit_date': dates[0], 'release_date': dates[1]})
    return rows


def fetch_released(days=7, max_pages=4):
    """Recent releases: sort the released grid by release date (newest first) and read until older than the window."""
    list_url = BASE + 'inmates-released'
    raw, _ = fetch(list_url, referer=BASE)
    page = raw.decode('utf8', 'replace')
    dump('released.html', page)
    if 'gvInmates' not in page:
        raise ValueError('Released page layout changed; review required')
    st = grid_state(page)
    cutoff = (datetime.date.today() - datetime.timedelta(days=days)).isoformat()
    out = {}
    rows = []; dates = []
    # DevExpress serializes each SortBy argument separately: column, index,
    # direction, reset. A semicolon-delimited string is a single invalid argument.
    for arg in (['8', '', 'DSC', 'true'],):
        rows = parse_released_rows(grid_callback(st, arg, list_url, command='SORT'))
        dates = [r['release_date'] for r in rows]
        if rows and dates == sorted(dates, reverse=True) and dates[0] >= cutoff:
            break
    else:
        raise ValueError('Released grid did not sort by release date; got %s' % dates[:3])
    n = 0
    while True:
        for r in rows:
            if r['release_date'] >= cutoff:
                out[r['bid']] = r
        if not rows or rows[-1]['release_date'] < cutoff or n + 1 >= max_pages:
            break
        n += 1
        rows = parse_released_rows(grid_callback(st, 'PN%d' % n, list_url))
    return list(out.values())


def grid_state(page):
    vs = re.search(r'id="__VIEWSTATE" value="([^"]*)"', page)
    vsg = re.search(r'id="__VIEWSTATEGENERATOR" value="([^"]*)"', page)
    ev = re.search(r'id="__EVENTVALIDATION" value="([^"]*)"', page)
    cs = re.search(r"'callbackState':'([^']*)'", page)
    keys = re.search(r"'keys':(\[[^\]]*\])", page)
    return {'__VIEWSTATE': vs[1] if vs else '', '__VIEWSTATEGENERATOR': vsg[1] if vsg else '',
            '__EVENTVALIDATION': ev[1] if ev else None, 'state': cs[1] if cs else '', 'keys': keys[1] if keys else '[]'}


def grid_callback(st, arg, list_url, command='PAGERONCLICK'):
    """Replay a DevExpress ASPxGridView callback (pager, sort...)."""
    args = [command] + (arg if isinstance(arg, list) else [arg])
    cmd = ''.join('%d|%s' % (len(str(value)), value) for value in args)
    fields = {'__EVENTTARGET': '', '__EVENTARGUMENT': '', '__VIEWSTATE': st['__VIEWSTATE'],
              '__VIEWSTATEGENERATOR': st['__VIEWSTATEGENERATOR'], '__CALLBACKID': 'gvInmates',
              '__CALLBACKPARAM': 'c0:KV|%d;%s;GB|%d;%s;' % (len(st['keys']), st['keys'], len(cmd), cmd),
              'gvInmates$CallbackState': st['state'], 'gvInmates$DXSelInput': '', 'gvInmates$DXKVInput': st['keys']}
    if st['__EVENTVALIDATION']:
        fields['__EVENTVALIDATION'] = st['__EVENTVALIDATION']
    raw, _ = fetch(list_url, fields, referer=list_url)
    page = js_unescape(raw.decode('utf8', 'replace'))
    dump('callback-%s.html' % arg, page)
    ns = re.search(r"'callbackState':'([^']*)'", page)
    if ns:
        st['state'] = ns[1]
    nk = re.search(r"'keys':(\[[^\]]*\])", page)
    if nk:
        st['keys'] = nk[1]
    return page


def fetch_roster():
    """Return (rows, expected_total). Raises if the list cannot be read."""
    list_url = BASE + 'inmates'
    raw, _ = fetch(list_url, referer=BASE)
    page = raw.decode('utf8', 'replace')
    dump('inmates.html', page)
    if 'gvInmates' not in page:
        raise ValueError('Roster page layout changed; review required')
    m = PAGER.search(page)
    total = int(m[3]) if m else None
    rows = parse_rows(page)
    seen = {r['bid']: r for r in rows}
    if total is None or len(seen) >= total:
        return list(seen.values()), total or len(seen)
    st = grid_state(page)
    # 1) ask the grid for "All" rows in one shot
    try:
        p = grid_callback(st, 'PS-1', list_url)
        for r in parse_rows(p):
            seen[r['bid']] = r
    except Exception as e:
        print('page-size callback failed: %s' % e, file=sys.stderr)
    # 2) fall back to walking the pages
    if len(seen) < total:
        pages = int(m[2])
        for n in range(1, pages):
            p = grid_callback(st, 'PN%d' % n, list_url)
            got = parse_rows(p)
            if not got:
                break
            for r in got:
                seen[r['bid']] = r
            if len(seen) >= total:
                break
    return list(seen.values()), total


# ---------- detail page ----------
def parse_detail(page):
    name = re.search(r'<span id="HeaderText">(.*?)</span>', page, re.S)
    img = re.search(r'<IMG id="mugShotImg"[^>]*src="([^"]+)"', page, re.I)
    facts = {}
    tbl = re.search(r'<table[^>]*id="dvDetail".*?</table>', page, re.S)
    if tbl:
        for k, v in re.findall(r'<td[^>]*font-weight:bold[^>]*>(.*?)</td>\s*<td[^>]*>(.*?)</td>', tbl[0], re.S):
            facts[text(k)] = text(v)
    charges = []
    grid = re.search(r'<table id="ChargeGrid_DXMainTable".*?</table>\s*<table', page, re.S)
    if grid:
        for row in re.findall(r'<tr id="ChargeGrid_DXDataRow\d+".*?</tr>', grid[0], re.S):
            cells = [text(c) for c in re.findall(r'<td[^>]*>(.*?)</td>', row, re.S)]
            if len(cells) < 9:
                continue
            charges.append(dict(zip(['description', 'offenseDate', 'courtType', 'courtDate', 'docket', 'bond', 'bondType', 'chargingAgency', 'arrestingAgency'], cells[:9])))
    if not name:
        raise ValueError('Detail layout changed; review required')
    city = facts.get('Address', '')
    m = re.search(r',\s*([A-Z .\-\']+?)\s+([A-Z]{2})\s+\d{5}', city)
    locality = (m[1].strip() + ', ' + m[2]) if m else ''
    return {'name': text(name[1]), 'photo_src': html.unescape(img[1]) if img else None, 'facts': facts,
            'locality': locality, 'charges': charges}


def save_photo(src, bid, referer):
    if not src or 'no_mugshot' in src:
        return None
    raw, ctype = fetch(urllib.parse.urljoin(BASE, src), referer=referer, limit=3_000_000)
    if raw[:3] == b'\xff\xd8\xff':
        ext = 'jpg'
    elif raw[:8] == b'\x89PNG\r\n\x1a\n':
        ext = 'png'
    else:
        return None
    (ROOT / 'photos').mkdir(parents=True, exist_ok=True)
    fn = hashlib.sha1(bid.encode()).hexdigest()[:24] + '.' + ext
    (ROOT / 'photos' / fn).write_bytes(raw)
    return fn


# ---------- database ----------
def open_db():
    ROOT.mkdir(parents=True, exist_ok=True)
    db = sqlite3.connect(str(ROOT / 'jail.sqlite'))
    db.execute('PRAGMA busy_timeout=10000'); db.execute('PRAGMA journal_mode=WAL')
    db.executescript('''
      CREATE TABLE IF NOT EXISTS roster(bid TEXT PRIMARY KEY, inmate_id TEXT, name TEXT, age INTEGER, sex TEXT, race TEXT,
        admit_date TEXT, admit_time TEXT, locality TEXT, detail_url TEXT, photo TEXT, details TEXT, search_text TEXT,
        first_seen TEXT, last_seen TEXT, last_detail TEXT, in_custody INTEGER DEFAULT 1, released_seen TEXT);
      CREATE INDEX IF NOT EXISTS roster_custody ON roster(in_custody, admit_date DESC);
      CREATE TABLE IF NOT EXISTS takedowns(bid TEXT PRIMARY KEY, reason TEXT, created TEXT);
      CREATE TABLE IF NOT EXISTS bookings(id TEXT PRIMARY KEY);''')
    rcols = {r[1] for r in db.execute("PRAGMA table_info('roster')")}
    if 'release_date' not in rcols:
        db.execute('ALTER TABLE roster ADD COLUMN release_date TEXT')
    cols = {r[1] for r in db.execute("PRAGMA table_info('bookings')")}
    if 'bid' not in cols and 'name' in cols:
        db.execute('ALTER TABLE bookings ADD COLUMN bid TEXT')
    return db


def link_bookings(db):
    """Attach only unique name, age and booking-date matches; retain suffixes."""
    linked = 0
    if 'bid' not in {r[1] for r in db.execute("PRAGMA table_info('bookings')")}:
        return 0
    roster = db.execute('SELECT bid, name, admit_date, age FROM roster WHERE admit_date IS NOT NULL').fetchall()
    by_key = {}
    for bid, name, d, age in roster:
        by_key.setdefault((name_key(name), age), []).append((bid, d))
    for bid_row in db.execute('SELECT id, name, booked, age FROM bookings WHERE bid IS NULL AND name IS NOT NULL').fetchall():
        rid, name, booked, age = bid_row
        if age is None:
            continue
        cands = by_key.get((name_key(name), age))
        if not cands or not booked:
            continue
        b = datetime.date.fromisoformat(booked)
        exact = [bid for bid, d in cands if d == booked]
        matches = exact or [bid for bid, d in cands if abs((datetime.date.fromisoformat(d) - b).days) <= 1]
        if len(matches) == 1:
            db.execute('UPDATE bookings SET bid=? WHERE id=?', (matches[0], rid)); linked += 1
    return linked


def run():
    db = open_db()
    started = now(); errors = []
    rows, expected = fetch_roster()
    if not rows:
        raise ValueError('Empty roster; review required')
    complete = len(rows) >= (expected or 0)
    seen_bids = set()
    with db:
        for r in rows:
            seen_bids.add(r['bid'])
            db.execute('''INSERT INTO roster(bid,inmate_id,name,age,sex,race,admit_date,detail_url,first_seen,last_seen,in_custody)
                          VALUES(?,?,?,?,?,?,?,?,?,?,1)
                          ON CONFLICT(bid) DO UPDATE SET name=excluded.name,age=excluded.age,sex=excluded.sex,race=excluded.race,
                          admit_date=COALESCE(excluded.admit_date,roster.admit_date),detail_url=excluded.detail_url,
                          last_seen=excluded.last_seen,in_custody=1,released_seen=NULL''',
                       (r['bid'], r['inmate_id'], r['name'], r['age'], r['sex'], r['race'], r['admit_date'],
                        urllib.parse.urljoin(BASE, r['href']), started, started))
        if complete:
            gone = db.execute('SELECT bid FROM roster WHERE in_custody=1 AND last_seen<?', (started,)).fetchall()
            db.execute('UPDATE roster SET in_custody=0, released_seen=? WHERE in_custody=1 AND last_seen<?', (started, started))
        else:
            gone = []
            print('Roster pull incomplete (%d of %s); custody status not changed' % (len(rows), expected), file=sys.stderr)

    # Recent releases: people booked and released between reads still get a photo.
    released = 0
    try:
        rel = fetch_released(days=RELEASED_DAYS)
        with db:
            for r in rel:
                db.execute('''INSERT INTO roster(bid,inmate_id,name,age,sex,race,admit_date,release_date,detail_url,first_seen,last_seen,in_custody,released_seen)
                              VALUES(?,?,?,?,?,?,?,?,?,?,?,0,?)
                              ON CONFLICT(bid) DO UPDATE SET release_date=excluded.release_date, in_custody=0,
                              released_seen=COALESCE(roster.released_seen,excluded.released_seen), detail_url=excluded.detail_url''',
                           (r['bid'], r['inmate_id'], r['name'], r['age'], r['sex'], r['race'], r['admit_date'], r['release_date'],
                            urllib.parse.urljoin(BASE, r['href']), started, started, started))
        released = len(rel)
    except Exception as e:
        errors.append({'bid': 'released-list', 'error': str(e)}); print('released list: %s' % e, file=sys.stderr)

    # Detail pages: new bookings first, then the stalest in-custody records; recent releases without a photo too.
    cutoff = (datetime.datetime.now(datetime.timezone.utc) - datetime.timedelta(hours=DETAIL_REFRESH_HOURS)).isoformat(timespec='seconds')
    rel_cutoff = (datetime.date.today() - datetime.timedelta(days=RELEASED_DAYS)).isoformat()
    todo = db.execute('''SELECT bid, detail_url, photo FROM roster WHERE
                         ((in_custody=1 AND (last_detail IS NULL OR last_detail<?))
                         OR (in_custody=0 AND release_date>=? AND
                             (last_detail IS NULL OR (photo IS NULL AND last_detail<?))))
                         AND NOT EXISTS(SELECT 1 FROM takedowns t WHERE t.bid=roster.bid)
                         ORDER BY photo IS NOT NULL, last_detail IS NOT NULL, admit_date DESC LIMIT ?''',
                      (cutoff, rel_cutoff, cutoff, DETAIL_BUDGET)).fetchall()
    details = 0; photos = 0
    for bid, url, photo in todo:
        try:
            raw, _ = fetch(url, referer=BASE + 'inmates')
            page = raw.decode('utf8', 'replace')
            dump('detail-' + hashlib.sha1(bid.encode()).hexdigest()[:8] + '.html', page)
            d = parse_detail(page)
            if not photo or not (ROOT / 'photos' / photo).exists():
                try:
                    photo = save_photo(d['photo_src'], bid, url)
                    if photo: photos += 1
                except Exception as e:
                    errors.append({'bid': bid, 'error': 'photo: ' + str(e)})
            f = d['facts']
            search = ' '.join([d['name'], d['locality']] + [c['description'] + ' ' + c['arrestingAgency'] for c in d['charges']])
            with db:
                db.execute('''UPDATE roster SET admit_date=COALESCE(?,admit_date), admit_time=?, locality=?, photo=COALESCE(?,photo),
                              details=?, search_text=?, last_detail=? WHERE bid=?''',
                           (iso_mdy(f.get('Admit Date')), f.get('Admit Time'), d['locality'], photo,
                            json.dumps({'charges': d['charges'], 'facts': f}, ensure_ascii=False), search, now(), bid))
            details += 1
        except Exception as e:
            errors.append({'bid': bid, 'error': str(e)}); print('%s: %s' % (bid, e), file=sys.stderr)

    with db:
        linked = link_bookings(db)
    status = {'parserVersion': PARSER, 'checkedAt': now(), 'listed': len(rows), 'expected': expected, 'complete': complete,
              'released': len(gone), 'recentReleases': released, 'detailsRead': details, 'photosSaved': photos, 'linkedBookings': linked,
              'inCustody': db.execute('SELECT COUNT(*) FROM roster WHERE in_custody=1').fetchone()[0],
              'withPhoto': db.execute('SELECT COUNT(*) FROM roster WHERE in_custody=1 AND photo IS NOT NULL').fetchone()[0],
              'errors': errors[:50]}
    (ROOT / 'roster-status.json').write_text(json.dumps(status, indent=2))
    db.execute('PRAGMA wal_checkpoint(TRUNCATE)'); db.close()
    print(json.dumps({k: v for k, v in status.items() if k != 'errors'}))
    if errors or not complete:
        sys.exit(1)


def cli():
    if '--hide' in sys.argv:
        i = sys.argv.index('--hide'); bid = sys.argv[i + 1]; reason = sys.argv[i + 2] if len(sys.argv) > i + 2 else ''
        db = open_db()
        with db:
            db.execute('INSERT OR REPLACE INTO takedowns VALUES(?,?,?)', (bid, reason, now()))
        print('hidden', bid); return
    if '--unhide' in sys.argv:
        bid = sys.argv[sys.argv.index('--unhide') + 1]
        db = open_db()
        with db:
            db.execute('DELETE FROM takedowns WHERE bid=?', (bid,))
        print('restored', bid); return
    run()


if __name__ == '__main__':
    cli()
