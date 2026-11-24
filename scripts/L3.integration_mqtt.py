"""Live test on an isolated lab: PHP + MariaDB + broker + bridge. Starts/stops virtual ESP32s.
Do not run with real hardware or another simulator on the same command topics.
"""
import argparse
import json
import os
from pathlib import Path
import subprocess
import sys
import tempfile
import time
from integration_api import desired,twin,request

ROOT=Path(__file__).resolve().parents[1]
NODES=['ESP32_ArequipaSimu','ESP32_Nodo02','ESP32_Nodo03']

def wait_synced(command,timeout=35):
    deadline=time.monotonic()+timeout
    while time.monotonic()<deadline:
        t=twin(command['node_id'])
        if t['sync_status']=='synced' and t['reported_version']==command['version'] and t['reported_state']==command['desired_state']:
            history=request('/twins/'+command['node_id']+'/commands'); c=next(x for x in history if x['command_id']==command['command_id'])
            assert c['convergence_ms'] is not None
            print('PASS MQTT convergence',command['node_id'],c['convergence_ms'],'ms',flush=True); return
        time.sleep(.5)
    raise AssertionError('convergence timeout: '+command['command_id'])

def stop(proc):
    if proc is not None and proc.poll() is None:
        proc.terminate()
        try: proc.wait(timeout=5)
        except subprocess.TimeoutExpired: proc.kill(); proc.wait()

def main():
    p=argparse.ArgumentParser(); p.add_argument('--config',required=True); p.add_argument('--offline-seconds',type=int,default=12); args=p.parse_args()
    assert args.offline_seconds>=1
    with tempfile.TemporaryDirectory() as temp:
        db=str(Path(temp)/'devices.sqlite3'); bridge_db=str(Path(temp)/'bridge.sqlite3')
        def sim(): return subprocess.Popen([sys.executable,str(ROOT/'tests/device_simulator.py'),'--config',args.config,'--database',db])
        bridge=subprocess.Popen([sys.executable,str(ROOT/'edge/twin_bridge.py'),'--config',args.config,'--database',bridge_db]); device=None
        try:
            device=sim(); time.sleep(3)
            for n in NODES: wait_synced(desired(True,node=n))
            stop(device); device=None
            cmd=desired(False,node=NODES[0]); time.sleep(args.offline_seconds)
            assert twin(NODES[0])['sync_status']=='pending'
            print('PASS pending during device outage',args.offline_seconds,'s',flush=True)
            device=sim(); wait_synced(cmd)
            print('PASS simulated device reconnect and durable version',flush=True)
        finally: stop(device); stop(bridge)

if __name__=='__main__': main()
