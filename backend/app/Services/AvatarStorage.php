<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\User;
use GdImage;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * La foto de perfil: se endereza, se recorta cuadrada y se reescribe.
 *
 * Nunca se guarda el archivo tal como llegó. Una foto de teléfono son 4 MB y
 * 4.000 píxeles de lado para mostrarse en un círculo de 36: servir el original
 * sería mandar mil veces lo que hace falta, y además arrastra los metadatos
 * de la cámara, que incluyen las coordenadas del lugar donde se tomó.
 *
 * El resultado es siempre el mismo: WebP de 256 px de lado. Un tamaño único
 * simplifica todo lo que viene después —no hay que decidir qué versión pedir
 * en cada pantalla— y 256 alcanza para el círculo más grande de la interfaz
 * con pantallas de doble densidad.
 */
final class AvatarStorage
{
    /** Lado del cuadrado final, en píxeles. */
    private const LADO = 256;

    /** Calidad WebP. Por debajo de 80 se nota en los bordes de la cara. */
    private const CALIDAD = 82;

    private const CARPETA = 'avatars';

    /**
     * Procesa la imagen, la guarda y deja la anterior en la basura.
     *
     * @return string la ruta relativa dentro del disco público
     */
    public function guardar(User $user, UploadedFile $archivo): string
    {
        $original = @imagecreatefromstring((string) file_get_contents($archivo->getRealPath()));

        if ($original === false) {
            throw new RuntimeException('El archivo no es una imagen que se pueda leer.');
        }

        $derecha = $this->enderezar($original, $archivo);
        $cuadrada = $this->recortarCuadrado($derecha);
        imagedestroy($derecha);

        // `imagewebp` escribe en la salida estándar cuando no se le da ruta:
        // así el binario se arma en memoria y lo guarda el disco de Laravel,
        // que es quien sabe dónde va y con qué permisos.
        ob_start();
        imagewebp($cuadrada, null, self::CALIDAD);
        $binario = (string) ob_get_clean();
        imagedestroy($cuadrada);

        // El nombre lleva un tramo al azar a propósito: si la ruta fuera fija
        // por persona, el navegador seguiría mostrando la foto vieja de su
        // caché después de cambiarla.
        $ruta = self::CARPETA.'/'.$user->id.'-'.Str::lower(Str::random(8)).'.webp';

        Storage::disk('public')->put($ruta, $binario);

        $anterior = $user->avatar_path;
        $user->forceFill(['avatar_path' => $ruta])->save();

        // Recién ahora: si se borrara antes de guardar y algo fallara en el
        // medio, la persona quedaría sin foto y sin manera de recuperarla.
        $this->borrarArchivo($anterior);

        return $ruta;
    }

    /**
     * Quita la foto y deja a la persona con sus iniciales.
     */
    public function borrar(User $user): void
    {
        $ruta = $user->avatar_path;

        $user->forceFill(['avatar_path' => null])->save();

        $this->borrarArchivo($ruta);
    }

    // -----------------------------------------------------------------

    /**
     * Aplica la orientación que la cámara anotó en el EXIF.
     *
     * Un teléfono no rota los píxeles al sacar una foto vertical: guarda el
     * sensor tal cual y deja una nota diciendo cómo hay que girarlo. GD ignora
     * esa nota, así que sin esto los retratos entran acostados.
     */
    private function enderezar(GdImage $imagen, UploadedFile $archivo): GdImage
    {
        if (! function_exists('exif_read_data') || $archivo->getMimeType() !== 'image/jpeg') {
            return $imagen;
        }

        $exif = @exif_read_data($archivo->getRealPath());

        // 3, 6 y 8 son los tres giros. Los otros valores son espejados, que no
        // los produce ninguna cámara de teléfono.
        $grados = match ((int) ($exif['Orientation'] ?? 1)) {
            3 => 180,
            6 => -90,
            8 => 90,
            default => 0,
        };

        if ($grados === 0) {
            return $imagen;
        }

        $rotada = imagerotate($imagen, $grados, 0);

        if ($rotada === false) {
            return $imagen;
        }

        imagedestroy($imagen);

        return $rotada;
    }

    /**
     * Recorta el cuadrado más grande que entre y lo lleva a 256 px.
     */
    private function recortarCuadrado(GdImage $imagen): GdImage
    {
        $ancho = imagesx($imagen);
        $alto = imagesy($imagen);
        $lado = min($ancho, $alto);

        // En una foto vertical la cara cae más arriba que el centro
        // geométrico —el encuadre siempre deja aire de torso abajo—, así que
        // recortar por la mitad decapita a medio directorio. Se toma a un
        // tercio de la altura sobrante, que es donde caen los ojos.
        $y = $alto > $ancho ? intdiv($alto - $lado, 3) : intdiv($alto - $lado, 2);
        $x = intdiv($ancho - $lado, 2);

        $destino = imagecreatetruecolor(self::LADO, self::LADO);

        // Un PNG con fondo transparente tiene que seguir siéndolo: sin estas
        // dos líneas, GD rellena el hueco de negro.
        imagealphablending($destino, false);
        imagesavealpha($destino, true);

        imagecopyresampled(
            $destino, $imagen,
            0, 0,
            $x, $y,
            self::LADO, self::LADO,
            $lado, $lado,
        );

        return $destino;
    }

    private function borrarArchivo(?string $ruta): void
    {
        // El prefijo se comprueba porque `avatar_path` es una columna de texto
        // y esto termina en un borrado: si alguna vez guardara otra cosa, que
        // no se lleve puesto nada de afuera de la carpeta de fotos.
        if ($ruta && str_starts_with($ruta, self::CARPETA.'/')) {
            Storage::disk('public')->delete($ruta);
        }
    }
}
