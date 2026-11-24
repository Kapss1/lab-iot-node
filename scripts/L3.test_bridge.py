import importlib.util
import json
from pathlib import Path
import socket
import tempfile
import threading
import unittest
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer

spec=importlib.util.spec_from_file_location('bridge',Path(__file__).resolve().parents[1]/'edge/twin_bridge.py')
b=importlib.util.module_from_spec(spec); spec.loader.exec_module(b)
ACK={'node_id':'ESP32_Nodo02','command_id':'12345678-1234-4234-9234-123456789abc','version':1,'reported_state':{'led':True}}
TOPIC='telecom/lab3/ESP32_Nodo02/ack'

class BridgeTest(unittest.TestCase):
    def setUp(self):
        self.tmp=tempfile.TemporaryDirectory(); self.path=Path(self.tmp.name)/'queue.sqlite3'; self.q=b.AckQueue(self.path)
    def tearDown(self): self.tmp.cleanup()
    def test_validation(self):
        self.assertEqual(b.normalize_ack(TOPIC,json.dumps(ACK).encode(),{'ESP32_Nodo02'}),ACK)
        with self.assertRaises(ValueError): b.normalize_ack('telecom/lab3/ESP32_Nodo03/ack',json.dumps(ACK).encode(),{'ESP32_Nodo02'})
        with self.assertRaises(ValueError): b.normalize_ack(TOPIC,json.dumps(dict(ACK,version=True)).encode(),{'ESP32_Nodo02'})
    def test_duplicate_persistence(self):
        self.q.put(ACK); self.q.put(ACK)
        self.assertEqual(b.AckQueue(self.path).counts(),{0:1})
        self.q.put(dict(ACK,reported_state={'led':False})); self.assertEqual(self.q.counts(),{0:2})
    def test_real_http_outage_then_recovery(self):
        # Reserve a port without listening: the first HTTP call must fail.
        sock=socket.socket(); sock.bind(('127.0.0.1',0)); port=sock.getsockname()[1]
        api=b.API(f'http://127.0.0.1:{port}/api/v1','test',.3)
        self.q.put(ACK); self.assertFalse(b.flush_one(self.q,api)); self.assertEqual(self.q.counts(),{0:1}); sock.close()
        # Reopen SQLite as a restarted bridge; expedite the retry deterministically.
        self.q=b.AckQueue(self.path)
        with self.q.connect() as db: db.execute('UPDATE ack_queue SET next_attempt=0')
        seen=[]
        class Receiver(BaseHTTPRequestHandler):
            def do_POST(self):
                body=json.loads(self.rfile.read(int(self.headers['Content-Length']))); seen.append(body)
                response=json.dumps({'ok':True,'data':{'command_id':body['command_id'],'duplicate':False}}).encode()
                self.send_response(200); self.end_headers(); self.wfile.write(response)
            def log_message(self,*a): pass
        srv=ThreadingHTTPServer(('127.0.0.1',port),Receiver); t=threading.Thread(target=srv.serve_forever,daemon=True); t.start()
        try:
            self.assertTrue(b.flush_one(self.q,api)); self.assertEqual(seen,[ACK]); self.assertEqual(self.q.counts(),{})
        finally: srv.shutdown(); srv.server_close(); t.join()
    def test_wrong_ack_keeps_pending(self):
        self.q.put(ACK)
        class WrongAPI:
            def call(self,*a): return {'command_id':'wrong'}
        self.assertFalse(b.flush_one(self.q,WrongAPI())); self.assertEqual(self.q.counts(),{0:1})
    def test_conflict_kept_for_diagnostics(self):
        from urllib.error import HTTPError
        self.q.put(ACK)
        class ConflictAPI:
            def call(self,*a): raise HTTPError('http://test',409,'conflict',{},None)
        self.assertFalse(b.flush_one(self.q,ConflictAPI())); self.assertEqual(self.q.counts(),{1:1})

if __name__=='__main__': unittest.main(verbosity=2)
