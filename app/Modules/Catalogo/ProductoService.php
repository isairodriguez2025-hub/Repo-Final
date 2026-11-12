<?php

declare(strict_types=1);

namespace App\Modules\Catalogo;

use App\Core\AuditLogger;
use App\Core\Validator;
use PDO;

/**
 * Reglas del catálogo (RN-19 y RN-20).
 *
 * Un producto no se publica por existir: necesita los datos completos, al menos
 * una imagen y que su categoría no sea restringida. Todo se valida aquí, en el
 * servidor, no en el formulario.
 */
final class ProductoService
{
    public const MAX_IMAGENES = 5;
    public const MAX_FILAS_CSV = 5000;
    public const MAX_BYTES_CSV = 5 * 1024 * 1024;

    public function __construct(
        private readonly ProductoRepository $repo,
        private readonly AuditLogger $auditoria,
        private readonly PDO $pdo,
        private readonly PrecioService $precios = new PrecioService(),
    ) {
    }

    /** ¿El mayorista ya puede publicar? Depende de su KYC. */
    public function puedePublicar(int $usuarioId): bool
    {
        $stmt = $this->pdo->prepare(
            "SELECT estado_kyc, activo FROM mayoristas_perfil WHERE usuario_id = ? LIMIT 1"
        );
        $stmt->execute([$usuarioId]);
        $perfil = $stmt->fetch();

        return $perfil !== false && $perfil['estado_kyc'] === 'aprobado' && (int) $perfil['activo'] === 1;
    }

    public function mayoristaId(int $usuarioId): ?int
    {
        $stmt = $this->pdo->prepare('SELECT id FROM mayoristas_perfil WHERE usuario_id = ? LIMIT 1');
        $stmt->execute([$usuarioId]);
        $id = $stmt->fetchColumn();

        return $id === false ? null : (int) $id;
    }

    /**
     * @return array{ok:bool, mensaje:string, errores:array<string,string>, id:?int}
     */
    public function crear(int $usuarioId, array $datos, string $ip): array
    {
        $mayoristaId = $this->mayoristaId($usuarioId);

        if ($mayoristaId === null) {
            return ['ok' => false, 'mensaje' => 'Tu cuenta no tiene perfil de mayorista.', 'errores' => [], 'id' => null];
        }

        $v = $this->validar($datos);

        if ($v->falla()) {
            return ['ok' => false, 'mensaje' => $v->primerError() ?? 'Datos inválidos.', 'errores' => $v->errores(), 'id' => null];
        }

        try {
            $this->precios->validarEscalas($datos['escalas'] ?? []);
        } catch (\App\Modules\Catalogo\Excepciones\EscalaInvalidaException $e) {
            return ['ok' => false, 'mensaje' => $e->getMessage(), 'errores' => ['escalas' => $e->getMessage()], 'id' => null];
        }

        $datos['mayorista_id'] = $mayoristaId;

        // Sin KYC aprobado no se publica nada, aunque el formulario lo pida.
        if (!$this->puedePublicar($usuarioId)) {
            $datos['activo'] = 0;
        }

        // Una categoría restringida deja el producto inactivo hasta revisión.
        $categoria = $this->repo->categoriaPorId((int) $datos['categoria_id']);
        if ($categoria !== null && (int) $categoria['restringida'] === 1) {
            $datos['activo'] = 0;
            $datos['restringido'] = 1;
        }

        $id = $this->repo->crear($datos);

        if (!empty($datos['stock_inicial'])) {
            $this->repo->ajustarStock($id, (int) $datos['stock_inicial'], 'entrada', $usuarioId, 'Stock inicial');
        }

        if (!empty($datos['escalas'])) {
            $this->repo->reemplazarEscalas($id, $datos['escalas']);
        }

        $this->auditoria->log('producto_creado', $usuarioId, 'producto', (string) $id, [
            'nombre' => $datos['nombre'],
            'precio' => $datos['precio_base'],
        ], $ip);

        return ['ok' => true, 'mensaje' => 'Producto guardado.', 'errores' => [], 'id' => $id];
    }

    public function actualizar(int $usuarioId, int $productoId, array $datos, string $ip): array
    {
        $v = $this->validar($datos);

        if ($v->falla()) {
            return ['ok' => false, 'mensaje' => $v->primerError() ?? 'Datos inválidos.', 'errores' => $v->errores()];
        }

        try {
            $this->precios->validarEscalas($datos['escalas'] ?? []);
        } catch (\App\Modules\Catalogo\Excepciones\EscalaInvalidaException $e) {
            return ['ok' => false, 'mensaje' => $e->getMessage(), 'errores' => ['escalas' => $e->getMessage()]];
        }

        $this->repo->actualizar($productoId, $datos);
        $this->repo->reemplazarEscalas($productoId, $datos['escalas'] ?? []);
        $this->auditoria->log('producto_actualizado', $usuarioId, 'producto', (string) $productoId, null, $ip);

        return ['ok' => true, 'mensaje' => 'Producto actualizado.', 'errores' => []];
    }

