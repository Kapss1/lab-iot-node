
<?php

class EventRepository
{
    public function __construct(
        private PDO $pdo
    ) {}

    public function findByDevice(
        int $deviceId,
        int $limit = 100
    ): array {
        $limit = max(1, min($limit, 1000));

        $stmt = $this->pdo->prepare(
            "SELECT
                e.id,
                e.device_id,
                d.node_id,
                e.event_type,
                e.severity,
                e.ts
             FROM events e
             INNER JOIN devices d
                ON e.device_id = d.id
             WHERE e.device_id = :device_id
             ORDER BY e.ts DESC, e.id DESC
             LIMIT $limit"
        );

        $stmt->execute([
            'device_id' => $deviceId
        ]);

        return $stmt->fetchAll();
    }
}
