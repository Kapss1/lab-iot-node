<?php
declare(strict_types=1);
final class TwinService {
    public function __construct(private TwinRepository $r) {}
    public function ingest(array $input,string $key): array {
        $a=aggregateValue($input,$key); $hash=fingerprint($a);
        return $this->r->transaction(function() use ($a,$hash) {
            $t=$this->r->twin($a['node_id'],true); requireThat($t['edge_id']===$a['edge_id'],'edge_node_mismatch',403);
            // Unique key also protects different nodes racing to use the same event_id.
            $this->r->exec('INSERT INTO lab3_aggregates(event_id,node_id,edge_id,window_start,window_end,payload,payload_hash) VALUES(?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE event_id=event_id',[$a['event_id'],$a['node_id'],$a['edge_id'],$a['window']['start'],$a['window']['end'],jsonText($a),$hash]);
            $row=$this->r->one('SELECT payload_hash FROM lab3_aggregates WHERE event_id=? FOR UPDATE',[$a['event_id']]);
            requireThat($row['payload_hash']===$hash,'event_id_conflict',409);
            // Always 200 is allowed by Edge; repeated requests return identical ACK.
            return ['event_id'=>$a['event_id']];
        });
    }
    public function desired(string $node,array $body,string $key): array {
        $state=stateValue($body['desired_state']??null); $key=requestKey($key);
        return $this->r->transaction(function() use($node,$state,$key) {
            $t=$this->r->twin($node,true);
            $old=$this->r->one('SELECT * FROM lab3_commands WHERE node_id=? AND request_key=?',[$node,$key]);
            if($old) { requireThat(json_decode($old['desired_state'],true)===$state,'request_key_conflict',409); return $this->r->view($old); }
            $v=(int)$t['desired_version']+1; $id=uuid();
            $this->r->exec("UPDATE lab3_commands SET status='superseded' WHERE node_id=? AND status='pending'",[$node]);
            $this->r->exec('INSERT INTO lab3_commands(command_id,node_id,request_key,version,desired_state) VALUES(?,?,?,?,?)',[$id,$node,$key,$v,jsonText($state)]);
            $this->r->exec("UPDATE lab3_twins SET desired_state=?,desired_version=?,sync_status='pending' WHERE node_id=?",[jsonText($state),$v,$node]);
            return $this->r->view($this->r->command($id));
        });
    }
    public function confirm(array $body): array {
        $a=confirmationValue($body); $hash=fingerprint($a);
        return $this->r->transaction(function() use($a,$hash) {
            $t=$this->r->twin($a['node_id'],true); $c=$this->r->command($a['command_id']);
            requireThat($c['node_id']===$a['node_id'] && (int)$c['version']===$a['version'],'confirmation_mismatch',409);
            $old=$this->r->one('SELECT payload_hash FROM lab3_confirmations WHERE command_id=?',[$a['command_id']]);
            if ($old) { requireThat($old['payload_hash']===$hash,'confirmation_conflict',409); return ['command_id'=>$a['command_id'],'duplicate'=>true,'twin'=>$this->r->view($t)]; }
            $this->r->exec('INSERT INTO lab3_confirmations(command_id,payload,payload_hash) VALUES(?,?,?)',[$a['command_id'],jsonText($a),$hash]);
            $current=$a['version']===(int)$t['desired_version'];
            $matches=json_decode($c['desired_state'],true)===$a['reported_state'];
            // Convergence means latest desired version was observed matching; never assign it to superseded or mismatched commands.
            $this->r->exec("UPDATE lab3_commands SET confirmed_at=UTC_TIMESTAMP(6),status=IF(status='superseded','superseded','confirmed'),convergence_ms=IF(?,GREATEST(0,TIMESTAMPDIFF(MICROSECOND,created_at,UTC_TIMESTAMP(6))/1000.0),NULL) WHERE command_id=?",[$current && $matches ? 1:0,$a['command_id']]);
            // Ignore older confirmations for the current twin, even when arriving late after reconnect.
            if ($current) $this->r->exec('UPDATE lab3_twins SET reported_state=?,reported_version=?,sync_status=?,last_report_at=UTC_TIMESTAMP(6) WHERE node_id=?',[jsonText($a['reported_state']),$a['version'],$matches?'synced':'diverged',$a['node_id']]);
            return ['command_id'=>$a['command_id'],'duplicate'=>false,'twin'=>$this->r->view($this->r->twin($a['node_id']))];
        });
    }
    public function listTwins(): array { return array_map(fn($x)=>$this->twinView($x),$this->r->all('SELECT * FROM lab3_twins ORDER BY node_id')); }
    private function twinView(array $t): array { $v=$this->r->view($t); $last=$this->r->one('SELECT MAX(window_end) AS last_window_end FROM lab3_aggregates WHERE node_id=?',[$t['node_id']]); $v['last_window_end']=$last['last_window_end']===null?null:(int)$last['last_window_end']; return $v; }
    public function getTwin(string $node): array { return $this->twinView($this->r->twin($node)); }
    public function pending(string $edge): array {
        requireThat(preg_match('/\A[A-Za-z0-9_-]{1,64}\z/D',$edge)===1,'invalid_edge_id');
        return array_map(fn($x)=>$this->r->view($x),$this->r->all("SELECT c.* FROM lab3_commands c JOIN lab3_twins t ON t.node_id=c.node_id WHERE c.status='pending' AND t.edge_id=? AND c.version=t.desired_version ORDER BY c.created_at",[$edge]));
    }
    public function history(string $node,int $limit): array { $this->r->twin($node); return array_map(fn($x)=>$this->r->view($x),$this->r->all('SELECT * FROM lab3_commands WHERE node_id=? ORDER BY version DESC LIMIT '.$limit,[$node])); }
    public function aggregates(string $node,int $limit): array { $this->r->twin($node); return array_map(fn($x)=>$this->r->view($x),$this->r->all('SELECT * FROM lab3_aggregates WHERE node_id=? ORDER BY window_end DESC,event_id LIMIT '.$limit,[$node])); }
    public function metrics(): array {
        $m=$this->r->one("SELECT COUNT(*) AS total_commands, COALESCE(SUM(status='pending'),0) AS pending_commands, COALESCE(SUM(status='confirmed'),0) AS confirmed_commands, COALESCE(SUM(status='superseded'),0) AS superseded_commands, COUNT(convergence_ms) AS converged_commands, AVG(convergence_ms) AS mean_convergence_ms, MAX(convergence_ms) AS max_convergence_ms FROM lab3_commands");
        $m['unique_aggregates']=$this->r->one('SELECT COUNT(*) AS n FROM lab3_aggregates')['n'];
        foreach($m as $k=>$v) if($v!==null) $m[$k]=str_ends_with($k,'_ms')?(float)$v:(int)$v; return $m;
    }
}
