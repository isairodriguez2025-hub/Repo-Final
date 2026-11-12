<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Core\Request;
use PHPUnit\Framework\TestCase;

final class RequestTest extends TestCase
{
    private function peticion(string $cuerpo = '', array $post = [], array $server = []): Request
    {
        return new Request('POST', '/api/algo', ['page' => '2'], $post, [], $server, $cuerpo);
    }

    public function test_lee_parametros_de_la_url(): void
    {
        $this->assertSame('2', $this->peticion()->query('page'));
        $this->assertNull($this->peticion()->query('noexiste'));
        $this->assertSame('x', $this->peticion()->query('noexiste', 'x'));
    }

    public function test_lee_el_cuerpo_json(): void
    {
        $r = $this->peticion('{"email":"a@b.mx","password":"secreta"}');

        $this->assertSame('a@b.mx', $r->input('email'));
        $this->assertSame('secreta', $r->input('password'));
    }

    public function test_json_invalido_devuelve_arreglo_vacio(): void
    {
        $this->assertSame([], $this->peticion('esto no es json')->json());
    }

    public function test_el_formulario_gana_sobre_el_json(): void
    {
        $r = $this->peticion('{"email":"json@b.mx"}', ['email' => 'form@b.mx']);

        $this->assertSame('form@b.mx', $r->input('email'));
    }

    public function test_detecta_content_type_json(): void
    {
        $r = $this->peticion('', [], ['HTTP_CONTENT_TYPE' => 'application/json; charset=utf-8']);

        $this->assertTrue($r->isJson());
    }

    public function test_lee_cabeceras_con_guiones(): void
    {
        $r = $this->peticion('', [], ['HTTP_X_CSRF_TOKEN' => 'abc123']);

        $this->assertSame('abc123', $r->header('X-CSRF-Token'));
    }
}
