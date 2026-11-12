<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Core\Upload;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class UploadTest extends TestCase
{
    private string $directorio;

    protected function setUp(): void
    {
        $this->directorio = sys_get_temp_dir() . '/didi2-upload-' . bin2hex(random_bytes(4));
        mkdir($this->directorio, 0775, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->directorio . '/*') ?: [] as $f) {
            @unlink($f);
        }
        @rmdir($this->directorio);
    }

    private function archivo(string $contenido, string $nombre): array
    {
        $ruta = $this->directorio . '/origen-' . $nombre;
        file_put_contents($ruta, $contenido);

        return ['tmp_name' => $ruta, 'error' => UPLOAD_ERR_OK, 'size' => filesize($ruta), 'name' => $nombre];
    }

    public function test_rechaza_un_php_disfrazado_de_jpg(): void
    {
        $archivo = $this->archivo('<?php echo "malicioso"; ?>', 'foto.jpg');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Solo se aceptan JPG, PNG o PDF');

        Upload::guardar($archivo, $this->directorio);
    }

    public function test_rechaza_un_archivo_vacio(): void
    {
        $archivo = $this->archivo('', 'vacio.pdf');

        $this->expectException(RuntimeException::class);

        Upload::guardar($archivo, $this->directorio);
    }

    public function test_acepta_un_pdf_y_le_pone_nombre_aleatorio(): void
    {
        $archivo = $this->archivo("%PDF-1.4\n1 0 obj<</Type/Catalog>>endobj\ntrailer<</Root 1 0 R>>\n%%EOF\n", 'constancia.pdf');

        $resultado = Upload::guardar($archivo, $this->directorio);

        $this->assertSame('application/pdf', $resultado['mime']);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{32}\.pdf$/', $resultado['ruta']);
        $this->assertFileExists($this->directorio . '/' . $resultado['ruta']);
        $this->assertStringNotContainsString('constancia', $resultado['ruta']);
    }

    public function test_el_archivo_guardado_no_es_ejecutable(): void
    {
        $archivo = $this->archivo("%PDF-1.4\n%%EOF\n", 'x.pdf');
        $resultado = Upload::guardar($archivo, $this->directorio);

        $permisos = substr(sprintf('%o', fileperms($this->directorio . '/' . $resultado['ruta'])), -3);

        $this->assertSame('640', $permisos);
    }
}
