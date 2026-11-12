<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Token CSRF por sesión. Se regenera al iniciar sesión y se valida en cada
 * petición que cambia datos (POST, PUT, DELETE).
 */
final class Csrf
{
    private const CLAVE = '_csrf_token';

    public static function token(): string
    {
        if (empty($_SESSION[self::CLAVE])) {
            $_SESSION[self::CLAVE] = bin2hex(random_bytes(32));
        }

        return (string) $_SESSION[self::CLAVE];
    }

    public static function regenerar(): string
    {
        $_SESSION[self::CLAVE] = bin2hex(random_bytes(32));

        return (string) $_SESSION[self::CLAVE];
    }

    /** Compara en tiempo constante para no filtrar información por el tiempo. */
    public static function valido(?string $enviado): bool
    {
        $esperado = $_SESSION[self::CLAVE] ?? null;

        if (!is_string($esperado) || $esperado === '' || !is_string($enviado) || $enviado === '') {
            return false;
        }

        return hash_equals($esperado, $enviado);
    }
}
