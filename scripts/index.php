
<?php

require_once __DIR__ . '/../config/database.php';

require_once __DIR__ . '/../src/Repositories/DeviceRepository.php';
require_once __DIR__ . '/../src/Repositories/TelemetryRepository.php';
require_once __DIR__ . '/../src/Repositories/ConfigRepository.php';
require_once __DIR__ . '/../src/Services/ConfigService.php';
require_once __DIR__ . '/../src/Controllers/ConfigController.php';
require_once __DIR__ . '/../src/Services/TelemetryService.php';
require_once __DIR__ . '/../src/Controllers/DeviceController.php';
require_once __DIR__ . '/../src/Controllers/TelemetryController.php';
require_once __DIR__ . '/../src/Repositories/EventRepository.php';
require_once __DIR__ . '/../src/Controllers/EventController.php';

header('Content-Type: application/json; charset=utf-8');

try {
    $pdo = getPDO();

    $devices = new DeviceRepository($pdo);
    $telemetry = new TelemetryRepository($pdo);

    $service = new TelemetryService(
        $devices,
        $telemetry
    );

    $path = parse_url(
        $_SERVER['REQUEST_URI'],
        PHP_URL_PATH
    );

    $prefix = '/iot_backend/public';

    if (str_starts_with($path, $prefix)) {
        $path = substr($path, strlen($prefix));
    }

    $method = $_SERVER['REQUEST_METHOD'];

    if (
        $method === 'POST' &&
        $path === '/api/v1/telemetry'
    ) {
        $controller = new TelemetryController(
            $service,
            $devices
        );

        $controller->store();
        exit;
    }

    // GET /api/v1/devices
    if (
        $method === 'GET' &&
        $path === '/api/v1/devices'
    ) {
        $controller = new DeviceController(
            $devices,
            $telemetry
        );

        $controller->index();
        exit;
    }

    // GET /api/v1/devices/{id}/telemetry
    if (
        $method === 'GET' &&
        preg_match(
            '#^/api/v1/devices/([0-9]+)/telemetry$#',
            $path,
            $matches
        )
    ) {
        $controller = new DeviceController(
            $devices,
            $telemetry
        );

        $controller->telemetry((int) $matches[1]);
        exit;
    }

    // PUT /api/v1/devices/{id}/config
    if (
        $method === 'PUT' &&
        preg_match(
            '#^/api/v1/devices/([0-9]+)/config$#',
            $path,
            $matches
        )
    ) {
        $configRepo = new ConfigRepository($pdo);
        $configService = new ConfigService($configRepo);

        $controller = new ConfigController(
            $configService,
            $devices
        );

        $controller->update((int) $matches[1]);
        exit;
    }

    // GET /api/v1/events
    if (
        $method === 'GET' &&
        $path === '/api/v1/events'
    ) {
        $eventRepo = new EventRepository($pdo);

        $controller = new EventController(
            $eventRepo,
            $devices
        );

        $controller->index();
        exit;
    }

    http_response_code(404);

    echo json_encode([
        'ok' => false,
        'data' => [
            'error' => 'Endpoint no encontrado'
        ],
        'meta' => [
            'api_version' => 'v1'
        ]
    ]);

} catch (Throwable $e) {
    error_log($e->getMessage());

    http_response_code(500);

    echo json_encode([
        'ok' => false,
        'data' => [
            'error' => 'Error interno del servidor'
        ],
        'meta' => [
            'api_version' => 'v1'
        ]
    ]);
}
