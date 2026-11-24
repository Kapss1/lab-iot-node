"""Three virtual LEDs, NOT ESP32 firmware. Persistent versions protect against old commands."""
import argparse
import json
import os
import sqlite3
from pathlib import Path
import paho.mqtt.client as mqtt

p=argparse.ArgumentParser(); p.add_argument('--config',required=True); p.add_argument('--database',default='simulated_devices.sqlite3'); args=p.parse_args()
cfg=json.loads(Path(args.config).read_text(encoding='utf-8-sig')); nodes=set(cfg['nodes'])
db=sqlite3.connect(args.database,check_same_thread=False)
db.execute('CREATE TABLE IF NOT EXISTS devices(node TEXT PRIMARY KEY,version INTEGER NOT NULL,command_id TEXT NOT NULL,led INTEGER NOT NULL)'); db.commit()
client=mqtt.Client(mqtt.CallbackAPIVersion.VERSION2,client_id=cfg['mqtt_client_id']+'-virtual-devices',clean_session=False)
if os.getenv('MQTT_USER'): client.username_pw_set(os.environ['MQTT_USER'],os.environ['MQTT_PASSWORD'])
if cfg.get('mqtt_tls'): client.tls_set()
def connected(c,u,f,rc,p):
    if not rc.is_failure: c.subscribe([(f'telecom/lab3/{n}/command',1) for n in sorted(nodes)])
def message(c,u,m):
    try:
        a=json.loads(m.payload); n=a['node_id']; version=a['version']; ident=a['command_id']; led=a['desired_state']['led']
        if n not in nodes or m.topic!=f'telecom/lab3/{n}/command' or m.retain or type(version) is not int or version<=0 or type(led) is not bool: return
        row=db.execute('SELECT version,command_id,led FROM devices WHERE node=?',(n,)).fetchone()
        if row and (version<row[0] or (version==row[0] and (ident!=row[1] or led!=bool(row[2])))): return
        if not row or version>row[0]:
            with db: db.execute('INSERT INTO devices VALUES(?,?,?,?) ON CONFLICT(node) DO UPDATE SET version=excluded.version,command_id=excluded.command_id,led=excluded.led',(n,version,ident,int(led)))
        ack={'node_id':n,'command_id':ident,'version':version,'reported_state':{'led':led}}
        c.publish(f'telecom/lab3/{n}/ack',json.dumps(ack),qos=1,retain=False)
        print('SIMULATED',json.dumps(ack),flush=True)
    except (ValueError,KeyError,TypeError): return
client.on_connect=connected; client.on_message=message
client.connect(cfg['mqtt_host'],cfg['mqtt_port'],30)
try: client.loop_forever()
except KeyboardInterrupt: pass
finally: client.disconnect(); db.close()
