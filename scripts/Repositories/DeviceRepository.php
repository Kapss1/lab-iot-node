
<?php

class DeviceRepository
{
    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    public function findByNodeId(string $nodeId): ?array
    {
        $sql = "SELECT * FROM devices WHERE node_id = :node_id";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute(['node_id' => $nodeId]);

        $device = $stmt->fetch();

        return $device ?: null;
    }

    public function findById(int $id): ?array
    {
        $stmt = $this->pdo->prepare(
            "SELECT * FROM devices WHERE id = :id"
        );

        $stmt->execute(['id' => $id]);

        return $stmt->fetch() ?: null;
    }

    public function findAll(): array
    {
        $stmt = $this->pdo->query(
            "SELECT id, node_id, name, last_seen
             FROM devices ORDER BY id"
        );

        return $stmt->fetchAll();
    }

    public function create(
        string $nodeId,
        string $name,
        string $tokenHash
    ): int {
        $sql = "INSERT INTO devices
                (node_id, name, token_hash)
                VALUES (:node_id, :name, :token_hash)";

        $stmt = $this->pdo->prepare($sql);

        $stmt->execute([
            'node_id' => $nodeId,
            'name' => $name,
            'token_hash' => $tokenHash
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    public function updateLastSeen(int $deviceId): void
    {
        $stmt = $this->pdo->prepare(
            "UPDATE devices
             SET last_seen = UTC_TIMESTAMP()
             WHERE id = :id"
        );

        $stmt->execute(['id' => $deviceId]);
    }
}
