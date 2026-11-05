
<?php

class EventController
{
    public function __construct(
        private EventRepository $events,
        private DeviceRepository $devices
    ) {}

    public function index(): void
    {
        $auth = $_SERVER['HTTP_AUTHORIZATION']
            ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION']
            ?? '';

        if (
            !preg_match(
                '/^Bearer\s+(\S+)$/i',
                $auth,
                $matches
            )
        ) {
            $this->respond(401, false, [
                'error' => 'Token requerido'
            ]);
            return;
        }

        // Versión inicial: identificar el dispositivo
        // a partir de su token REST.
        $authenticated = null;

        foreach ($this->devices->findAll() as $device) {
            $full = $this->devices->findById(
                (int) $device['id']
            );

            if (
                $full &&
                password_verify(
                    $matches[1],
                    $full['token_hash']
                )
            ) {
                $authenticated = $full;
                break;
            }
        }

        if (!$authenticated) {
            $this->respond(401, false, [
                'error' => 'Token invalido'
            ]);
            return;
        }

        $limit = filter_var(
            $_GET['limit'] ?? 100,
            FILTER_VALIDATE_INT
        );

        if (
            $limit === false ||
            $limit < 1 ||
            $limit > 1000
        ) {
            $this->respond(422, false, [
                'error' => 'limit debe estar entre 1 y 1000'
            ]);
            return;
        }

        try {
            $records = $this->events->findByDevice(
                (int) $authenticated['id'],
                $limit
            );

            $this->respond(200, true, $records);

        } catch (Throwable $e) {
            error_log($e->getMessage());

            $this->respond(500, false, [
                'error' => 'Error interno del servidor'
            ]);
        }
    }

    private function respond(
        int $status,
        bool $ok,
        array $data
    ): void {
        http_response_code($status);

        header('Content-Type: application/json; charset=utf-8');

        echo json_encode([
            'ok' => $ok,
            'data' => $data,
            'meta' => [
                'api_version' => 'v1'
            ]
        ], JSON_UNESCAPED_UNICODE);
    }
}
