<?php
declare(strict_types=1);
final class TwinRepository {
    public function __construct(private PDO $db) {}
    public function all(string $sql, array $args=[]): array { $s=$this->db->prepare($sql); $s->execute($args); return $s->fetchAll(PDO::FETCH_ASSOC); }
    public function one(string $sql, array $args=[]): ?array { return $this->all($sql,$args)[0]??null; }
    public function exec(string $sql, array $args=[]): void { $s=$this->db->prepare($sql); $s->execute($args); }
    public function transaction(callable $f): mixed {
        $this->db->beginTransaction();
        try { $r=$f(); $this->db->commit(); return $r; }
        catch (Throwable $e) { if ($this->db->inTransaction()) $this->db->rollBack(); throw $e; }
    }
    public function twin(string $node, bool $lock=false): array {
        $t=$this->one('SELECT * FROM lab3_twins WHERE node_id=?'.($lock?' FOR UPDATE':''),[$node]);
        requireThat($t!==null,'node_not_found',404); return $t;
    }
    public function view(array $r): array {
        foreach (['desired_state','reported_state','payload'] as $k) if (isset($r[$k])) $r[$k]=json_decode($r[$k],true,512,JSON_THROW_ON_ERROR);
        foreach (['desired_version','reported_version','version','window_start','window_end'] as $k) if (isset($r[$k])) $r[$k]=(int)$r[$k];
        if (isset($r['convergence_ms'])) $r['convergence_ms']=(float)$r['convergence_ms'];
        unset($r['payload_hash'],$r['request_key']); return $r;
    }
    public function command(string $id): array { $r=$this->one('SELECT * FROM lab3_commands WHERE command_id=?',[$id]); requireThat($r!==null,'command_not_found',404); return $r; }
}
