"""Extract only outbox data from original ZIP and run PHP validation, no source changes."""
import argparse
import json
from pathlib import Path
import sqlite3
import subprocess
import tempfile
import zipfile
p=argparse.ArgumentParser(); p.add_argument('zip'); a=p.parse_args()
with tempfile.TemporaryDirectory() as temp, zipfile.ZipFile(a.zip) as z:
    members=[n for n in z.namelist() if n.endswith('/data/edge_lab_iot.sqlite3')]
    if len(members)!=1: raise SystemExit('Expected exactly one original Edge SQLite database')
    dbpath=Path(temp)/'original.sqlite3'; dbpath.write_bytes(z.read(members[0]))
    with sqlite3.connect(dbpath) as db: rows=[json.loads(r[0]) for r in db.execute('SELECT payload FROM outbox')]
    dest=Path(temp)/'aggregates.json'; dest.write_text(json.dumps(rows))
    subprocess.run(['php',str(Path(__file__).parent/'validate_contract.php'),str(dest)],check=True)
