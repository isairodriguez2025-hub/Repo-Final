<?php

declare(strict_types=1);

namespace App\Modules\Admin;

use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Core\View;
use PDO;

/**
 * Panel de administración.
 *
 * - GET /admin                    → dashboard
 * - GET /admin/usuarios           → listado de usuarios
 * - GET /admin/usuarios/{id}      → detalle de usuario
 * - POST /admin/usuarios/{id}/rol → cambiar rol
 * - POST /admin/usuarios/{id}/estado → cambiar estado
 * - GET /admin/reclamos           → listado de reclamos
 * - POST /admin/reclamos/{id}/resolver → resolver reclamo
 * - GET /admin/parametros         → parámetros versionados
 * - POST /admin/parametros/{clave} → actualizar parámetro
 */
final class AdminController
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly string $directorioVistas,
    ) {
    }

    private function vista(string $plantilla, array $datos, int $estado = 200): Response
    {
        $nonce = base64_encode(random_bytes(16));
        $vista = new View($this->directorioVistas, $nonce);
        $datos['nonce'] ??= $nonce;
        $datos['csrf'] ??= \App\Core\Csrf::token();

        return Response::html($vista->render($plantilla, $datos), $estado)->withNonce($nonce);
    }

    private function usuarioId(): int
    {
        return (int) Session::obtener('user_id', 0);
    }

    private function roles(): array
    {
        $roles = Session::obtener('roles', []);

        return is_array($roles) ? array_values($roles) : [];
    }

    private function esAdmin(): bool
    {
        return in_array('admin_total', $this->roles(), true);
    }

    // ------------------------------------------------------------------
    // Dashboard
    // ------------------------------------------------------------------

    public function dashboard(): Response
    {
        $totalUsuarios = (int) $this->pdo->query('SELECT COUNT(*) FROM usuarios')->fetchColumn();
        $totalComercios = (int) $this->pdo->query('SELECT COUNT(*) FROM comercios_perfil')->fetchColumn();
        $totalMayoristas = (int) $this->pdo->query('SELECT COUNT(*) FROM mayoristas_perfil')->fetchColumn();
        $pedidosHoy = (int) $this->pdo->query(
            "SELECT COUNT(*) FROM pedidos WHERE DATE(created_at) = CURDATE()"
        )->fetchColumn();
        $reclamosAbiertos = (int) $this->pdo->query(
            "SELECT COUNT(*) FROM reclamos WHERE estado = 'abierto'"
        )->fetchColumn();
        $kycPendientes = (int) $this->pdo->query(
            "SELECT COUNT(*) FROM mayoristas_perfil WHERE estado_kyc = 'pendiente'"
        )->fetchColumn();

        return $this->vista('admin/dashboard', [
            'titulo' => 'Panel de administración',
            'totalUsuarios' => $totalUsuarios,
            'totalComercios' => $totalComercios,
            'totalMayoristas' => $totalMayoristas,
            'pedidosHoy' => $pedidosHoy,
            'reclamosAbiertos' => $reclamosAbiertos,
            'kycPendientes' => $kycPendientes,
        ]);
    }

    // ------------------------------------------------------------------
    // Usuarios — CRUD ligero (listar + cambiar rol/estado)
    // ------------------------------------------------------------------

    public function listarUsuarios(Request $peticion): Response
    {
        $busqueda = trim((string) $peticion->query('q', ''));
        $rol = trim((string) $peticion->query('rol', ''));

        $sql = 'SELECT u.id, u.email, u.estado, u.created_at, GROUP_CONCAT(r.nombre) AS roles
                FROM usuarios u
                LEFT JOIN usuario_roles ur ON ur.usuario_id = u.id
                LEFT JOIN roles r ON r.id = ur.rol_id';
        $params = [];

        if ($busqueda !== '') {
            $sql .= ' WHERE u.email LIKE :busqueda';
            $params[':busqueda'] = '%' . $busqueda . '%';
        }

        $sql .= ' GROUP BY u.id ORDER BY u.created_at DESC LIMIT 100';
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);

        $usuarios = [];
        foreach ($stmt->fetchAll() ?: [] as $f) {
            $usuarios[] = [
                'id' => (int) $f['id'],
                'email' => $f['email'],
                'estado' => $f['estado'],
                'roles' => $f['roles'] ?? '',
                'creado' => $f['created_at'],
            ];
        }

        return $this->vista('admin/usuarios', [
            'titulo' => 'Usuarios',
            'usuarios' => $usuarios,
            'busqueda' => $busqueda,
            'rol' => $rol,
        ]);
    }

    public function detalleUsuario(Request $peticion, array $args): Response
    {
        $id = (int) $args['id'];
        $stmt = $this->pdo->prepare(
            'SELECT u.id, u.email, u.estado, u.created_at, u.updated_at,
                    GROUP_CONCAT(r.nombre) AS roles
             FROM usuarios u
             LEFT JOIN usuario_roles ur ON ur.usuario_id = u.id
             LEFT JOIN roles r ON r.id = ur.rol_id
             WHERE u.id = ? GROUP BY u.id LIMIT 1'
        );
        $stmt->execute([$id]);
        $usuario = $stmt->fetch();

        if ($usuario === false) {
            return Response::redirect('/admin/usuarios?error=' . urlencode('Usuario no encontrado.'));
        }

        // Pedidos del usuario (como comercio o mayorista)
        $stmt = $this->pdo->prepare(
            'SELECT id, uuid, total, estado, created_at FROM pedidos
             WHERE comercio_id = ? OR mayorista_id = ?
             ORDER BY created_at DESC LIMIT 20'
        );
        $stmt->execute([$id, $id]);
        $pedidos = $stmt->fetchAll() ?: [];

        return $this->vista('admin/usuario-detalle', [
            'titulo' => 'Usuario #' . $id,
            'usuario' => $usuario,
            'pedidos' => $pedidos,
        ]);
    }

    public function cambiarRol(Request $peticion, array $args): Response
    {
        if (!$this->esAdmin()) {
            return Response::json(['ok' => false, 'error' => ['code' => 'PROHIBIDO', 'message' => 'Sin permiso.']], 403);
        }

        $id = (int) $args['id'];
        $nuevoRol = trim((string) $peticion->input('rol'));
        $rolesValidos = ['comercio', 'mayorista', 'admin_total', 'admin_kyc', 'repartidor'];

        if (!in_array($nuevoRol, $rolesValidos, true)) {
            return Response::json(['ok' => false, 'error' => ['code' => 'ROL_INVALIDO', 'message' => 'Rol no válido.']], 422);
        }

        // Asegurar que el rol existe
        $stmt = $this->pdo->prepare('SELECT id FROM roles WHERE nombre = ? LIMIT 1');
        $stmt->execute([$nuevoRol]);
        $rolId = $stmt->fetchColumn();

        if ($rolId === false) {
            return Response::json(['ok' => false, 'error' => ['code' => 'ROL_NO_EXISTE', 'message' => 'El rol no existe.']], 422);
        }

        // Reemplazar todos los roles del usuario por el nuevo
        $this->pdo->prepare('DELETE FROM usuario_roles WHERE usuario_id = ?')->execute([$id]);
        $this->pdo->prepare('INSERT INTO usuario_roles (usuario_id, rol_id) VALUES (?, ?)')->execute([$id, (int) $rolId]);

        return Response::json(['ok' => true, 'mensaje' => 'Rol actualizado a ' . $nuevoRol]);
    }

    public function cambiarEstado(Request $peticion, array $args): Response
    {
        if (!$this->esAdmin()) {
            return Response::json(['ok' => false, 'error' => ['code' => 'PROHIBIDO', 'message' => 'Sin permiso.']], 403);
        }

        $id = (int) $args['id'];
        $nuevoEstado = trim((string) $peticion->input('estado'));

        if (!in_array($nuevoEstado, ['activo', 'suspendido'], true)) {
            return Response::json(['ok' => false, 'error' => ['code' => 'ESTADO_INVALIDO', 'message' => 'Estado no válido.']], 422);
        }

        $this->pdo->prepare('UPDATE usuarios SET estado = ? WHERE id = ?')->execute([$nuevoEstado, $id]);

        return Response::json(['ok' => true, 'mensaje' => 'Estado actualizado a ' . $nuevoEstado]);
    }

    // ------------------------------------------------------------------
    // Reclamos / Disputas
    // ------------------------------------------------------------------

    public function listarReclamos(Request $peticion): Response
    {
        $estado = trim((string) $peticion->query('estado', ''));
        $sql = 'SELECT r.*, p.uuid AS pedido_uuid, p.total AS pedido_total,
                       m.nombre_comercial AS mayorista, c.nombre_negocio AS comercio
                FROM reclamos r
                JOIN pedidos p ON p.id = r.pedido_id
                JOIN mayoristas_perfil m ON m.id = p.mayorista_id
                JOIN comercios_perfil c ON c.id = p.comercio_id';
        $params = [];

        if ($estado !== '' && in_array($estado, ['abierto', 'respondido', 'resuelto', 'rechazado'], true)) {
            $sql .= ' WHERE r.estado = :estado';
            $params[':estado'] = $estado;
        }

        $sql .= ' ORDER BY r.created_at DESC LIMIT 100';
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);

        $reclamos = [];
        foreach ($stmt->fetchAll() ?: [] as $f) {
            $reclamos[] = [
                'id' => (int) $f['id'],
                'uuid' => $f['uuid'],
                'pedido_uuid' => $f['pedido_uuid'],
                'tipo' => $f['tipo'],
                'estado' => $f['estado'],
                'descripcion' => $f['descripcion'],
                'resolucion' => $f['resolucion'],
                'mayorista' => $f['mayorista'],
                'comercio' => $f['comercio'],
                'pedido_total' => $f['pedido_total'],
                'creado' => $f['created_at'],
            ];
        }

        return $this->vista('admin/reclamos', [
            'titulo' => 'Reclamos y disputas',
            'reclamos' => $reclamos,
            'estadoFiltro' => $estado,
        ]);
    }

    public function resolverReclamo(Request $peticion, array $args): Response
    {
        if (!$this->esAdmin()) {
            return Response::json(['ok' => false, 'error' => ['code' => 'PROHIBIDO', 'message' => 'Sin permiso.']], 403);
        }

        $id = (int) $args['id'];
        $resolucion = trim((string) $peticion->input('resolucion'));
        $monto = $peticion->input('monto');
        $notas = trim((string) $peticion->input('notas', ''));

        $resolucionesValidas = ['reembolso_total', 'reembolso_parcial', 'sin_reembolso'];
        if (!in_array($resolucion, $resolucionesValidas, true)) {
            return Response::json(['ok' => false, 'error' => ['code' => 'RESOLUCION_INVALIDA', 'message' => 'Resolución no válida.']], 422);
        }

        $montoFloat = ($resolucion === 'reembolso_parcial' && $monto !== null)
            ? (float) $monto
            : null;

        if ($resolucion === 'reembolso_parcial' && $montoFloat === null) {
            return Response::json(['ok' => false, 'error' => ['code' => 'MONTO_REQUERIDO', 'message' => 'Se requiere un monto para reembolso parcial.']], 422);
        }

        // Verificar que el reclamo existe y está abierto
        $stmt = $this->pdo->prepare('SELECT estado FROM reclamos WHERE id = ? LIMIT 1');
        $stmt->execute([$id]);
        $estadoActual = $stmt->fetchColumn();

        if ($estadoActual === false) {
            return Response::json(['ok' => false, 'error' => ['code' => 'RECLAMO_NO_ENCONTRADO', 'message' => 'Reclamo no encontrado.']], 404);
        }
        if ($estadoActual !== 'abierto') {
            return Response::json(['ok' => false, 'error' => ['code' => 'RECLAMO_YA_RESUELTO', 'message' => 'Este reclamo ya fue resuelto.']], 409);
        }

        // Registrar valoración
        $this->pdo->prepare(
            'INSERT INTO reclamo_valoraciones (reclamo_id, admin_id, resolucion, monto, notas)
             VALUES (?, ?, ?, ?, ?)'
        )->execute([$id, $this->usuarioId(), $resolucion, $montoFloat, $notas]);

        // Actualizar estado del reclamo
        $nuevoEstado = $resolucion === 'sin_reembolso' ? 'rechazado' : 'resuelto';
        $this->pdo->prepare('UPDATE reclamos SET estado = ?, resolucion = ? WHERE id = ?')
            ->execute([$nuevoEstado, $resolucion, $id]);

        // Si es reembolso total, revertir el pedido a completado y liberar el dinero
        if ($resolucion === 'reembolso_total') {
            $this->pdo->prepare('UPDATE pedidos SET estado = ? WHERE id = (SELECT pedido_id FROM reclamos WHERE id = ?)')
                ->execute(['completado', $id]);
        }

        return Response::json(['ok' => true, 'mensaje' => 'Reclamo resuelto como ' . $resolucion]);
    }

    // ------------------------------------------------------------------
    // Parámetros versionados
    // ------------------------------------------------------------------

    public function listarParametros(): Response
    {
        $stmt = $this->pdo->query('SELECT * FROM parametros ORDER BY clave');
        $parametros = [];
        foreach ($stmt->fetchAll() ?: [] as $f) {
            $parametros[] = [
                'clave' => $f['clave'],
                'valor_actual' => $f['valor_actual'],
                'tipo' => $f['tipo'],
                'descripcion' => $f['descripcion'],
                'version' => (int) $f['version'],
                'cambiado_por' => $f['cambiado_por'],
                'cambiado_at' => $f['cambiado_at'],
            ];
        }

        return $this->vista('admin/parametros', [
            'titulo' => 'Parámetros del sistema',
            'parametros' => $parametros,
        ]);
    }

    public function actualizarParametro(Request $peticion, array $args): Response
    {
        if (!$this->esAdmin()) {
            return Response::json(['ok' => false, 'error' => ['code' => 'PROHIBIDO', 'message' => 'Sin permiso.']], 403);
        }

        $clave = trim((string) $args['clave']);
        $nuevoValor = trim((string) $peticion->input('valor_actual'));

        // Leer el actual
        $stmt = $this->pdo->prepare('SELECT * FROM parametros WHERE clave = ? LIMIT 1');
        $stmt->execute([$clave]);
        $actual = $stmt->fetch();

        if ($actual === false) {
            return Response::json(['ok' => false, 'error' => ['code' => 'PARAMETRO_NO_EXISTE', 'message' => 'El parámetro no existe.']], 404);
        }

        // Validar tipo
        $tipo = $actual['tipo'];
        $valorValido = match ($tipo) {
            'int' => preg_match('/^-?\d+$/', $nuevoValor),
            'float' => preg_match('/^-?[\d.]+$/', $nuevoValor),
            'bool' => in_array(strtolower($nuevoValor), ['true', 'false', '1', '0'], true),
            'json' => static fn () => json_decode($nuevoValor) !== null,
            default => true,
        };

        if (!$valorValido) {
            return Response::json(['ok' => false, 'error' => ['code' => 'VALOR_INVALIDO', 'message' => "El valor no es válido para el tipo {$tipo}."]], 422);
        }

        // Normalizar bool
        if ($tipo === 'bool') {
            $nuevoValor = in_array(strtolower($nuevoValor), ['true', '1'], true) ? 'true' : 'false';
        }

        // Guardar historial antes de actualizar
        $this->pdo->prepare(
            'INSERT INTO parametros_historial (parametro_id, valor_anterior, valor_nuevo, version, usuario_id, ip)
             VALUES (?, ?, ?, ?, ?, ?)'
        )->execute([
            (int) $actual['id'],
            $actual['valor_actual'],
            $nuevoValor,
            (int) $actual['version'] + 1,
            $this->usuarioId(),
            $peticion->ip(),
        ]);

        // Actualizar
        $this->pdo->prepare(
            'UPDATE parametros SET valor_actual = ?, version = version + 1, cambiado_por = ? WHERE clave = ?'
        )->execute([$nuevoValor, $this->usuarioId(), $clave]);

        return Response::json(['ok' => true, 'mensaje' => "Parámetro '{$clave}' actualizado a la versión " . ((int) $actual['version'] + 1)]);
    }

    public function historialParametro(Request $peticion, array $args): Response
    {
        $clave = trim((string) $args['clave']);

        $stmt = $this->pdo->prepare(
            'SELECT ph.*, u.email AS admin_email
             FROM parametros_historial ph
             LEFT JOIN usuarios u ON u.id = ph.usuario_id
             JOIN parametros p ON p.id = ph.parametro_id
             WHERE p.clave = ?
             ORDER BY ph.cambiado_at DESC
             LIMIT 50'
        );
        $stmt->execute([$clave]);
        $historial = $stmt->fetchAll() ?: [];

        return $this->vista('admin/parametro-historial', [
            'titulo' => 'Historial: ' . $clave,
            'clave' => $clave,
            'historial' => $historial,
        ]);
    }
}
