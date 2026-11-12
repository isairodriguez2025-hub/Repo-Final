<?php

declare(strict_types=1);

namespace App\Core;

use RuntimeException;

/**
 * Guarda archivos subidos de forma segura.
 *
 * Reglas (SEG-10):
 *  - El tipo se decide por el CONTENIDO (finfo), nunca por la extensión.
 *  - Solo se aceptan JPEG, PNG y PDF.
 *  - Nombre aleatorio: el original no toca el disco.
 *  - Se guarda fuera de public/ y con permisos 0640.
 *  - Las imágenes se re-codifican para eliminar metadatos EXIF.
 */
final class Upload
{
    public const MAX_BYTES = 10 * 1024 * 1024;

    /** MIME real => extensión con la que se guarda. */
    public const PERMITIDOS = [
        'image/jpeg'      => 'jpg',
        'image/png'       => 'png',
        'application/pdf' => 'pdf',
    ];

    /**
     * @param array{tmp_name?:string,error?:int,size?:int,name?:string} $archivo
     * @return array{ruta:string, mime:string, bytes:int}
     */
    public static function guardar(array $archivo, string $directorio): array
    {
        $error = (int) ($archivo['error'] ?? UPLOAD_ERR_NO_FILE);

        if ($error !== UPLOAD_ERR_OK) {
            throw new RuntimeException('La subida falló (código ' . $error . ').');
        }

        $tmp = (string) ($archivo['tmp_name'] ?? '');

        if ($tmp === '' || !is_file($tmp)) {
            throw new RuntimeException('No se recibió ningún archivo.');
        }

        $bytes = (int) filesize($tmp);

        if ($bytes <= 0) {
            throw new RuntimeException('El archivo está vacío.');
        }

        if ($bytes > self::MAX_BYTES) {
            throw new RuntimeException('El archivo pasa de 10 MB.');
        }

        // Tipo real por contenido, no por nombre.
        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $mime = (string) $finfo->file($tmp);

        if (!isset(self::PERMITIDOS[$mime])) {
            throw new RuntimeException('Solo se aceptan JPG, PNG o PDF.');
        }

        $extension = self::PERMITIDOS[$mime];

        if (!is_dir($directorio)) {
            mkdir($directorio, 0750, true);
        }

        $nombre = bin2hex(random_bytes(16)) . '.' . $extension;
        $destino = rtrim($directorio, '/') . '/' . $nombre;

        if ($mime === 'application/pdf') {
            if (!move_uploaded_file($tmp, $destino) && !rename($tmp, $destino)) {
                throw new RuntimeException('No se pudo guardar el archivo.');
            }
        } else {
            // Re-codificar la imagen elimina EXIF y cualquier carga adjunta.
            self::reprocesarImagen($tmp, $destino, $mime);
        }

        chmod($destino, 0640);

        return ['ruta' => $nombre, 'mime' => $mime, 'bytes' => (int) filesize($destino)];
    }

    private static function reprocesarImagen(string $origen, string $destino, string $mime): void
    {
        $imagen = match ($mime) {
            'image/jpeg' => @imagecreatefromjpeg($origen),
            'image/png'  => @imagecreatefrompng($origen),
            default      => false,
        };

        if ($imagen === false) {
            throw new RuntimeException('La imagen está dañada o no se pudo leer.');
        }

        // Se limita el tamaño para que un archivo enorme no llene el disco.
        $ancho = imagesx($imagen);
        $alto = imagesy($imagen);
        $maximo = 2000;

        if ($ancho > $maximo || $alto > $maximo) {
            $escala = min($maximo / $ancho, $maximo / $alto);
            $nueva = imagescale($imagen, (int) ($ancho * $escala), (int) ($alto * $escala));
            if ($nueva !== false) {
                imagedestroy($imagen);
                $imagen = $nueva;
            }
        }

        $ok = $mime === 'image/jpeg'
            ? imagejpeg($imagen, $destino, 88)
            : imagepng($imagen, $destino, 6);

        imagedestroy($imagen);

        if (!$ok) {
            throw new RuntimeException('No se pudo procesar la imagen.');
        }
    }
}
