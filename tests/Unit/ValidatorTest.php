<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Core\Validator;
use PHPUnit\Framework\TestCase;

final class ValidatorTest extends TestCase
{
    public function test_campo_obligatorio_vacio(): void
    {
        $v = Validator::make(['email' => ''], ['email' => 'required']);

        $this->assertTrue($v->falla());
        $this->assertStringContainsString('obligatorio', (string) $v->primerError());
    }

    public function test_correo_con_formato_invalido(): void
    {
        $v = Validator::make(['email' => 'no-es-correo'], ['email' => 'required|email']);

        $this->assertTrue($v->falla());
    }

    public function test_correo_valido_pasa(): void
    {
        $v = Validator::make(['email' => 'a@b.mx'], ['email' => 'required|email']);

        $this->assertFalse($v->falla());
    }

    public function test_longitud_minima(): void
    {
        $v = Validator::make(['password' => 'corta'], ['password' => 'min:12']);

        $this->assertTrue($v->falla());
        $this->assertStringContainsString('12', (string) $v->primerError());
    }

    public function test_telefono_mexicano_de_10_digitos(): void
    {
        $this->assertFalse(Validator::make(['t' => '5512345678'], ['t' => 'telefono'])->falla());
        $this->assertTrue(Validator::make(['t' => '551234'], ['t' => 'telefono'])->falla());
        $this->assertTrue(Validator::make(['t' => '55-1234-5678'], ['t' => 'telefono'])->falla());
    }

    public function test_valor_fuera_de_lista(): void
    {
        $this->assertFalse(Validator::make(['rol' => 'comercio'], ['rol' => 'in:comercio,mayorista'])->falla());
        $this->assertTrue(Validator::make(['rol' => 'admin'], ['rol' => 'in:comercio,mayorista'])->falla());
    }

    public function test_acumula_varios_errores(): void
    {
        $v = Validator::make(['email' => 'mal', 'password' => 'x'], [
            'email'    => 'required|email',
            'password' => 'min:12',
        ]);

        $this->assertCount(2, $v->errores());
    }
}
