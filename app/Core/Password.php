<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Hash de contraseñas con Argon2id. Nunca md5 ni sha1.
 */
final class Password
{
    public const MINIMO = 12;

    public static function hash(string $plana): string
    {
        return password_hash($plana, PASSWORD_ARGON2ID);
    }

    public static function verificar(string $plana, string $hash): bool
    {
        return password_verify($plana, $hash);
    }

    /** Si el algoritmo cambió, se puede re-hashear sin pedir la contraseña. */
    public static function necesitaRehash(string $hash): bool
    {
        return password_needs_rehash($hash, PASSWORD_ARGON2ID);
    }

    /**
     * Verificación contra un hash ficticio.
     *
     * Se usa cuando el correo no existe: así el tiempo de respuesta es el mismo
     * que con un usuario real y no se puede deducir qué correos están dados de
     * alta (SEG-02, anti-enumeración).
     */
    public static function verificarFicticio(string $plana): void
    {
        static $ficticio = null;

        $ficticio ??= password_hash(bin2hex(random_bytes(16)), PASSWORD_ARGON2ID);

        password_verify($plana, $ficticio);
    }
}
