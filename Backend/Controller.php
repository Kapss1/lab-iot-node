<?php
declare(strict_types=1);
final class Controller {
    public function __construct(private TwinService $s, private array $cfg) {}
    private function auth(string $role): void {
        $expected=$this->cfg[$role==='edge'?'EDGE_BACKEND_TOKEN':'DASHBOARD_TOKEN'] ?? null;
        requireThat(is_string($expected) && $expected!=='','server_token_not_configured',503);
        requireThat(hash_equals('Bearer '.$expected,$_SERVER['HTTP_AUTHORIZATION']??$_SERVER['REDIRECT_HTTP_AUTHORIZATION']??''),'unauthorized',401);
    }
    private function body(): array {
        $raw=file_get_contents('php://input',false,null,0,65537); requireThat($raw!==false && strlen($raw)<=65536,'body_too_large',413);
        try { $b=json_decode($raw,true,64,JSON_THROW_ON_ERROR); } catch(JsonException) { throw new ApiError(400,'invalid_json'); }
        requireThat(is_array($b)&&!array_is_list($b),'expected_json_object'); return $b;
    }
    private function limit(): int { $n=filter_var($_GET['limit']??50,FILTER_VALIDATE_INT); requireThat($n!==false && $n>=1 && $n<=200,'invalid_limit'); return $n; }
    public function dispatch(string $method,string $path): array {
        $base='/api/v1'; $key=$_SERVER['HTTP_IDEMPOTENCY_KEY']??'';
        if($method==='GET' && $path===$base.'/health') return ['status'=>'ok'];
        if($path===$base.'/edge/ingest' && $method==='POST') { $this->auth('edge'); return $this->s->ingest($this->body(),$key); }
        if($path===$base.'/edge/commands' && $method==='GET') { $this->auth('edge'); return $this->s->pending((string)($_GET['edge_id']??'')); }
        if($path===$base.'/edge/confirmations' && $method==='POST') { $this->auth('edge'); return $this->s->confirm($this->body()); }
        $this->auth('dashboard');
        if($method==='GET' && $path===$base.'/twins') return $this->s->listTwins();
        if($method==='GET' && $path===$base.'/metrics') return $this->s->metrics();
        if(preg_match('~^/api/v1/twins/([A-Za-z0-9_-]{1,50})(?:/(desired|commands|aggregates))?$~D',$path,$m)) {
            $node=$m[1]; $sub=$m[2]??'';
            if($method==='GET' && $sub==='') return $this->s->getTwin($node);
            if($method==='PUT' && $sub==='desired') return $this->s->desired($node,$this->body(),$key);
            if($method==='GET' && $sub==='commands') return $this->s->history($node,$this->limit());
            if($method==='GET' && $sub==='aggregates') return $this->s->aggregates($node,$this->limit());
        }
        throw new ApiError(404,'route_not_found');
    }
}
