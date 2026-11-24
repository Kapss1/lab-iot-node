"""Integration against actual PHP/MariaDB. Run against a dedicated fresh lab DB, not production."""
import hashlib
import json
import os
from pathlib import Path
import time
import uuid
from urllib.error import HTTPError
from urllib.request import Request, urlopen

BASE=os.getenv('LAB3_API','http://127.0.0.1/lab-iot-node/backend/public/api/v1')
EDGE=os.environ['EDGE_BACKEND_TOKEN']; DASH=os.environ['DASHBOARD_TOKEN']
NODE='ESP32_Nodo02'

def request(path, method='GET', body=None, key=None, token=DASH, expected=200):
    headers={'Authorization':'Bearer '+token,'Content-Type':'application/json'}
    if key is not None: headers['Idempotency-Key']=key
    req=Request(BASE+path,data=json.dumps(body).encode() if body is not None else None,headers=headers,method=method)
    try:
        with urlopen(req,timeout=5) as r: code=r.status; obj=json.load(r)
    except HTTPError as e: code=e.code; obj=json.load(e)
    assert code==expected, (path,code,obj,expected)
    assert obj['ok']==(expected==200),obj
    return obj.get('data',obj)

def desired(led,key=None,node=NODE):
    return request(f'/twins/{node}/desired','PUT',{'desired_state':{'led':led}},key or uuid.uuid4().hex)

def confirm(cmd, led=None, expected=200):
    obj={k:cmd[k] for k in ('node_id','command_id','version')}; obj['reported_state']=cmd['desired_state'] if led is None else {'led':led}
    return request('/edge/confirmations','POST',obj,token=EDGE,expected=expected)

def twin(node=NODE): return request('/twins/'+node)

def run():
    assert len(request('/twins'))==3
    request('/twins',token='invalid-token',expected=401)
    before=request('/metrics')['unique_aggregates']
    a=json.loads((Path(__file__).parent/'fixture_edge_aggregate.json').read_text()); a['event_id']=hashlib.sha256(uuid.uuid4().bytes).hexdigest()
    for _ in range(2): assert request('/edge/ingest','POST',a,a['event_id'],EDGE)['event_id']==a['event_id']
    assert request('/metrics')['unique_aggregates']==before+1
    bad=dict(a,count=a['count']+1); request('/edge/ingest','POST',bad,a['event_id'],EDGE,409)
    print('PASS aggregate idempotency + conflict + auth')
    key=uuid.uuid4().hex; first=desired(True,key); repeat=desired(True,key)
    assert first['command_id']==repeat['command_id']
    request('/twins/'+NODE+'/desired','PUT',{'desired_state':{'led':False}},key,expected=409)
    assert twin()['sync_status']=='pending'
    assert first['command_id'] in [x['command_id'] for x in request('/edge/commands?edge_id=edge-lab-iot-01',token=EDGE)]
    time.sleep(.02); result=confirm(first); assert result['twin']['sync_status']=='synced'
    assert confirm(first)['duplicate'] is True
    confirm(first,False,409)
    history=request('/twins/'+NODE+'/commands'); old=next(c for c in history if c['command_id']==first['command_id']); assert old['convergence_ms']>=0
    print('PASS desired idempotency, pending, confirmed, convergence_ms',old['convergence_ms'])
    a=desired(False); b=desired(True); late=confirm(a); assert late['twin']['desired_version']==b['version'] and late['twin']['sync_status']=='pending'
    assert confirm(b)['twin']['sync_status']=='synced'
    assert twin()['reported_version']==b['version']
    c=desired(True); assert confirm(c,False)['twin']['sync_status']=='diverged'
    d=desired(False); assert confirm(d)['twin']['sync_status']=='synced'
    print('PASS superseded, late ACK, divergence and resynchronization')
    for node in ['ESP32_ArequipaSimu','ESP32_Nodo03']:
        cmd=desired(True,node=node); assert confirm(cmd)['twin']['sync_status']=='synced'
    print('PASS independent 3 nodes; confirmations are synthetic HTTP, not MQTT')
    print(json.dumps(request('/metrics'),indent=2))

if __name__=='__main__': run()
