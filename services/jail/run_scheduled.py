import os,sys,fcntl,datetime,json,subprocess
from pathlib import Path
base=Path(__file__).parent
sys.path.insert(0,str(base/'vendor'))
os.environ['CW_JAIL_DIR']=str(base/'data')
from zoneinfo import ZoneInfo
lock=open(base/'schedule.lock','w')
try:fcntl.flock(lock,fcntl.LOCK_EX|fcntl.LOCK_NB)
except BlockingIOError:sys.exit(0)
now=datetime.datetime.now(ZoneInfo('America/Chicago'));hour=12 if now.hour>=12 else 5 if now.hour>=5 else 0
slot=now.strftime('%Y-%m-%d')+'T'+str(hour)
flag=base/'last-slot'
status=base/'data'/'status.json'
version=json.loads(status.read_text()).get('parserVersion') if status.exists() else None
if flag.exists() and flag.read_text()==slot and version==2 and '--force' not in sys.argv:sys.exit(0)
import import_jail
try:import_jail.run();flag.write_text(slot)
except BaseException as e:
 if isinstance(e,SystemExit) and e.code in (None,0):flag.write_text(slot)
 else:raise
