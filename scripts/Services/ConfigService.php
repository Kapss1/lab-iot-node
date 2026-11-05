
<?php

class ConfigService
{
    public function __construct(
        private ConfigRepository $configs
    ) {}

    public function update(
        int $deviceId,
        array $data
    ): array {
        $key = $data['config_key'] ?? null;
        $value = $data['config_value'] ?? null;

        if (
            !is_string($key) ||
            !preg_match('/^[a-zA-Z0-9_]{1,50}$/', $key)
        ) {
            throw new InvalidArgumentException(
                'config_key invalido'
            );
        }

        if (
            !is_string($value) ||
            strlen($value) > 255
        ) {
            throw new InvalidArgumentException(
                'config_value invalido'
            );
        }

        // Primera configuracion admitida:
        // intervalo de publicacion en segundos.
        if ($key !== 'intervalo_segundos') {
            throw new InvalidArgumentException(
                'Configuracion no permitida'
            );
        }

        if (
            !ctype_digit($value) ||
            (int) $value < 1 ||
            (int) $value > 3600
        ) {
            throw new InvalidArgumentException(
                'Intervalo permitido: 1 a 3600 segundos'
            );
        }

        $this->configs->save($deviceId, $key, $value);

        return [
            'device_id' => $deviceId,
            'config_key' => $key,
            'config_value' => $value,
            'saved' => true
        ];
    }
}
