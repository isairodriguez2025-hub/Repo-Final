<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Respuesta HTTP. Aplica las cabeceras de seguridad en un solo lugar para que
 * ninguna ruta pueda olvidarlas.
 */
final class Response
{
    /** @var array<string, string> */
    private array $headers = [];

    /** Nonce para la política CSP: se genera por petición en el middleware. */
    private ?string $nonce = null;

    public function __construct(
        private string $body = '',
        private int $status = 200,
    ) {
    }

    public static function json(array $data, int $status = 200): self
    {
        $r = new self((string) json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), $status);
        $r->headers['Content-Type'] = 'application/json; charset=utf-8';

        return $r;
    }

    public static function html(string $html, int $status = 200): self
    {
        $r = new self($html, $status);
        $r->headers['Content-Type'] = 'text/html; charset=utf-8';

        return $r;
    }

    public static function redirect(string $url, int $status = 302): self
    {
        $r = new self('', $status);
        $r->headers['Location'] = $url;

        return $r;
    }

    public function withNonce(string $nonce): self
    {
        $this->nonce = $nonce;

        return $this;
    }

    public function nonce(): ?string
    {
        return $this->nonce;
    }

    public function withHeader(string $name, string $value): self
    {
        $this->headers[$name] = $value;

        return $this;
    }

    public function status(): int
    {
        return $this->status;
    }

    public function body(): string
    {
        return $this->body;
    }

    /** Envía la respuesta al navegador con las cabeceras de seguridad. */
    public function send(): void
    {
        if (headers_sent()) {
            echo $this->body;

            return;
        }

        http_response_code($this->status);

        $seguridad = [
            'X-Content-Type-Options'  => 'nosniff',
            'X-Frame-Options'         => 'DENY',
            'Referrer-Policy'         => 'strict-origin-when-cross-origin',
            'Permissions-Policy'      => 'geolocation=(self)',
            'Content-Security-Policy' => $this->nonce === null
                ? "default-src 'self'; img-src 'self' data:; style-src 'self'; script-src 'self'; frame-ancestors 'none'"
                : "default-src 'self'; img-src 'self' data:; style-src 'self'; script-src 'self' 'nonce-{$this->nonce}'; frame-ancestors 'none'",
        ];

        // HSTS solo tiene sentido sobre HTTPS.
        if (($this->headers['X-Forwarded-Proto'] ?? '') === 'https'
            || (($_SERVER['HTTPS'] ?? '') !== '' && ($_SERVER['HTTPS'] ?? '') !== 'off')) {
            $seguridad['Strict-Transport-Security'] = 'max-age=31536000; includeSubDomains';
        }

        foreach ($seguridad + $this->headers as $nombre => $valor) {
            header($nombre . ': ' . $valor);
        }

        echo $this->body;
    }
}
