
<?php

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../src/Repositories/DeviceRepository.php';
require_once __DIR__ . '/../src/Repositories/TelemetryRepository.php';
require_once __DIR__ . '/../src/Services/TelemetryService.php';

try {
    $pdo = getPDO();

    $service = new TelemetryService(
        new DeviceRepository($pdo),
        new TelemetryRepository($pdo)
    );

    $data = [
        'node_id' => 'ESP32_ArequipaSimu',
        'ts' => time(),
        'rssi' => -55,
        'seq' => random_int(100000000, 2000000000),
        'value' => [
            'temperatura' => 25.6,
            'humedad' => 68.2,
            'lux' => 350,
            'particulas' => 120.5
        ]
    ];

    echo "PRIMERA INSERCION:\n";
    $first = $service->ingest($data);
    print_r($first);

    echo "\nSEGUNDA INSERCION (MISMO SEQ):\n";
    $second = $service->ingest($data);
    print_r($second);

    if (
        $first['duplicate'] === false &&
        $second['duplicate'] === true &&
        $first['telemetry_id'] === $second['telemetry_id']
    ) {
        echo "\nPRUEBA EXITOSA\n";
    } else {
        echo "\nPRUEBA FALLIDA\n";
        exit(1);
    }

} catch (Throwable $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
    exit(1);
}
