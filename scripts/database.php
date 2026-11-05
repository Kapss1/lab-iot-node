<?php

function getPDO(): PDO
{
    // database.local.php solo existe en cada equipo y esta excluido de Git.
    // Tambien se admiten DB_HOST, DB_PORT, DB_NAME, DB_USER y DB_PASSWORD.
    $localFile = __DIR__ . '/database.local.php';
    $local = is_file($localFile) ? require $localFile : [];
    if (!is_array($local)) {
        throw new RuntimeException('Configuracion local de base de datos invalida');
    }

    $read = static function (string $key, ?string $default = null) use ($local): ?string {
        $env = getenv($key);
        if ($env !== false) {
            return $env;
        }
        return array_key_exists($key, $local) ? (string) $local[$key] : $default;
    };

    $host = $read('DB_HOST', '127.0.0.1');
    $port = $read('DB_PORT', '3306');
    $database = $read('DB_NAME', 'iot_telemetry');
    $username = $read('DB_USER');
    $password = $read('DB_PASSWORD');
    if ($username === null || $username === '' || $password === null) {
        throw new RuntimeException('Configura database.local.php o las variables DB_USER y DB_PASSWORD');
    }
    if (!ctype_digit($port) || (int) $port < 1 || (int) $port > 65535) {
        throw new RuntimeException('Puerto de base de datos invalido');
    }

    $dsn = "mysql:host=$host;port=$port;dbname=$database;charset=utf8mb4";
    $pdo = new PDO($dsn, $username, $password, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
    $pdo->exec("SET time_zone = '+00:00'");
    return $pdo;
}
