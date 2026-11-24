"""Executable behavioral model; not PHP/MariaDB, MQTT, or physical ESP32 validation.
Uses the production bridge ACK normalization, durable queue, and HTTP retry.
The backend model uses SQLite and a virtual clock for an accelerated outage.
"""
import hashlib
import json
from pathlib import Path
import socket
import sqlite3
import sys
import tempfile
import threading
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
from uuid import UUID

ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ROOT / 'edge'))
from twin_bridge import AckQueue, API, compact, flush_one, normalize_ack

NODES = ['ESP32_ArequipaSimu', 'ESP32_Nodo02', 'ESP32_Nodo03']
EDGE = 'edge-lab-iot-01'

class Model:
    def __init__(self, path):
        self.path = path
        with sqlite3.connect(path) as db:
            db.executescript('''CREATE TABLE IF NOT EXISTS twins(node TEXT PRIMARY KEY, desired INTEGER, reported INTEGER, dv INTEGER DEFAULT 0, rv INTEGER DEFAULT 0, status TEXT DEFAULT 'unknown');
            CREATE TABLE IF NOT EXISTS commands(id TEXT PRIMARY KEY,node TEXT, request_key TEXT, version INTEGER, led INTEGER, status TEXT, created_ms INTEGER, convergence_ms INTEGER, UNIQUE(node,request_key), UNIQUE(node,version));
            CREATE TABLE IF NOT EXISTS confirmations(id TEXT PRIMARY KEY,payload TEXT);
            CREATE TABLE IF NOT EXISTS aggregates(id TEXT PRIMARY KEY,payload TEXT);''')
            db.executemany('INSERT OR IGNORE INTO twins(node) VALUES (?)', [(n,) for n in NODES])
        self.now = 0

    def connect(self):
        db = sqlite3.connect(self.path)
        db.row_factory = sqlite3.Row
        return db

    def twin(self, n):
        with self.connect() as db:
            r = db.execute('SELECT * FROM twins WHERE node=?',(n,)).fetchone()
        def state(x): return None if x is None else {'led': bool(x)}
        return dict(node_id=n,edge_id=EDGE,desired_state=state(r['desired']),reported_state=state(r['reported']),desired_version=r['dv'],reported_version=r['rv'],sync_status=r['status'])

    def desired(self,n,led,key):
        with self.connect() as db:
            old=db.execute('SELECT * FROM commands WHERE node=? AND request_key=?',(n,key)).fetchone()
            if old:
                if old['led'] != led: raise ValueError('409 request_key_conflict')
                return self.command(old)
            v=db.execute('SELECT dv FROM twins WHERE node=?',(n,)).fetchone()[0]+1
            idx=db.execute('SELECT COUNT(*) FROM commands').fetchone()[0]+1
            ident=str(UUID(int=idx,version=4))
            db.execute("UPDATE commands SET status='superseded' WHERE node=? AND status='pending'",(n,))
            db.execute('INSERT INTO commands VALUES(?,?,?,?,?,?,?,NULL)',(ident,n,key,v,led,'pending',self.now))
            db.execute("UPDATE twins SET desired=?,dv=?,status='pending' WHERE node=?",(led,v,n))
            return self.command(db.execute('SELECT * FROM commands WHERE id=?',(ident,)).fetchone())

    @staticmethod
    def command(r):
        return dict(command_id=r['id'],node_id=r['node'],version=r['version'],desired_state={'led':bool(r['led'])},status=r['status'],convergence_ms=r['convergence_ms'])

    def pending(self):
        with self.connect() as db:
            return [self.command(r) for r in db.execute("SELECT * FROM commands WHERE status='pending' ORDER BY version,node")]

    def history(self,n):
        with self.connect() as db:
            return [self.command(r) for r in db.execute('SELECT * FROM commands WHERE node=? ORDER BY version DESC',(n,))]

    def ingest(self,a):
        payload=compact(a)
        with self.connect() as db:
            row=db.execute('SELECT payload FROM aggregates WHERE id=?',(a['event_id'],)).fetchone()
            if row and row[0]!=payload: raise ValueError('409 event_id_conflict')
            db.execute('INSERT OR IGNORE INTO aggregates VALUES (?,?)',(a['event_id'],payload))
        return {'event_id':a['event_id']}

    def confirm(self,a):
        ident=a['command_id']; n=a['node_id']; led=a['reported_state']['led']; payload=compact(a)
        with self.connect() as db:
            c=db.execute('SELECT * FROM commands WHERE id=?',(ident,)).fetchone()
            if not c or c['node']!=n or c['version']!=a['version']: raise ValueError('409 confirmation_mismatch')
            old=db.execute('SELECT payload FROM confirmations WHERE id=?',(ident,)).fetchone()
            if old:
                if old[0]!=payload: raise ValueError('409 confirmation_conflict')
                duplicate=True
            else:
                duplicate=False
                db.execute('INSERT INTO confirmations VALUES (?,?)',(ident,payload))
                current=db.execute('SELECT dv FROM twins WHERE node=?',(n,)).fetchone()[0]==a['version']
                matches=c['led']==led
                ms=self.now-c['created_ms'] if current and matches else None
                db.execute('UPDATE commands SET status=?,convergence_ms=? WHERE id=?',('superseded' if c['status']=='superseded' else 'confirmed',ms,ident))
                if current: db.execute('UPDATE twins SET reported=?,rv=?,status=? WHERE node=?',(led,a['version'],'synced' if matches else 'diverged',n))
        return dict(command_id=ident,duplicate=duplicate,twin=self.twin(n))

    def metrics(self):
        with self.connect() as db:
            agg=db.execute('SELECT COUNT(*) FROM aggregates').fetchone()[0]
            r=db.execute("SELECT COUNT(*) total_commands,SUM(status='pending') pending_commands,SUM(status='confirmed') confirmed_commands,SUM(status='superseded') superseded_commands,COUNT(convergence_ms) converged_commands,AVG(convergence_ms) mean_convergence_ms,MAX(convergence_ms) max_convergence_ms FROM commands").fetchone()
        return dict(unique_aggregates=agg,**dict(r))


