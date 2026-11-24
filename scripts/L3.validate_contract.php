<?php
declare(strict_types=1);
require __DIR__.'/../backend/src/Support.php';
function check(bool $ok,string $label): void { if(!$ok) throw new RuntimeException($label); echo "PASS $label\n"; }
function rejects(callable $fn,string $label): void { try { $fn(); } catch(ApiError $e) { check($e->status===422,$label); return; } throw new RuntimeException('accepted '.$label); }
$fixture=json_decode(file_get_contents(__DIR__.'/fixture_edge_aggregate.json'),true,512,JSON_THROW_ON_ERROR);
check(aggregateValue($fixture,$fixture['event_id'])===$fixture,'original Edge fixture');
$reversed=array_reverse($fixture,true); check(fingerprint($fixture)===fingerprint($reversed),'canonical field order');
rejects(fn()=>aggregateValue($fixture,'wrong'),'invalid idempotency key');
$bad=$fixture; $bad['window']['seconds']=5; rejects(fn()=>aggregateValue($bad,$bad['event_id']),'invalid window');
$bad=$fixture; $bad['stats']['humedad']['max']=101; rejects(fn()=>aggregateValue($bad,$bad['event_id']),'invalid humidity');
rejects(fn()=>stateValue(['led'=>1]),'strict boolean');
check(stateValue(['led'=>false])===['led'=>false],'false LED accepted');
$ack=['node_id'=>$fixture['node_id'],'command_id'=>uuid(),'version'=>1,'reported_state'=>['led'=>true]];
check(confirmationValue($ack)===$ack,'valid device confirmation');
$ack['version']=0; rejects(fn()=>confirmationValue($ack),'invalid version');
if(isset($argv[1])) { $all=json_decode(file_get_contents($argv[1]),true,512,JSON_THROW_ON_ERROR); foreach($all as $a) aggregateValue($a,$a['event_id']); echo 'PASS '.count($all)." original Edge aggregates validated\n"; }

check(apiPath('/lab-iot-node/backend/public/api/v1/twins?limit=3','/lab-iot-node/backend/public/index.php')==='/api/v1/twins','Apache subdirectory route');
check(apiPath('/api/v1/health','/index.php')==='/api/v1/health','root route');
try { apiPath('/wrong','/lab-iot-node/backend/public/index.php'); throw new RuntimeException('accepted invalid route'); } catch(ApiError $e) { check($e->status===404,'invalid route rejected'); }
