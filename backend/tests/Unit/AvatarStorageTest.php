<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\User;
use App\Services\AvatarStorage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Lo que hace `AvatarStorage` con la imagen.
 *
 * Se prueba esto y no la pantalla por la misma razón que el buscador de
 * personas: es la parte que **no se ve mirando**. Que el recorte tome la
 * franja de arriba o la del medio, o que la foto entre girada, son cosas que
 * en un círculo de 36 píxeles pasan perfectamente desapercibidas hasta que
 * alguien mira su propia foto de cerca.
 */
class AvatarStorageTest extends TestCase
{
    use RefreshDatabase;

    private function persona(): User
    {
        return User::factory()->create();
    }

    /** Arma un JPEG con tres franjas horizontales de colores distintos. */
    private function franjas(int $ancho, int $alto, ?int $orientacionExif = null): UploadedFile
    {
        $im = imagecreatetruecolor($ancho, $alto);
        $tercio = intdiv($alto, 3);

        imagefilledrectangle($im, 0, 0, $ancho, $tercio, imagecolorallocate($im, 255, 0, 0));
        imagefilledrectangle($im, 0, $tercio, $ancho, 2 * $tercio, imagecolorallocate($im, 0, 255, 0));
        imagefilledrectangle($im, 0, 2 * $tercio, $ancho, $alto, imagecolorallocate($im, 0, 0, 255));

        $ruta = tempnam(sys_get_temp_dir(), 'foto').'.jpg';
        imagejpeg($im, $ruta, 95);
        imagedestroy($im);

        if ($orientacionExif !== null) {
            $this->marcarOrientacion($ruta, $orientacionExif);
        }

        return new UploadedFile($ruta, 'foto.jpg', 'image/jpeg', null, true);
    }

    /**
     * Color aproximado de un punto del resultado: «rojo», «verde» o «azul».
     *
     * Las dos coordenadas van en tanto por uno del lado, para poder mirar
     * tanto de arriba abajo —el recorte— como de izquierda a derecha, que es
     * donde se nota el giro.
     */
    private function franjaEn(string $webp, float $x, float $y): string
    {
        $im = imagecreatefromstring($webp);
        $color = imagecolorat($im, (int) (imagesx($im) * $x), (int) (imagesy($im) * $y));

        $r = ($color >> 16) & 0xFF;
        $g = ($color >> 8) & 0xFF;
        $b = $color & 0xFF;
        imagedestroy($im);

        return match (max($r, $g, $b)) {
            $r => 'rojo',
            $g => 'verde',
            default => 'azul',
        };
    }

    public function test_deja_la_foto_cuadrada_de_256_y_en_webp(): void
    {
        Storage::fake('public');

        $ruta = app(AvatarStorage::class)->guardar($this->persona(), $this->franjas(1200, 1600));

        $this->assertStringEndsWith('.webp', $ruta);

        $binario = Storage::disk('public')->get($ruta);
        [$ancho, $alto, $tipo] = getimagesizefromstring($binario);

        $this->assertSame([256, 256], [$ancho, $alto]);
        $this->assertSame(IMAGETYPE_WEBP, $tipo);
    }

    public function test_en_una_foto_vertical_el_recorte_tira_hacia_arriba(): void
    {
        Storage::fake('public');

        // 1200x1600: sobran 400 px de alto. Por el centro se recortaría desde
        // el píxel 200; a un tercio, desde el 133. La diferencia se ve en qué
        // franja queda arriba del cuadrado.
        $ruta = app(AvatarStorage::class)->guardar($this->persona(), $this->franjas(1200, 1600));
        $binario = Storage::disk('public')->get($ruta);

        // Arriba tiene que quedar rojo —la franja alta del original— y no el
        // verde del medio, que es lo que daría un recorte centrado.
        $this->assertSame('rojo', $this->franjaEn($binario, 0.5, 0.05));
        $this->assertSame('verde', $this->franjaEn($binario, 0.5, 0.5));
    }

    public function test_endereza_la_foto_segun_lo_que_anoto_la_camara(): void
    {
        if (! function_exists('exif_read_data')) {
            $this->markTestSkipped('Sin la extensión exif no hay orientación que leer.');
        }

        Storage::fake('public');

        // Orientación 6: el teléfono guardó el sensor acostado y anotó que hay
        // que girarlo un cuarto de vuelta en sentido horario. El original es
        // apaisado con las franjas horizontales; al enderezarlo quedan
        // verticales, y la que estaba arriba —la roja— termina a la derecha.
        $ruta = app(AvatarStorage::class)->guardar($this->persona(), $this->franjas(1600, 1200, 6));
        $binario = Storage::disk('public')->get($ruta);

        $this->assertSame('azul', $this->franjaEn($binario, 0.05, 0.5));
        $this->assertSame('rojo', $this->franjaEn($binario, 0.95, 0.5));

        // Y sin enderezar seguirían horizontales: esto es justo lo que
        // distingue una foto derecha de una acostada.
        $this->assertSame('verde', $this->franjaEn($binario, 0.5, 0.05));
    }

    public function test_la_foto_anterior_no_queda_ocupando_disco(): void
    {
        Storage::fake('public');

        $persona = $this->persona();
        $servicio = app(AvatarStorage::class);

        $primera = $servicio->guardar($persona, $this->franjas(600, 600));
        $segunda = $servicio->guardar($persona->fresh(), $this->franjas(600, 600));

        $this->assertNotSame($primera, $segunda);
        Storage::disk('public')->assertMissing($primera);
        Storage::disk('public')->assertExists($segunda);
    }

    public function test_al_quitarla_desaparecen_el_archivo_y_la_columna(): void
    {
        Storage::fake('public');

        $persona = $this->persona();
        $ruta = app(AvatarStorage::class)->guardar($persona, $this->franjas(600, 600));

        app(AvatarStorage::class)->borrar($persona->fresh());

        Storage::disk('public')->assertMissing($ruta);
        $this->assertNull($persona->fresh()->avatar_path);
    }

    public function test_un_archivo_que_no_es_imagen_no_pasa(): void
    {
        Storage::fake('public');

        $ruta = tempnam(sys_get_temp_dir(), 'falsa').'.jpg';
        file_put_contents($ruta, 'esto no es una imagen');

        $this->expectException(\RuntimeException::class);

        app(AvatarStorage::class)->guardar(
            $this->persona(),
            new UploadedFile($ruta, 'falsa.jpg', 'image/jpeg', null, true),
        );
    }

    /**
     * Escribe una cabecera EXIF mínima con la orientación pedida.
     *
     * Se arma a mano porque GD no sabe escribir EXIF y traer una biblioteca
     * entera para una prueba sería desproporcionado.
     */
    private function marcarOrientacion(string $ruta, int $orientacion): void
    {
        $jpeg = file_get_contents($ruta);

        $tiff = "MM\x00\x2a\x00\x00\x00\x08"          // cabecera TIFF, big endian
            ."\x00\x01"                                // una sola entrada
            ."\x01\x12\x00\x03\x00\x00\x00\x01"        // etiqueta 0x0112 (Orientation), tipo short
            .pack('n', $orientacion)."\x00\x00"        // el valor
            ."\x00\x00\x00\x00";                       // fin del directorio

        $exif = "Exif\x00\x00".$tiff;
        $app1 = "\xFF\xE1".pack('n', strlen($exif) + 2).$exif;

        // Va justo después del marcador de inicio de imagen (SOI, 2 bytes).
        file_put_contents($ruta, substr($jpeg, 0, 2).$app1.substr($jpeg, 2));
    }
}
