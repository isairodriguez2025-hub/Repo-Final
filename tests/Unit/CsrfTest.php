<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Core\Csrf;
use PHPUnit\Framework\TestCase;

final class CsrfTest extends TestCase
{
    protected function setUp(): void
    {
        $_SESSION = [];
    }

    public function test_genera_un_token_de_64_caracteres(): void
    {
        $token = Csrf::token();

        $this->assertSame(64, strlen($token));
        $this->assertSame($token, Csrf::token(), 'El token debe ser estable dentro de la sesión');
    }

    public function test_valida_el_token_correcto(): void
    {
        $token = Csrf::token();

        $this->assertTrue(Csrf::valido($token));
    }

    public function test_rechaza_token_incorrecto_o_vacio(): void
    {
        Csrf::token();

        $this->assertFalse(Csrf::valido('otro'));
        $this->assertFalse(Csrf::valido(''));
        $this->assertFalse(Csrf::valido(null));
    }

    public function test_regenerar_cambia_el_token(): void
    {
        $antes = Csrf::token();
        $despues = Csrf::regenerar();

        $this->assertNotSame($antes, $despues);
        $this->assertFalse(Csrf::valido($antes));
        $this->assertTrue(Csrf::valido($despues));
    }
}
