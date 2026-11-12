<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Validador de datos de entrada.
 *
 * Las reglas se escriben como texto: 'required|email|max:190'. El servidor
 * vuelve a validar TODO: lo que haga el navegador es comodidad, no seguridad.
 */
final class Validator
{
    /** @var array<string, string> */
    private array $errores = [];

    private function __construct(private readonly array $datos)
    {
    }

    public static function make(array $datos, array $reglas, array $etiquetas = []): self
    {
        $v = new self($datos);

        foreach ($reglas as $campo => $cadenaReglas) {
            $valor = $datos[$campo] ?? null;
            $valor = is_string($valor) ? trim($valor) : $valor;
            $etiqueta = $etiquetas[$campo] ?? $campo;

            foreach (explode('|', $cadenaReglas) as $regla) {
                [$nombre, $parametro] = array_pad(explode(':', $regla, 2), 2, null);
                $v->aplicar($campo, $etiqueta, $nombre, $parametro, $valor);
            }
        }

        return $v;
    }

    private function aplicar(string $campo, string $etiqueta, string $regla, ?string $parametro, mixed $valor): void
    {
        $vacio = $valor === null || $valor === '' || $valor === [];

        switch ($regla) {
            case 'required':
                if ($vacio) {
                    $this->errores[$campo] = "El campo {$etiqueta} es obligatorio.";
                }
                break;

            case 'email':
                if (!$vacio && !filter_var((string) $valor, FILTER_VALIDATE_EMAIL)) {
                    $this->errores[$campo] = 'El correo no tiene un formato válido.';
                }
                break;

            case 'min':
                if (!$vacio && mb_strlen((string) $valor) < (int) $parametro) {
                    $this->errores[$campo] = "El campo {$etiqueta} debe tener al menos {$parametro} caracteres.";
                }
                break;

            case 'max':
                if (!$vacio && mb_strlen((string) $valor) > (int) $parametro) {
                    $this->errores[$campo] = "El campo {$etiqueta} no puede pasar de {$parametro} caracteres.";
                }
                break;

            case 'telefono':
                if (!$vacio && !preg_match('/^[0-9]{10}$/', (string) $valor)) {
                    $this->errores[$campo] = 'El teléfono debe tener 10 dígitos.';
                }
                break;

            case 'in':
                $permitidos = explode(',', (string) $parametro);
                if (!$vacio && !in_array((string) $valor, $permitidos, true)) {
                    $this->errores[$campo] = "El campo {$etiqueta} no tiene un valor permitido.";
                }
                break;
        }
    }

    /** Añade un error que no viene de una regla sino de una regla de negocio. */
    public function agregarError(string $campo, string $mensaje): void
    {
        $this->errores[$campo] = $mensaje;
    }

    public function falla(): bool
    {
        return $this->errores !== [];
    }

    /** @return array<string, string> */
    public function errores(): array
    {
        return $this->errores;
    }

    public function primerError(): ?string
    {
        return $this->errores === [] ? null : reset($this->errores);
    }
}
