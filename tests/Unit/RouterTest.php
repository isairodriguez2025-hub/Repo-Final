<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Core\Middleware;
use App\Core\Request;
use App\Core\Response;
use App\Core\Router;
use PHPUnit\Framework\TestCase;

final class RouterTest extends TestCase
{
    private function peticion(string $metodo, string $ruta): Request
    {
        return new Request($metodo, $ruta, [], [], [], [], '');
    }

    public function test_ruta_simple(): void
    {
        $router = new Router();
        $router->get('/hola', static fn (): Response => Response::json(['ok' => true]));

        $r = $router->dispatch($this->peticion('GET', '/hola'));

        $this->assertSame(200, $r->status());
    }

    public function test_ruta_inexistente_da_404_generico(): void
    {
        $router = new Router();
        $r = $router->dispatch($this->peticion('GET', '/no-existe'));

        $this->assertSame(404, $r->status());
        $this->assertStringContainsString('NOT_FOUND', $r->body());
        $this->assertStringNotContainsString('vendor', $r->body());
    }

    public function test_metodo_no_permitido_da_405(): void
    {
        $router = new Router();
        $router->get('/solo-get', static fn (): Response => Response::json([]));

        $r = $router->dispatch($this->peticion('POST', '/solo-get'));

        $this->assertSame(405, $r->status());
    }

    public function test_pasa_argumentos_de_la_ruta(): void
    {
        $router = new Router();
        $router->get('/producto/{id:\d+}', static fn (Request $p, array $a): Response => Response::json(['id' => $a['id']]));

        $r = $router->dispatch($this->peticion('GET', '/producto/42'));

        $this->assertStringContainsString('"id":"42"', $r->body());
    }

    public function test_los_middleware_se_ejecutan_en_orden(): void
    {
        $orden = [];

        $mw = static function (string $nombre) use (&$orden): Middleware {
            return new class ($nombre, $orden) implements Middleware {
                public function __construct(private string $nombre, private array &$orden)
                {
                }

                public function handle(Request $peticion, callable $siguiente): Response
                {
                    $this->orden[] = $this->nombre;

                    return $siguiente($peticion);
                }
            };
        };

        $router = new Router();
        $router->get('/cadena', static function () use (&$orden): Response {
            $orden[] = 'controlador';

            return Response::json([]);
        }, [$mw('primero'), $mw('segundo')]);

        $router->dispatch($this->peticion('GET', '/cadena'));

        $this->assertSame(['primero', 'segundo', 'controlador'], $orden);
    }

    public function test_un_middleware_puede_cortar_la_cadena(): void
    {
        $router = new Router();
        $router->get('/bloqueado', static fn (): Response => Response::json(['ok' => true]), [
            new class implements Middleware {
                public function handle(Request $peticion, callable $siguiente): Response
                {
                    return Response::json(['ok' => false], 403);
                }
            },
        ]);

        $r = $router->dispatch($this->peticion('GET', '/bloqueado'));

        $this->assertSame(403, $r->status());
    }
}
