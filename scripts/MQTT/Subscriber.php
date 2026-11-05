
<?php

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../Repositories/DeviceRepository.php';
require_once __DIR__ . '/../Repositories/TelemetryRepository.php';
require_once __DIR__ . '/../Services/TelemetryService.php';
require_once __DIR__ . '/../Utils/Logger.php';
use PhpMqtt\Client\MqttClient;
use PhpMqtt\Client\ConnectionSettings;

$host = '127.0.0.1';
$port = 1883;

$username = getenv('MQTT_USERNAME');
$password = getenv('MQTT_PASSWORD');

if (!$username || !$password) {
    fwrite(STDERR, "Faltan credenciales MQTT.\n");
    exit(1);
}

try {
    $pdo = getPDO();

    $devices = new DeviceRepository($pdo);
    $telemetry = new TelemetryRepository($pdo);

    // Exactamente el mismo servicio que utiliza REST
    $service = new TelemetryService(
        $devices,
        $telemetry
    );

    $mqtt = new MqttClient(
        $host,
        $port,
        'iot-backend-subscriber'
    );

    $settings = (new ConnectionSettings())
        ->setUsername($username)
        ->setPassword($password)
        ->setKeepAliveInterval(60);

    $mqtt->connect($settings, true);

    echo "Conectado a Mosquitto.\n";

    // Primero probaremos el nodo existente.
    $topicFilter = 'telecom/lab1/+/telemetry';

    $mqtt->subscribe(
        $topicFilter,
        function (
            string $topic,
            string $message
        ) use ($service) {

            try {
                $data = json_decode(
                    $message,
                    true,
                    512,
                    JSON_THROW_ON_ERROR
                );

                if (!is_array($data)) {
                    throw new InvalidArgumentException(
                        'JSON invalido'
                    );
                }

                $parts = explode('/', $topic);

                if (
                    count($parts) !== 4 ||
                    $parts[0] !== 'telecom' ||
                    $parts[1] !== 'lab1' ||
                    $parts[3] !== 'telemetry' ||
                    ($data['node_id'] ?? null) !== $parts[2]
                ) {
                    throw new InvalidArgumentException(
                        'Topic y node_id no coinciden'
                    );
                }

                $result = $service->ingest($data);

                echo json_encode([
                    'node_id' => $data['node_id'],
                    'telemetry_id' => $result['telemetry_id'],
                    'duplicate' => $result['duplicate']
                ], JSON_UNESCAPED_UNICODE) . PHP_EOL;

           } catch (Throwable $e) {
    try {
        Logger::write(
            'ERROR',
            'MQTT',
            get_class($e) . ': ' . $e->getMessage()
        );
    } catch (Throwable $logError) {
        error_log('No se pudo registrar el error MQTT');
    }

    echo "Mensaje rechazado: "
        . $e->getMessage()
        . PHP_EOL;
}
        },
        0
    );

    echo "Suscrito a: $topicFilter\n";
    echo "Esperando telemetria...\n";

    $mqtt->loop(true);

    $mqtt->disconnect();

} catch (Throwable $e) {
    fwrite(
        STDERR,
        "Error MQTT: " . $e->getMessage() . "\n"
    );
    exit(1);
}
