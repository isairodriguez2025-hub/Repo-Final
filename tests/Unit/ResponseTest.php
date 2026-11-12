<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Core\Response;
use PHPUnit\Framework\TestCase;

final class ResponseTest extends TestCase
{
    public function test_json_define_contenido_y_estado(): void
    {
        $r = Response::json(['ok' => true], 201);

        $this->assertSame(201, $r->status());
        $this->assertSame('{"ok":true}', $r->body());
    }

    public function test_json_no_escapa_acentos(): void
    {
        $r = Response::json(['mensaje' => 'sesión expirada']);

        $this->assertStringContainsString('sesión expirada', $r->body());
    }

    public function test_redirect_usa_302_por_defecto(): void
    {
        $r = Response::redirect('/login');

        $this->assertSame(302, $r->status());
    }

    public function test_acepta_nonce_para_la_csp(): void
    {
        $r = Response::html('<p>hola</p>')->withNonce('abc123');

        $this->assertSame('abc123', $r->nonce());
    }
}
