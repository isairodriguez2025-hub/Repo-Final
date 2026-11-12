<?php

declare(strict_types=1);

namespace App\Modules\Carrito;

use App\Core\Csrf;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Core\View;
use App\Modules\Pedidos\CheckoutService;
use App\Modules\Perfil\PerfilRepository;
use PDO;

final class CarritoController
{
    public function __construct(
        private readonly CarritoService $carrito,
        private readonly CheckoutService $checkout,
        private readonly PerfilRepository $perfiles,
        private readonly PDO $pdo,
        private readonly string $directorioVistas,
    ) {
    }

    private function vista(string $plantilla, array $datos, int $estado = 200): Response
    {
        $nonce = base64_encode(random_bytes(16));
        $vista = new View($this->directorioVistas, $nonce);

        $datos['nonce'] ??= $nonce;
        $datos['csrf'] ??= Csrf::token();

        return Response::html($vista->render($plantilla, $datos), $estado)->withNonce($nonce);
    }

    private function usuarioId(): int
    {
        return (int) Session::obtener('user_id', 0);
    }

    /** El carrito cuelga del perfil de comercio, no del usuario. */
    private function comercioId(): ?int
    {
        $stmt = $this->pdo->prepare('SELECT id FROM comercios_perfil WHERE usuario_id = ? LIMIT 1');
        $stmt->execute([$this->usuarioId()]);
        $id = $stmt->fetchColumn();

        return $id === false ? null : (int) $id;
    }

    public function ver(Request $peticion): Response
    {
        $comercioId = $this->comercioId();

        if ($comercioId === null) {
            return $this->vista('carrito/sin-perfil', ['titulo' => 'Carrito']);
        }

        return $this->vista('carrito/index', [
            'titulo'  => 'Mi carrito',
            'resumen' => $this->carrito->recalcular($comercioId),
            'mensaje' => $peticion->query('ok') !== null ? 'Carrito actualizado.' : null,
            'error'   => $peticion->query('error'),
        ]);
    }

    public function agregar(Request $peticion): Response
    {
        $comercioId = $this->comercioId();

        if ($comercioId === null) {
            return Response::redirect('/perfil');
        }

        $resultado = $this->carrito->agregarItem(
            $comercioId,
            (string) $peticion->input('producto', ''),
            (int) $peticion->input('cantidad', 1),
        );

        $destino = (string) $peticion->input('volver', '/carrito');

        return Response::redirect($destino . ($resultado['ok'] ? '?ok=1' : '?error=' . urlencode($resultado['mensaje'])));
    }

    public function quitar(Request $peticion): Response
    {
        $comercioId = $this->comercioId();

        if ($comercioId !== null) {
            $this->carrito->quitar($comercioId, (string) $peticion->input('producto', ''));
        }

        return Response::redirect('/carrito?ok=1');
    }

    public function vaciar(Request $peticion): Response
    {
        $comercioId = $this->comercioId();

        if ($comercioId !== null) {
            $this->carrito->vaciar($comercioId);
        }

        return Response::redirect('/carrito');
    }

    public function checkoutForm(Request $peticion): Response
    {
        $comercioId = $this->comercioId();

        if ($comercioId === null) {
            return Response::redirect('/perfil');
        }

        $resumen = $this->carrito->recalcular($comercioId);

        return $this->vista('carrito/checkout', [
            'titulo'      => 'Confirmar pedido',
            'resumen'     => $resumen,
            'direcciones' => $this->perfiles->listarDirecciones($this->usuarioId()),
            'idempotency' => bin2hex(random_bytes(16)),
            'error'       => null,
        ]);
    }

    public function checkout(Request $peticion): Response
    {
        $comercioId = $this->comercioId();

        if ($comercioId === null) {
            return Response::redirect('/perfil');
        }

        $resultado = $this->checkout->crearCheckout(
            $comercioId,
            (string) $peticion->input('idempotency_key', ''),
            (int) $peticion->input('direccion_id', 0),
            $this->usuarioId(),
        );

        if (!$resultado['ok']) {
            $resumen = $this->carrito->recalcular($comercioId);

            return $this->vista('carrito/checkout', [
                'titulo'      => 'Confirmar pedido',
                'resumen'     => $resumen,
                'direcciones' => $this->perfiles->listarDirecciones($this->usuarioId()),
                'idempotency' => bin2hex(random_bytes(16)),
                'error'       => $resultado['mensaje'],
            ], 422);
        }

        return $this->vista('carrito/confirmado', [
            'titulo' => 'Pedido confirmado',
            'data'   => $resultado['data'],
        ]);
    }
}
