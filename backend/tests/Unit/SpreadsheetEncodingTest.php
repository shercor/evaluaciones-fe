<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\SpreadsheetReader;
use Tests\TestCase;

/**
 * Que los acentos lleguen enteros.
 *
 * Es la clase de avería que no falla: entra, se guarda y se descubre meses
 * después mirando el directorio, con «Sánchez» escrito «SÃ¡nchez» en 53 filas
 * y sin nadie que sepa en qué momento pasó.
 *
 * Las dos que se dan de verdad vienen de Excel. Guardar un CSV en español lo
 * escribe en Windows-1252 y no en UTF-8; y abrir en Excel un CSV que **ya**
 * era UTF-8 lo hace interpretar cada byte como un carácter, así que al
 * guardarlo escribe el doble de bytes de los que había.
 */
class SpreadsheetEncodingTest extends TestCase
{
    private SpreadsheetReader $lector;

    protected function setUp(): void
    {
        parent::setUp();
        $this->lector = app(SpreadsheetReader::class);
    }

    /** @return array<int, array<string, string>> */
    private function leer(string $contenido): array
    {
        $ruta = tempnam(sys_get_temp_dir(), 'planilla').'.csv';
        file_put_contents($ruta, $contenido);

        try {
            return $this->lector->readRaw($ruta, 'csv')['rows'];
        } finally {
            @unlink($ruta);
        }
    }

    /** @return array<int, array{clave: string, etiqueta: string}> */
    private function encabezadosDe(string $contenido): array
    {
        $ruta = tempnam(sys_get_temp_dir(), 'planilla').'.csv';
        file_put_contents($ruta, $contenido);

        try {
            return $this->lector->readRaw($ruta, 'csv')['headers'];
        } finally {
            @unlink($ruta);
        }
    }

    public function test_un_csv_en_utf8_pasa_intacto(): void
    {
        $filas = $this->leer("codigo,apellido\n1,Sánchez\n2,Díaz\n");

        $this->assertSame('Sánchez', $filas[0]['apellido']);
        $this->assertSame('Díaz', $filas[1]['apellido']);
    }

    public function test_un_csv_guardado_por_excel_en_windows_1252_se_convierte(): void
    {
        // Como lo escribe Excel en español: la «á» es un solo byte, `E1`.
        $contenido = mb_convert_encoding("codigo,apellido\n1,Sánchez\n", 'Windows-1252', 'UTF-8');

        $this->assertFalse(mb_check_encoding($contenido, 'UTF-8'), 'La prueba tiene que partir de bytes que no son UTF-8.');

        $filas = $this->leer($contenido);

        $this->assertSame('Sánchez', $filas[0]['apellido']);
    }

    public function test_un_utf8_codificado_dos_veces_se_deshace(): void
    {
        // El caso real: cada byte de la «á» se volvió un carácter.
        $roto = "codigo,apellido\n1,SÃ¡nchez\n2,MaltÃ©s\n3,VÃ¡squez\n";

        $this->assertTrue(mb_check_encoding($roto, 'UTF-8'), 'La avería es que el texto roto es UTF-8 válido: por eso nada se queja.');

        $filas = $this->leer($roto);

        $this->assertSame('Sánchez', $filas[0]['apellido']);
        $this->assertSame('Maltés', $filas[1]['apellido']);
        $this->assertSame('Vásquez', $filas[2]['apellido']);
    }

    public function test_los_encabezados_tambien_llegan_enteros(): void
    {
        // La avería se produce igual que en la vida real —tomando el UTF-8 y
        // volviéndolo a codificar— en vez de escribirla a mano: «Área» doble
        // no es «Ãrea», es «Ã» más un carácter de control, y escribirlo a ojo
        // sale mal.
        $roto = mb_convert_encoding("codigo,Dirección,Área\n1,Calle,Ventas\n", 'UTF-8', 'Windows-1252');

        $encabezados = $this->encabezadosDe($roto);

        $this->assertSame('Dirección', $encabezados[1]['etiqueta']);
        $this->assertSame('direccion', $encabezados[1]['clave']);
        $this->assertSame('Área', $encabezados[2]['etiqueta']);
        $this->assertSame('area', $encabezados[2]['clave']);
    }

    public function test_no_toca_un_texto_que_de_verdad_lleva_esas_letras(): void
    {
        // «Ã» sola, sin un carácter alto detrás: no es la huella de la avería
        // y deshacerla arruinaría un apellido legítimo.
        $filas = $this->leer("codigo,apellido\n1,Ã\n2,Vietnã\n3,Ângela\n");

        $this->assertSame('Ã', $filas[0]['apellido']);
        $this->assertSame('Vietnã', $filas[1]['apellido']);
        $this->assertSame('Ângela', $filas[2]['apellido']);
    }

    public function test_los_simbolos_que_excel_dobla_tambien_vuelven(): void
    {
        $filas = $this->leer("codigo,cargo\n1,JefeÂ° de Turno\n2,NiÃ±era\n");

        $this->assertSame('Jefe° de Turno', $filas[0]['cargo']);
        $this->assertSame('Niñera', $filas[1]['cargo']);
    }
}
