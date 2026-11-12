<?php

declare(strict_types=1);

/**
 * Aplica las migraciones pendientes en orden numérico.
 *
 *   php bin/migrate.php
 *
 * Reglas:
 *  - Nunca re-aplica una migración ya registrada.
 *  - Nunca edita una migración aplicada: si hay que cambiar algo, se crea otra.
 *  - Usa el usuario migrador (con permisos DDL), no el de la aplicación.
 */

require __DIR__ . '/../vendor/autoload.php';

use App\Config\Database;
use Dotenv\Dotenv;

Dotenv::createImmutable(__DIR__ . '/..')->safeLoad();

$directorio = __DIR__ . '/../database/migrations';

if (!is_dir($directorio)) {
    fwrite(STDERR, "No existe el directorio de migraciones: {$directorio}\n");
    exit(1);
}

try {
    $pdo = Database::conexion(true);
} catch (Throwable $e) {
    fwrite(STDERR, "No se pudo conectar como migrador: {$e->getMessage()}\n");
    exit(1);
}

// Tabla de control: se crea si no existe, sin depender de la migración 000.
$pdo->exec(
    'CREATE TABLE IF NOT EXISTS migraciones (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        nombre VARCHAR(200) NOT NULL UNIQUE,
        aplicada_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
);

$aplicadas = $pdo->query('SELECT nombre FROM migraciones')->fetchAll(PDO::FETCH_COLUMN);
$aplicadas = array_flip($aplicadas);

$archivos = glob($directorio . '/*.sql') ?: [];
sort($archivos, SORT_STRING);

$pendientes = 0;
$errores = 0;

foreach ($archivos as $archivo) {
    $nombre = basename($archivo);

    if (isset($aplicadas[$nombre])) {
        continue;
    }

    $sql = (string) file_get_contents($archivo);

    if (trim($sql) === '') {
        continue;
    }

    try {
        $pdo->exec($sql);
        $stmt = $pdo->prepare('INSERT INTO migraciones (nombre) VALUES (?)');
        $stmt->execute([$nombre]);
        $pendientes++;
        echo "  ✓ {$nombre}\n";
    } catch (Throwable $e) {
        $errores++;
        fwrite(STDERR, "  ✗ {$nombre}: {$e->getMessage()}\n");
        break; // Se detiene: no tiene sentido seguir con el esquema a medias.
    }
}

echo $pendientes === 0
    ? "No había migraciones pendientes.\n"
    : "\n{$pendientes} migración(es) aplicada(s).\n";

exit($errores > 0 ? 1 : 0);
