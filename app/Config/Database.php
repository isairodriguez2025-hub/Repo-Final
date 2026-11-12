<?php

declare(strict_types=1);

namespace App\Config;

use PDO;
use PDOException;
use RuntimeException;

/**
 * Conexión PDO única. Se configura con:
 *  - ERRMODE_EXCEPTION: los errores se lanzan, no se ignoran.
 *  - EMULATE_PREPARES = false: sentencias preparadas REALES en el servidor,
 *    que es lo que bloquea la inyección SQL.
 *  - FETCH_ASSOC: arreglos asociativos, sin sorpresas.
 */
final class Database
{
    private static ?PDO $conexion = null;

    /**
     * Conexión nueva, sin reutilizar la del proceso.
     *
     * Necesaria al bifurcar procesos (pcntl_fork): el hijo hereda el socket
     * del padre y, si lo usa, ambos escriben sobre la misma conexión y el
     * servidor la corta con "Server has gone away".
     */
    public static function nuevaConexion(): PDO
    {
        self::$conexion = null;

        return self::conexion();
    }

    public static function conexion(bool $usarMigrador = false): PDO
    {
        if (self::$conexion !== null && !$usarMigrador) {
            return self::$conexion;
        }

        $host = $_ENV['DB_HOST'] ?? '127.0.0.1';
        $puerto = $_ENV['DB_PORT'] ?? '3306';
        $nombre = $_ENV['DB_NAME'] ?? 'didi2';
        $usuario = $usarMigrador
            ? ($_ENV['DB_MIGRATOR_USER'] ?? 'root')
            : ($_ENV['DB_USER'] ?? 'root');
        $clave = $usarMigrador
            ? ($_ENV['DB_MIGRATOR_PASS'] ?? '')
            : ($_ENV['DB_PASS'] ?? '');

        $dsn = sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4', $host, $puerto, $nombre);

        try {
            $pdo = new PDO($dsn, $usuario, $clave, [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
                PDO::ATTR_STRINGIFY_FETCHES  => false,
            ]);
        } catch (PDOException $e) {
            // El detalle va al log; al usuario se le da un mensaje genérico.
            throw new RuntimeException('No se pudo conectar a la base de datos.', 0, $e);
        }

        if (!$usarMigrador) {
            self::$conexion = $pdo;
        }

        return $pdo;
    }
}
