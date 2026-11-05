
<?php

class DeviceController
{
    public function __construct(
        private DeviceRepository $devices,
        private TelemetryRepository $telemetry
    ) {}

    private function authenticate(): ?array
    {
        $auth = $_SERVER['HTTP_AUTHORIZATION']
            ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION']
            ?? '';

        if (!preg_match('/^Bearer\s+(\S+)$/i', $auth, $matches)) {
            return null;
        }

        // Autenticacion con token del dispositivo.
        foreach ($this->devices->findAll() as $device) {
            $full = $this->devices->findById((int) $device['id']);

            if ($full && password_verify(
                $matches[1],
                $full['token_hash']
            )) {
                return $full;
            }
        }

        return null;
    }

    public function index(): void
    {
        if (!$this->authenticate()) {
            $this->respond(401, false, [
                'error' => 'Token invalido'
            ]);
            return;
        }

        $this->respond(200, true, $this->devices->findAll());
    }

    public function telemetry(int $id): void
    {
        $authenticated = $this->authenticate();

        if (!$authenticated) {
            $this->respond(401, false, [
                'error' => 'Token invalido'
            ]);
            return;
        }

        if ((int) $authenticated['id'] !== $id) {
            $this->respond(403, false, [
                'error' => 'Acceso denegado al dispositivo'
            ]);
            return;
        }

        $limit = filter_var(
            $_GET['limit'] ?? 100,
            FILTER_VALIDATE_INT
        );

        if ($limit === false || $limit < 1 || $limit > 1000) {
            $this->respond(422, false, [
                'error' => 'limit debe estar entre 1 y 1000'
            ]);
            return;
        }

        $records = $this->telemetry->findByDevice(
            $id,
            $limit
        );

        foreach ($records as &$record) {
            $record['value'] = json_decode(
                $record['value'],
                true
            );
        }
        unset($record);

        $this->respond(200, true, $records);
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
