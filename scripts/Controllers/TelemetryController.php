
<?php

class TelemetryController
{
    public function __construct(
        private TelemetryService $service,
        private DeviceRepository $devices
    ) {}

    public function store(): void
    {
        $data = json_decode(
            file_get_contents('php://input'),
            true
        );

        if (
            json_last_error() !== JSON_ERROR_NONE ||
            !is_array($data) ||
            array_is_list($data)
        ) {
            $this->respond(422, false, [
                'error' => 'JSON invalido'
            ]);
            return;
        }

        $nodeId = $data['node_id'] ?? null;

        $auth = $_SERVER['HTTP_AUTHORIZATION']
            ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION']
            ?? '';

        if (
            !is_string($nodeId) ||
            !preg_match('/^Bearer\s+(\S+)$/i', $auth, $matches)
        ) {
            $this->respond(401, false, [
                'error' => 'Autenticacion requerida'
            ]);
            return;
        }

        $device = $this->devices->findByNodeId($nodeId);

        if (
            !$device ||
            !password_verify(
                $matches[1],
                $device['token_hash']
            )
        ) {
            $this->respond(401, false, [
                'error' => 'Token invalido'
            ]);
            return;
        }

        try {
            $result = $this->service->ingest($data);

            $this->respond(
                $result['duplicate'] ? 200 : 201,
                true,
                $result
            );

        } catch (InvalidArgumentException $e) {
            $this->respond(422, false, [
                'error' => $e->getMessage()
            ]);

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
