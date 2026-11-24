<?php
declare(strict_types=1);
foreach(['Support','TwinRepository','TwinService','Controller'] as $f) require __DIR__.'/../src/'.$f.'.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
try {
    $configFile=__DIR__.'/../config.local.php';
    $cfg=is_file($configFile) ? require $configFile : [];
    $origin=$cfg['DASHBOARD_ORIGIN'] ?? '*';
    if ($origin === '*' || ($origin !== '' && ($_SERVER['HTTP_ORIGIN'] ?? '') === $origin)) {
        header('Access-Control-Allow-Origin: '.$origin);
        if ($origin !== '*') header('Vary: Origin');
        header('Access-Control-Allow-Methods: GET, PUT, POST, OPTIONS');
        header('Access-Control-Allow-Headers: Authorization, Content-Type, Idempotency-Key');
    }
    $method=$_SERVER['REQUEST_METHOD'] ?? 'GET';
    // Apache may install this module under any htdocs subdirectory.
    $path=apiPath($_SERVER['REQUEST_URI'] ?? '/',$_SERVER['SCRIPT_NAME'] ?? '/index.php');
    if ($method==='OPTIONS') { http_response_code(204); exit; }
    requireThat(is_file($configFile),'copy_config_example_to_config_local',503);
    foreach (['EDGE_BACKEND_TOKEN','DASHBOARD_TOKEN'] as $key) {
        requireThat(is_string($cfg[$key]??null) && strlen($cfg[$key])>=16 && !str_starts_with($cfg[$key],'CHANGE_'),'configure_local_tokens',503);
    }
    $db=new PDO($cfg['DB_DSN'],$cfg['DB_USER'],$cfg['DB_PASSWORD'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_EMULATE_PREPARES=>false]);
    $db->exec("SET time_zone='+00:00'");
    $controller=new Controller(new TwinService(new TwinRepository($db)),$cfg);
    echo jsonText(['ok'=>true,'data'=>$controller->dispatch($method,$path)]);
} catch(ApiError $e) { http_response_code($e->status); echo jsonText(['ok'=>false,'error'=>$e->getMessage()]); }
catch(Throwable $e) { error_log((string)$e); http_response_code(503); echo jsonText(['ok'=>false,'error'=>'backend_unavailable']); }
