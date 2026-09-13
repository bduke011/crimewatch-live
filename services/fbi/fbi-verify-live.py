import urllib.request,urllib.parse,json,concurrent.futures,datetime,pathlib,time
base='https://crimewatch.live/fbi-api.php?'
def get(p):
 return json.load(urllib.request.urlopen(urllib.request.Request(base+urllib.parse.urlencode({**p,'verify':'20260912-final'}),headers={'User-Agent':'CrimeWatch/1.1 verification'}),timeout=120))
c=get({'action':'catalog'});assert len(c['years'])==30
pathlib.Path('outputs/fbi-archive/hosted-catalog.json').write_text(json.dumps(c,indent=2))
def check(m):
 d=get({'year':m['year']});assert d['total']==m['incidents'];assert len(d['incidents'])==min(50,d['total'])
 for r in d['incidents']:
  datetime.date.fromisoformat(r['date']);assert 'lat' not in r;assert r['offenses']
 return {'year':m['year'],'incidents':d['total'],'checked':True}
with concurrent.futures.ThreadPoolExecutor(max_workers=3) as ex:r=list(ex.map(check,c['years']))
a=get({'action':'agencies','year':2026});houston=next(x for x in a['agencies'] if x['ori']=='TXHPD0000');d=get({'year':2026,'agency':houston['id']});assert d['total']==69557
p2=get({'year':2026,'agency':houston['id'],'page':2});assert not set(x['id'] for x in d['incidents'])&set(x['id'] for x in p2['incidents'])
f=get({'year':2026,'agency':houston['id'],'offense':'13A'});assert all(any(o['code']=='13A' for o in x['offenses']) for x in f['incidents'])
pathlib.Path('outputs/fbi-archive/verification.json').write_text(json.dumps({'years':r,'houstonFilter':True,'pagination':True,'offenseFilter':True},indent=2));print('PASS all 30 years; Houston 69,557; paging and offense filters');print('Totals',sum(x['incidents'] for x in c['years']),sum(x['offenses'] for x in c['years']),sum(x['databaseBytes'] for x in c['years']))
