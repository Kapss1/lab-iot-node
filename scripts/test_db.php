
<?php

require_once __DIR__ . '/../config/database.php';

header('Content-Type: application/json; charset=utf-8');

try {
    $pdo = getPDO();

    $stmt = $pdo->query("SELECT VERSION() AS version");
    $server = $stmt->fetch();

    $stmt = $pdo->query("SELECT COUNT(*) AS total FROM devices");
    $devices = $stmt->fetch();

    echo json_encode([
        'ok' => true,
        'message' => 'Conexion PDO exitosa',
        'database' => 'iot_telemetry',
        'mariadb_version' => $server['version'],
        'devices' => (int) $devices['total']
    ], JSON_PRETTY_PRINT);

} catch (PDOException $e) {
    http_response_code(500);

    echo json_encode([
        'ok' => false,
        'message' => 'Error de conexion con MariaDB'
    ]);
}