def emit(label,value):
    print(label)
    print(json.dumps(value,indent=2,ensure_ascii=False))

def ack(c,led=None):
    a={k:c[k] for k in ('command_id','node_id','version')}
    a['reported_state']=c['desired_state'] if led is None else {'led':led}
    return normalize_ack('telecom/lab3/'+a['node_id']+'/ack',compact(a).encode(),set(NODES))

def conflict(f):
    try: f()
    except ValueError as e:
        assert str(e).startswith('409'); print(str(e)); return
    raise AssertionError('conflict not rejected')


def main():
    print('SIMULACION EJECUTADA | modelo SQLite + reloj virtual; sin PHP/MariaDB/MQTT real')
    with tempfile.TemporaryDirectory() as temp:
        path=Path(temp)/'model.sqlite3'; m=Model(path); n=NODES[1]
        print('\n=== 03 | Ingesta e idempotencia ===')
        a=json.loads((ROOT/'tests/fixture_edge_aggregate.json').read_text())
        for i in range(2): emit(f'POST /api/v1/edge/ingest (modelo), intento {i+1}',m.ingest(a))
        conflict(lambda:m.ingest(dict(a,count=a['count']+1)))
        assert m.metrics()['unique_aggregates']==1
        print('PASS: 2 entregas, 1 agregado persistido')
        print('\n=== 04 | Estado deseado y comando pendiente ===')
        emit('GET /api/v1/twins/'+n+' (modelo)',m.twin(n))
        c=m.desired(n,True,'demo-on'); assert m.desired(n,True,'demo-on')==c
        conflict(lambda:m.desired(n,False,'demo-on'))
        emit('PUT /api/v1/twins/'+n+'/desired (modelo)',c)
        emit('GET twin: comando pendiente',m.twin(n))
        assert m.twin(n)['sync_status']=='pending' and m.pending()==[c]
        print('PASS: mismo comando para la misma clave')
        print('\n=== 05 | ACK y convergencia ===')
        m.now=240
        print('Topico simulado: telecom/lab3/'+n+'/command -> /ack')
        emit('POST /api/v1/edge/confirmations (modelo)',m.confirm(ack(c)))
        assert m.confirm(ack(c))['duplicate'] is True
        conflict(lambda:m.confirm(ack(c,False)))
        emit('GET historial (modelo)',m.history(n)[0])
        assert m.twin(n)['sync_status']=='synced' and m.history(n)[0]['convergence_ms']==240
        print('PASS: ACK duplicado no cambia estado; 240 ms de reloj virtual')
        print('\n=== 06 | Desconexion y reanudacion ===')
        m.now=1000; c=m.desired(n,False,'demo-off'); m.now=121000
        m=Model(path); m.now=121000
        assert m.pending()==[c] and m.twin(n)['sync_status']=='pending'
        emit('Tras 120 s VIRTUALES offline y reapertura de SQLite',m.twin(n))
        qpath=Path(temp)/'bridge.sqlite3'; q=AckQueue(qpath); q.put(ack(c)); q.put(ack(c))
        sock=socket.socket(); sock.bind(('127.0.0.1',0)); port=sock.getsockname()[1]
        api=API('http://127.0.0.1:'+str(port)+'/api/v1','demo',.3)
        assert flush_one(q,api) is False and q.counts()=={0:1}; sock.close()
        print('HTTP local sin receptor: fallo real; ACK persistido:',q.counts())
        q=AckQueue(qpath)
        with q.connect() as db: db.execute('UPDATE ack_queue SET next_attempt=0')
        class Receiver(BaseHTTPRequestHandler):
            def do_POST(self):
                assert self.path=='/api/v1/edge/confirmations'
                body=json.loads(self.rfile.read(int(self.headers['Content-Length'])))
                response=compact({'ok':True,'data':m.confirm(body)}).encode()
                self.send_response(200); self.end_headers(); self.wfile.write(response)
            def log_message(self,*args): pass
        srv=ThreadingHTTPServer(('127.0.0.1',port),Receiver); t=threading.Thread(target=srv.serve_forever,daemon=True); t.start()
        try:
            m.now=121350; assert flush_one(q,api) is True and q.counts()=={}
        finally: srv.shutdown(); srv.server_close(); t.join()
        print('HTTP local restablecido (receptor simulado); cola ACK:',q.counts())
        emit('GET twin recuperado (modelo)',m.twin(n))
        assert m.twin(n)['sync_status']=='synced' and m.history(n)[0]['convergence_ms']==120350
        print('PASS: convergencia virtual 120350 ms; cola vacia')
        print('\n=== 07 | Versiones, divergencia y tres nodos ===')
        old=m.desired(n,True,'old'); new=m.desired(n,False,'new')
        assert m.confirm(ack(old))['twin']['sync_status']=='pending'; print('PASS: ACK tardio no modifica la version vigente')
        m.now+=100; assert m.confirm(ack(new))['twin']['sync_status']=='synced'
        d=m.desired(n,True,'diverge'); assert m.confirm(ack(d,False))['twin']['sync_status']=='diverged'
        print('PASS: ACK con LED diferente -> diverged')
        for node in NODES:
            c=m.desired(node,True,'all-on'); m.now+=100
            assert m.confirm(ack(c))['twin']['sync_status']=='synced'
            v=m.twin(node); print(node,compact({'desired':v['desired_state'],'reported':v['reported_state'],'version':v['reported_version'],'sync_status':v['sync_status']}))
        print('PASS: tres nodos virtuales sincronizados')
        print('\n=== 08 | Metricas de convergencia ===')
        emit('GET /api/v1/metrics (modelo, milisegundos virtuales)',m.metrics())
        print('PASS: escenario completo. No equivale a validacion de hardware.')

if __name__=='__main__': main()
