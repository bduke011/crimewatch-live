import urllib.request,urllib.parse,json,pathlib
base='https://crimewatch.live/fbi-extra-api.php?'
def get(p):return json.load(urllib.request.urlopen(urllib.request.Request(base+urllib.parse.urlencode(p),headers={'User-Agent':'CrimeWatch/1.1 verification'}),timeout=90))
c=get({'action':'catalog'});results=[]
for m in c:
 d=get({'table':m['table'],'year':m['lastYear']});assert d['total']>0;assert all(str(r[d['columns'].index('year' if 'year' in d['columns'] else 'data_year')])==str(m['lastYear']) for r in d['rows']);results.append({'table':m['table'],'totalRows':m['rows'],'yearFilterPassed':True});print(m['id'],m['rows'],d['total'])
pathlib.Path('outputs/fbi-archive/additional/verification.json').write_text(json.dumps(results,indent=2));print('PASS',len(c),'Texas collections',sum(m['rows'] for m in c),'rows')
