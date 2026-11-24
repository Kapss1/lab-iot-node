<?php
declare(strict_types=1);
final class ApiError extends RuntimeException {
    public function __construct(public int $status, string $message) { parent::__construct($message); }
}
function requireThat(bool $condition, string $message, int $status=422): void {
    if (!$condition) throw new ApiError($status, $message);
}
function jsonText(mixed $v): string { return json_encode($v, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE); }
function canonical(mixed $v): mixed {
    if (is_array($v)) { if (!array_is_list($v)) ksort($v, SORT_STRING); foreach ($v as &$item) $item=canonical($item); unset($item); }
    return $v;
}
function fingerprint(array $v): string { return hash('sha256', jsonText(canonical($v))); }
function uuid(): string {
    $h=bin2hex(random_bytes(16)); $h[12]='4'; $h[16]=dechex((hexdec($h[16]) & 3) | 8);
    return substr($h,0,8).'-'.substr($h,8,4).'-'.substr($h,12,4).'-'.substr($h,16,4).'-'.substr($h,20);
}
function stateValue(mixed $v): array {
    requireThat(is_array($v) && count($v)===1 && array_key_exists('led',$v) && is_bool($v['led']), 'state_must_be_led_boolean');
    return ['led'=>$v['led']];
}
function nodeId(mixed $v): string {
    requireThat(is_string($v) && preg_match('/\A[A-Za-z0-9_-]{1,50}\z/D',$v)===1,'invalid_node_id'); return $v;
}
function requestKey(string $v): string {
    requireThat(preg_match('/\A[A-Za-z0-9_.:-]{1,128}\z/D',$v)===1,'invalid_idempotency_key'); return $v;
}
function aggregateValue(array $v, string $key): array {
    requireThat(($v['type']??null)==='aggregate' && is_string($v['event_id']??null) && preg_match('/\A[a-f0-9]{64}\z/D',$v['event_id'])===1 && $key===$v['event_id'],'invalid_event_id_or_key');
    nodeId($v['node_id']??null);
    requireThat(is_string($v['edge_id']??null) && preg_match('/\A[A-Za-z0-9_-]{1,64}\z/D',$v['edge_id'])===1,'invalid_edge_id');
    $w=$v['window']??null;
    requireThat(is_array($w) && ($w['clock']??null)==='edge_received_at','invalid_window');
    foreach (['start','end','seconds','slide_seconds'] as $k) requireThat(is_int($w[$k]??null),'invalid_window_'.$k);
    requireThat($w['start']>=0 && $w['end']>$w['start'] && $w['end']-$w['start']===$w['seconds'] && $w['slide_seconds']>=1 && $w['slide_seconds']<=$w['seconds'] && $w['seconds']<=3600,'invalid_window_range');
    foreach (['count','seq_min','seq_max','last_rssi','last_sample_ts'] as $k) requireThat(is_int($v[$k]??null),'invalid_'.$k);
    requireThat($v['count']>0 && $v['seq_min']>=0 && $v['seq_max']>=$v['seq_min'] && $v['last_rssi']>=-120 && $v['last_rssi']<=0 && $v['last_sample_ts']>0,'invalid_aggregate_range');
    foreach (['temperatura','humedad','lux','particulas'] as $field) {
        $s=$v['stats'][$field]??null; requireThat(is_array($s),'missing_stats_'.$field);
        foreach (['min','max','mean'] as $k) requireThat((is_int($s[$k]??null)||is_float($s[$k]??null)) && is_finite((float)$s[$k]),'invalid_stat_'.$field);
        requireThat($s['min']<=$s['max'] && $s['mean'] >= $s['min']-1e-9 && $s['mean'] <= $s['max']+1e-9,'invalid_stat_order');
        if ($field==='humedad') requireThat($s['min']>=0 && $s['max']<=100,'invalid_humidity');
        if (in_array($field,['lux','particulas'],true)) requireThat($s['min']>=0,'invalid_nonnegative_stat');
    }
    return $v;
}
function confirmationValue(array $v): array {
    nodeId($v['node_id']??null);
    requireThat(is_string($v['command_id']??null) && preg_match('/\A[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}\z/D',$v['command_id'])===1,'invalid_command_id');
    requireThat(is_int($v['version']??null) && $v['version']>0,'invalid_version');
    return ['node_id'=>$v['node_id'],'command_id'=>$v['command_id'],'version'=>$v['version'],'reported_state'=>stateValue($v['reported_state']??null)];
}

function apiPath(string $uri,string $script): string {
    $path=parse_url($uri,PHP_URL_PATH);
    requireThat(is_string($path),'invalid_path',400);
    $prefix=rtrim(str_replace('\\','/',dirname($script)),'/');
    if ($prefix!=='' && str_starts_with($path,$prefix.'/')) $path=substr($path,strlen($prefix));
    requireThat(preg_match('~^/api/v1(?:/|$)~D',$path)===1,'route_not_found',404);
    return $path;
}
