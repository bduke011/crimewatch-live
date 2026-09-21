"""Scheduled private inbox importer. Does not scrape or contact the portal."""
import fcntl
import json
from pathlib import Path
import sys
from court_import import run

base=Path(__file__).parent
with (base/'court-import.lock').open('w') as lock:
    try: fcntl.flock(lock,fcntl.LOCK_EX|fcntl.LOCK_NB)
    except BlockingIOError: sys.exit(0)
    status=run(base/'data')
    print(json.dumps(status))
    sys.exit(1 if status['errors'] else 0)
