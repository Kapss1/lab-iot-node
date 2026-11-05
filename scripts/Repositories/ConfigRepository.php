
<?php

class ConfigRepository
{
    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    // Insertar o actualizar configuracion
    public function save(
        int $deviceId,
        string $key,
        string $value
    ): void {
        $sql = "
            INSERT INTO configurations
                (
                    device_id,
                    config_key,
                    config_value,
                    updated_at
                )
            VALUES
                (
                    :device_id,
                    :config_key,
                    :config_value,
                    UTC_TIMESTAMP()
                )
            ON DUPLICATE KEY UPDATE
                config_value = VALUES(config_value),
                updated_at = UTC_TIMESTAMP()
        ";

        $stmt = $this->pdo->prepare($sql);

        $stmt->execute([
            'device_id' => $deviceId,
            'config_key' => $key,
            'config_value' => $value
        ]);
    }

    // Consultar configuraciones de un dispositivo
    public function findByDevice(int $deviceId): array
    {
        $sql = "
            SELECT
                config_key,
                config_value,
                updated_at
            FROM configurations
            WHERE device_id = :device_id
            ORDER BY config_key
        ";

        $stmt = $this->pdo->prepare($sql);

        $stmt->execute([
            'device_id' => $deviceId
        ]);

        return $stmt->fetchAll();
    }
}
