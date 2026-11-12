<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Core\Password;
use PHPUnit\Framework\TestCase;

final class PasswordTest extends TestCase
{
    public function test_usa_argon2id(): void
    {
        $hash = Password::hash('claveSegura123');

        $this->assertStringStartsWith('$argon2id$', $hash);
        $this->assertStringNotContainsString('claveSegura123', $hash);
    }

    public function test_verifica_la_contrasena_correcta(): void
    {
        $hash = Password::hash('claveSegura123');

        $this->assertTrue(Password::verificar('claveSegura123', $hash));
        $this->assertFalse(Password::verificar('otraClave123', $hash));
    }

    public function test_dos_hashes_de_la_misma_clave_son_distintos(): void
    {
        $this->assertNotSame(Password::hash('claveSegura123'), Password::hash('claveSegura123'));
    }

    public function test_no_necesita_rehash_con_argon2id(): void
    {
        $this->assertFalse(Password::necesitaRehash(Password::hash('claveSegura123')));
    }

    public function test_la_verificacion_ficticia_no_revienta(): void
    {
        Password::verificarFicticio('cualquierCosa123');

        $this->assertTrue(true);
    }
}
