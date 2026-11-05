
<?php

class TelemetryService
{
    private DeviceRepository $devices;
    private TelemetryRepository $telemetry;

    public function __construct(
        DeviceRepository $devices,
        TelemetryRepository $telemetry
    ) {
        $this->devices = $devices;
        $this->telemetry = $telemetry;
    }

    public function ingest(array $data): array
    {
        // 1. Validar campos principales
        foreach (['node_id', 'ts', 'rssi', 'seq', 'value'] as $field) {
            if (!array_key_exists($field, $data)) {
                throw new InvalidArgumentException(
                    "Falta el campo: $field"
                );
            }
        }

        if (
            !is_string($data['node_id']) ||
            trim($data['node_id']) === '' ||
            strlen($data['node_id']) > 50
        ) {
            throw new InvalidArgumentException('node_id invalido');
        }

        foreach (['ts', 'rssi', 'seq'] as $field) {
            if (!is_int($data[$field])) {
                throw new InvalidArgumentException(
                    "$field debe ser entero"
                );
            }
        }

        // MariaDB TIMESTAMP y columnas INT con signo.
        if (
            $data['ts'] < 1 ||
            $data['ts'] > 2147483647 ||
            $data['seq'] < 0 ||
            $data['seq'] > 2147483647 ||
            $data['rssi'] < -120 ||
            $data['rssi'] > 0
        ) {
            throw new InvalidArgumentException(
                'ts, seq o rssi fuera de rango'
            );
        }

        // 2. Validar mediciones
        if (!is_array($data['value'])) {
            throw new InvalidArgumentException('value invalido');
        }

        foreach (
            ['temperatura', 'humedad', 'lux', 'particulas']
            as $field
        ) {
            if (
                !array_key_exists($field, $data['value']) ||
                !is_numeric($data['value'][$field]) ||
                !is_int($data['value'][$field]) &&
                !is_float($data['value'][$field])
            ) {
                throw new InvalidArgumentException(
                    "Medicion invalida: $field"
                );
            }

            $number = (float) $data['value'][$field];

            if (!is_finite($number)) {
                throw new InvalidArgumentException(
                    "Medicion fuera de rango: $field"
                );
            }
        }

        if (
            $data['value']['humedad'] < 0 ||
            $data['value']['humedad'] > 100 ||
            $data['value']['lux'] < 0 ||
            $data['value']['particulas'] < 0
        ) {
            throw new InvalidArgumentException(
                'Valores fisicos invalidos'
            );
        }

        // 3. Identificar dispositivo
        $device = $this->devices->findByNodeId(
            $data['node_id']
        );

        if (!$device) {
            throw new RuntimeException(
                'Dispositivo no registrado'
            );
        }

        $deviceId = (int) $device['id'];

        // 4. Comprobar idempotencia
        $existing = $this->telemetry->findByDeviceAndSeq(
            $deviceId,
            $data['seq']
        );

        if ($existing) {
            return [
                'telemetry_id' => (int) $existing['id'],
                'duplicate' => true
            ];
        }

        // 5. Registrar nueva telemetria
        try {
            $id = $this->telemetry->create(
                $deviceId,
                $data['ts'],
                $data['value'],
                $data['rssi'],
                $data['seq']
            );
        } catch (PDOException $e) {
            // Otra solicitud pudo insertar el mismo seq
            // entre la consulta y el INSERT.
            if (
                ($e->errorInfo[1] ?? null) === 1062
            ) {
                $existing =
                    $this->telemetry->findByDeviceAndSeq(
                        $deviceId,
                        $data['seq']
                    );

                if ($existing) {
                    return [
                        'telemetry_id' => (int) $existing['id'],
                        'duplicate' => true
                    ];
                }
            }

            throw $e;
        }

        $this->devices->updateLastSeen($deviceId);

        return [
            'telemetry_id' => $id,
            'duplicate' => false
        ];
    }
}
