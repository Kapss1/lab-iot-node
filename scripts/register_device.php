
<?php

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../src/Repositories/DeviceRepository.php';

try {
    $pdo = getPDO();

    $nodeId = 'ESP32_ArequipaSimu';

    $token = bin2hex(random_bytes(32));
    $hash = password_hash($token, PASSWORD_DEFAULT);

    $devices = new DeviceRepository($pdo);
    if ($devices->findByNodeId($nodeId)) {
        $stmt = $pdo->prepare(
            "UPDATE devices SET token_hash = :hash WHERE node_id = :node_id"
        );
        $stmt->execute(['hash' => $hash, 'node_id' => $nodeId]);
    } else {
        // Permite arrancar desde el esquema vacio, sin tokens de ejemplo.
        $devices->create($nodeId, 'ESP32 Simulador Arequipa', $hash);
    }

    echo "TOKEN REST GENERADO\n";
    echo "Node ID: $nodeId\n";
    echo "Nuevo token: $token\n";
    echo "GUARDA ESTE TOKEN.\n";

} catch (Throwable $e) {
    fwrite(STDERR, "Error al actualizar el token.\n");
    exit(1);
}
