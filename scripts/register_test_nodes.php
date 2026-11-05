
<?php

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../src/Repositories/DeviceRepository.php';

$pdo = getPDO();
$repo = new DeviceRepository($pdo);

$nodes = [
    'ESP32_Nodo02',
    'ESP32_Nodo03'
];

foreach ($nodes as $nodeId) {

    if ($repo->findByNodeId($nodeId)) {
        echo "$nodeId ya existe.\n";
        continue;
    }

    $token = bin2hex(random_bytes(32));
    $hash = password_hash($token, PASSWORD_DEFAULT);

    $id = $repo->create(
        $nodeId,
        "Nodo de prueba $nodeId",
        $hash
    );

    echo "Registrado: $nodeId | ID: $id\n";
    echo "TOKEN REST: $token\n";
    echo "Guarda este token en privado.\n\n";
}
