<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Envuelve la petición HTTP. Nada de $_GET/$_POST sueltos por el código:
 * todo pasa por aquí para poder validar y probar.
 */
final class Request
{
    public function __construct(
        private readonly string $method,
        private readonly string $path,
        private readonly array $query,
        private readonly array $post,
        private readonly array $files,
        private readonly array $server,
        private readonly string $rawBody = '',
    ) {
    }

    public static function fromGlobals(): self
    {
        $method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
        $uri    = (string) ($_SERVER['REQUEST_URI'] ?? '/');
        $path   = (string) (parse_url($uri, PHP_URL_PATH) ?: '/');
        $path   = rtrim($path, '/');
        if ($path === '') {
            $path = '/';
        }

        return new self(
            $method,
            $path,
            $_GET,
            $_POST,
            $_FILES,
            $_SERVER,
            (string) (file_get_contents('php://input') ?: ''),
        );
    }

    public function method(): string
    {
        return $this->method;
    }

    public function path(): string
    {
        return $this->path;
    }

    /** Parámetro de la URL (?clave=valor). */
    public function query(string $key, mixed $default = null): mixed
    {
        return $this->query[$key] ?? $default;
    }

    /** Dato de formulario o del cuerpo JSON, lo que venga. */
    public function input(string $key, mixed $default = null): mixed
    {
        if (array_key_exists($key, $this->post)) {
            return $this->post[$key];
        }

        $json = $this->json();

        return $json[$key] ?? $default;
    }

    /** Cuerpo JSON ya decodificado. Devuelve [] si no es JSON válido. */
    public function json(): array
    {
        if ($this->rawBody === '') {
            return [];
        }

        $data = json_decode($this->rawBody, true);

        return is_array($data) ? $data : [];
    }

    public function file(string $key): ?array
    {
        return $this->files[$key] ?? null;
    }

    public function header(string $name): ?string
    {
        $key = 'HTTP_' . strtoupper(str_replace('-', '_', $name));

        return isset($this->server[$key]) ? (string) $this->server[$key] : null;
    }

    public function ip(): string
    {
        return (string) ($this->server['REMOTE_ADDR'] ?? '0.0.0.0');
    }

    public function isJson(): bool
    {
        $tipo = $this->header('Content-Type') ?? '';

        return str_contains(strtolower($tipo), 'application/json');
    }

    public function rawBody(): string
    {
        return $this->rawBody;
    }
}
