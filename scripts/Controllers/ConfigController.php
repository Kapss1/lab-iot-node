
<?php

class ConfigController
{
    public function __construct(
        private ConfigService $service,
        private DeviceRepository $devices
    ) {}

    public function update(int $deviceId): void
    {
        $auth = $_SERVER['HTTP_AUTHORIZATION']
            ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION']
            ?? '';

        if (!preg_match('/^Bearer\s+(\S+)$/i', $auth, $matches)) {
            $this->respond(401, false, ['error' => 'Token requerido']);
            return;
        }

        $device = $this->devices->findById($deviceId);

        if (
            !$device ||
            !password_verify($matches[1], $device['token_hash'])
        ) {
            $this->respond(401, false, ['error' => 'Token invalido']);
            return;
        }

        $data = json_decode(
            file_get_contents('php://input'),
            true
        );

        if (
            json_last_error() !== JSON_ERROR_NONE ||
            !is_array($data) ||
            array_is_list($data)
        ) {
            $this->respond(422, false, ['error' => 'JSON invalido']);
            return;
        }

        try {
            $result = $this->service->update($deviceId, $data);
            $this->respond(200, true, $result);

        } catch (InvalidArgumentException $e) {
            $this->respond(422, false, [
                'error' => $e->getMessage()
            ]);

        } catch (Throwable $e) {
            error_log($e->getMessage());
            $this->respond(500, false, [
                'error' => 'Error interno'
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
            'meta' => ['api_version' => 'v1']
        ], JSON_UNESCAPED_UNICODE);
    }
}
