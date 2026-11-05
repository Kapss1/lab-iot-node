
<?php

class TelemetryRepository
{
    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    public function findByDeviceAndSeq(
        int $deviceId,
        int $seq
    ): ?array {
        $stmt = $this->pdo->prepare(
            "SELECT * FROM telemetry
             WHERE device_id = :device_id
             AND seq = :seq"
        );

        $stmt->execute([
            'device_id' => $deviceId,
            'seq' => $seq
        ]);

        return $stmt->fetch() ?: null;
    }

    public function create(
        int $deviceId,
        int $timestamp,
        array $value,
        int $rssi,
        int $seq
    ): int {
        $sql = "INSERT INTO telemetry
                (device_id, ts, value, rssi, seq)
                VALUES (
                    :device_id,
                    FROM_UNIXTIME(:ts),
                    :value,
                    :rssi,
                    :seq
                )";

        $stmt = $this->pdo->prepare($sql);

        $stmt->execute([
            'device_id' => $deviceId,
            'ts' => $timestamp,
            'value' => json_encode(
                $value,
                JSON_THROW_ON_ERROR
            ),
            'rssi' => $rssi,
            'seq' => $seq
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    public function findByDevice(
        int $deviceId,
        int $limit = 100
    ): array {
        $limit = max(1, min($limit, 1000));

        $stmt = $this->pdo->prepare(
            "SELECT id, device_id, ts, value, rssi, seq
             FROM telemetry
             WHERE device_id = :device_id
             ORDER BY ts DESC, id DESC
             LIMIT $limit"
        );

        $stmt->execute(['device_id' => $deviceId]);

        return $stmt->fetchAll();
    }
}
