<?php

declare(strict_types=1);

require __DIR__ . '/../../vendor/autoload.php';
Dotenv\Dotenv::createImmutable(__DIR__ . '/../..')->safeLoad();

$pdo = App\Config\Database::conexion();
$email = 'comercio@didi2.mx';

$pdo->prepare('DELETE FROM usuarios WHERE email = ?')->execute([$email]);
$pdo->prepare(
    "INSERT INTO usuarios (uuid, email, password_hash, telefono, estado, email_verificado_at)
     VALUES (UUID(), ?, ?, '5512345678', 'activo', NOW())"
)->execute([$email, App\Core\Password::hash('comercio123')]);

$usuarioId = (int) $pdo->lastInsertId();

$pdo->prepare("INSERT INTO usuario_roles (usuario_id, rol_id) SELECT ?, id FROM roles WHERE nombre = 'comercio'")
    ->execute([$usuarioId]);

$pdo->prepare("INSERT INTO comercios_perfil (usuario_id, nombre_negocio) VALUES (?, 'Abarrotes La Esquina')")
    ->execute([$usuarioId]);

$pdo->prepare(
    "INSERT INTO direcciones (usuario_id, alias, calle, colonia, ciudad, estado_mx, cp, latitud, longitud, es_principal)
     VALUES (?, 'Local', 'Av. Insurgentes Sur 123', 'Del Valle', 'Ciudad de México', 'CDMX', '03100', 19.3800, -99.1700, 1)"
)->execute([$usuarioId]);

echo "Comercio creado: {$email} / comercio123\n";
