
<?php

class Logger
{
    public static function write(
        string $level,
        string $component,
        string $message
    ): void {
        $directory = __DIR__ . '/../../logs';

        if (!is_dir($directory)) {
            throw new RuntimeException(
                'Directorio de logs inexistente'
            );
        }

        $entry = [
            'timestamp' => gmdate('c'),
            'level' => $level,
            'component' => $component,
            'message' => $message
        ];

        $line = json_encode(
            $entry,
            JSON_UNESCAPED_UNICODE |
            JSON_THROW_ON_ERROR
        ) . PHP_EOL;

        $file = $directory . '/backend.jsonl';

        if (file_put_contents(
            $file,
            $line,
            FILE_APPEND | LOCK_EX
        ) === false) {
            throw new RuntimeException(
                'No se pudo escribir el log'
            );
        }
    }
}