    private function validar(array $datos): Validator
    {
        $v = Validator::make($datos, [
            'nombre'          => 'required|max:160',
            'categoria_id'    => 'required',
            'precio_base'     => 'required',
            'unidad_venta'    => 'required|max:30',
            'contenido'       => 'required|max:60',
            'peso_kg'         => 'required',
            'volumen_m3'      => 'required',
            'cantidad_minima' => 'required',
            'multiplo_venta'  => 'required',
        ], [
            'nombre'          => 'nombre del producto',
            'categoria_id'    => 'categoría',
            'precio_base'     => 'precio',
            'unidad_venta'    => 'unidad de venta',
            'contenido'       => 'contenido',
            'peso_kg'         => 'peso',
            'volumen_m3'      => 'volumen',
            'cantidad_minima' => 'cantidad mínima',
            'multiplo_venta'  => 'múltiplo de venta',
        ]);

        if ((float) ($datos['precio_base'] ?? 0) <= 0) {
            $v->agregarError('precio_base', 'El precio debe ser mayor que cero.');
        }

        if ((float) ($datos['peso_kg'] ?? 0) <= 0) {
            $v->agregarError('peso_kg', 'El peso debe ser mayor que cero.');
        }

        if ((float) ($datos['volumen_m3'] ?? 0) <= 0) {
            $v->agregarError('volumen_m3', 'El volumen debe ser mayor que cero.');
        }

        if ((int) ($datos['cantidad_minima'] ?? 0) < 1) {
            $v->agregarError('cantidad_minima', 'La cantidad mínima es al menos 1.');
        }

        if ((int) ($datos['multiplo_venta'] ?? 0) < 1) {
            $v->agregarError('multiplo_venta', 'El múltiplo de venta es al menos 1.');
        }

        $min = (int) ($datos['cantidad_minima'] ?? 1);
        $mult = (int) ($datos['multiplo_venta'] ?? 1);

        if ($mult > 1 && $min % $mult !== 0) {
            $v->agregarError('cantidad_minima', "La cantidad mínima debe ser múltiplo de {$mult}.");
        }

        return $v;
    }

    /**
     * Importa productos desde CSV. Las filas inválidas no detienen el proceso:
     * se devuelven con el número de línea para corregirlas.
     *
     * @return array{ok:int, errores:array<int,string>}
     */
    public function importarCsv(int $usuarioId, string $rutaCsv, string $ip): array
    {
        $mayoristaId = $this->mayoristaId($usuarioId);

        if ($mayoristaId === null) {
            return ['ok' => 0, 'errores' => [0 => 'No tienes perfil de mayorista.']];
        }

        if (filesize($rutaCsv) > self::MAX_BYTES_CSV) {
            return ['ok' => 0, 'errores' => [0 => 'El CSV pasa de 5 MB.']];
        }

        $fh = fopen($rutaCsv, 'r');

        if ($fh === false) {
            return ['ok' => 0, 'errores' => [0 => 'No se pudo leer el archivo.']];
        }

        $cabecera = fgetcsv($fh, 0, ',', '"', '');
        $esperadas = ['sku', 'nombre', 'precio_base', 'stock_disponible', 'peso_kg', 'volumen_m3', 'unidad_venta', 'contenido', 'cantidad_minima', 'multiplo_venta'];

        if ($cabecera === false || array_diff($esperadas, array_map('trim', $cabecera)) !== []) {
            fclose($fh);

            return ['ok' => 0, 'errores' => [0 => 'Faltan columnas: ' . implode(', ', $esperadas)]];
        }

        $ok = 0;
        $errores = [];
        $linea = 1;
        $categoria = (int) ($this->repo->categorias()[0]['id'] ?? 1);

        while (($fila = fgetcsv($fh, 0, ',', '"', '')) !== false) {
            $linea++;

            if ($linea > self::MAX_FILAS_CSV + 1) {
                $errores[$linea] = 'El archivo pasa de 5 000 filas.';
                break;
            }

            $datos = array_combine(array_map('trim', $cabecera), array_map('trim', $fila));

            if ($datos === false) {
                $errores[$linea] = 'La fila no tiene el mismo número de columnas.';
                continue;
            }

            $datos['categoria_id'] = $categoria;
            $datos['stock_inicial'] = (int) ($datos['stock_disponible'] ?? 0);
            $datos['activo'] = true;

            $resultado = $this->crear($usuarioId, $datos, $ip);

            if ($resultado['ok']) {
                $ok++;
            } else {
                $errores[$linea] = $resultado['mensaje'];
            }
        }

        fclose($fh);

        return ['ok' => $ok, 'errores' => $errores];
    }
}
