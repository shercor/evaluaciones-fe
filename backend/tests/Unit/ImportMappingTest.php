<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\BranchOffice;
use App\Models\User;
use App\Services\ImportMapping;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * Homologar una planilla ajena con las columnas del sistema.
 *
 * Acá se prueba lo que decide sin que nadie mire: qué columna se sugiere para
 * cuál, qué homologaciones se rechazan, y qué dice el resumen antes de tocar
 * el directorio. Ese resumen es el único punto donde alguien puede darse
 * cuenta de que conectó mal una columna, así que si miente, miente en el peor
 * momento posible.
 */
class ImportMappingTest extends TestCase
{
    use RefreshDatabase;

    private ImportMapping $mapper;

    protected function setUp(): void
    {
        parent::setUp();
        $this->mapper = app(ImportMapping::class);
    }

    /** @param  array<int, string>  $claves */
    private function encabezados(array $claves): array
    {
        return array_map(fn (string $c) => ['clave' => $c, 'etiqueta' => $c], $claves);
    }

    public function test_reconoce_los_nombres_que_usa_una_planilla_de_verdad(): void
    {
        $sugerencia = $this->mapper->sugerir($this->encabezados([
            'n_ficha', 'nombre_del_trabajador', 'apellidos', 'mail_corporativo',
            'local_asignado', 'cargo_desempenado', 'jefe_directo_ficha', 'observaciones',
        ]));

        $this->assertSame('n_ficha', $sugerencia['codigo']);
        $this->assertSame('nombre_del_trabajador', $sugerencia['nombre']);
        $this->assertSame('apellidos', $sugerencia['apellido']);
        $this->assertSame('mail_corporativo', $sugerencia['correo']);
        $this->assertSame('local_asignado', $sugerencia['sucursal']);
        $this->assertSame('cargo_desempenado', $sugerencia['cargo']);
        $this->assertSame('jefe_directo_ficha', $sugerencia['codigo_supervisor']);
    }

    public function test_la_coincidencia_exacta_le_gana_a_la_parcial(): void
    {
        // «supervisor» contiene el nombre de la columna del sistema, pero
        // «codigo_supervisor» es exactamente ella: si ganara la parcial, el
        // sistema homologaría el nombre del jefe donde va su código y la
        // jerarquía entera quedaría sin armar.
        $sugerencia = $this->mapper->sugerir($this->encabezados([
            'codigo', 'nombre', 'supervisor', 'codigo_supervisor',
        ]));

        $this->assertSame('codigo_supervisor', $sugerencia['codigo_supervisor']);
    }

    public function test_no_le_roba_al_supervisor_su_columna_por_parecido(): void
    {
        // La planilla trae el código del jefe pero no el de la persona. Como
        // «codigo_supervisor» contiene «codigo», el campo «código interno» —que
        // se resuelve antes— podría llevárselo y dejar los dos mal.
        $sugerencia = $this->mapper->sugerir($this->encabezados([
            'nombre', 'codigo_supervisor',
        ]));

        $this->assertNull($sugerencia['codigo']);
        $this->assertSame('codigo_supervisor', $sugerencia['codigo_supervisor']);
    }

    public function test_no_ofrece_dos_veces_la_misma_columna(): void
    {
        $sugerencia = $this->mapper->sugerir($this->encabezados(['nombre', 'codigo']));

        $usadas = array_filter(array_values($sugerencia));

        $this->assertSame($usadas, array_unique($usadas));
    }

    public function test_no_deja_pasar_una_homologacion_sin_las_obligatorias(): void
    {
        $this->expectException(ValidationException::class);

        $this->mapper->validar(
            ['correo' => 'mail'],
            $this->encabezados(['mail']),
        );
    }

    public function test_no_deja_pasar_la_misma_columna_en_dos_campos(): void
    {
        try {
            $this->mapper->validar(
                ['codigo' => 'ficha', 'nombre' => 'quien', 'apellido' => 'quien'],
                $this->encabezados(['ficha', 'quien']),
            );

            $this->fail('Tendría que haber rechazado la columna repetida.');
        } catch (ValidationException $e) {
            $this->assertStringContainsString(
                'solo un campo',
                implode(' ', $e->errors()['mapping']),
            );
        }
    }

    public function test_no_deja_pasar_una_columna_que_el_archivo_no_tiene(): void
    {
        $this->expectException(ValidationException::class);

        $this->mapper->validar(
            ['codigo' => 'ficha', 'nombre' => 'columna_fantasma'],
            $this->encabezados(['ficha', 'quien']),
        );
    }

    public function test_traduce_las_filas_y_deja_vacio_lo_que_la_planilla_no_trae(): void
    {
        $filas = $this->mapper->aplicar(
            [['ficha' => '77001', 'quien' => 'Ana', 'sobra' => 'ignorar esto']],
            ['codigo' => 'ficha', 'nombre' => 'quien'],
        );

        // Todas las columnas del sistema tienen que estar, incluso las que la
        // planilla no trae: el importador las espera, y una clave ausente da
        // un aviso distinto del que corresponde.
        $this->assertSame(
            ['codigo', 'nombre', 'apellido', 'correo', 'cargo', 'cargo_codigo',
                'sucursal', 'sucursal_codigo', 'codigo_supervisor'],
            array_keys($filas[0]),
        );
        $this->assertSame('77001', $filas[0]['codigo']);
        $this->assertSame('Ana', $filas[0]['nombre']);
        $this->assertSame('', $filas[0]['correo']);
        $this->assertArrayNotHasKey('sobra', $filas[0]);
    }

    public function test_el_resumen_cuenta_lo_que_va_a_pasar_sin_tocar_nada(): void
    {
        User::factory()->create(['external_code' => '77001']);

        $filas = [
            $this->fila('77001', ['nombre' => 'Ana', 'correo' => 'ana@x.cl']),
            $this->fila('77002', ['nombre' => 'Beto']),
            $this->fila('77003', ['nombre' => '', 'apellido' => 'Sin nombre']),
        ];

        $resumen = $this->mapper->ensayar($filas);

        $this->assertSame(3, $resumen['filas_totales']);
        $this->assertSame(2, $resumen['filas_validas']);
        $this->assertSame(1, $resumen['filas_con_problemas']);
        $this->assertSame(1, $resumen['se_actualizaran'], 'La 77001 ya existe.');
        $this->assertSame(1, $resumen['se_crearan']);
        $this->assertSame(1, $resumen['sin_correo']);
        $this->assertSame(4, $resumen['problemas'][0]['linea'], 'La tercera fila es la línea 4 de la planilla.');

        // Y nada de esto tocó la base: sigue habiendo una sola persona.
        $this->assertSame(1, User::count());
    }

    public function test_avisa_de_los_codigos_repetidos_dentro_del_archivo(): void
    {
        $resumen = $this->mapper->ensayar([
            $this->fila('77001', ['nombre' => 'Ana']),
            $this->fila('77001', ['nombre' => 'Otra Ana']),
            $this->fila('77002', ['nombre' => 'Beto']),
        ]);

        $this->assertSame(['77001' => 2], $resumen['codigos_repetidos']);
        // Dos filas, un solo código: se crea una persona, no dos.
        $this->assertSame(2, $resumen['se_crearan']);
    }

    public function test_avisa_que_sucursales_y_cargos_van_a_nacer_de_la_planilla(): void
    {
        BranchOffice::create(['name' => 'Sucursal Norte', 'active' => true]);

        $resumen = $this->mapper->ensayar([
            $this->fila('77001', ['sucursal' => 'Sucursal Norte', 'cargo' => 'Vendedor']),
            $this->fila('77002', ['sucursal' => 'Sucursal Sur', 'cargo' => 'Vendedor']),
            // El mismo nombre escrito distinto no se cuenta dos veces.
            $this->fila('77003', ['sucursal' => 'sucursal sur', 'cargo' => 'Cajero']),
        ]);

        $this->assertSame(['Sucursal Sur'], $resumen['sucursales_nuevas']);
        $this->assertSame(['Cajero', 'Vendedor'], $resumen['cargos_nuevos']);
    }

    public function test_un_codigo_de_sucursal_que_no_esta_cargado_rechaza_la_fila(): void
    {
        // El caso que motivó todo esto: la planilla trae el id de la sucursal
        // y no su nombre. Antes se creaba una sucursal **llamada** «S-14»; ni
        // fallaba ni avisaba.
        $resumen = $this->mapper->ensayar([
            $this->fila('77001', ['sucursal_codigo' => 'S-14']),
        ]);

        $this->assertSame(1, $resumen['filas_con_problemas']);
        $this->assertSame(['S-14'], $resumen['sucursales_faltantes']);
        $this->assertStringContainsString(
            'no está en el sistema',
            implode(' ', $resumen['problemas'][0]['motivos']),
        );
        $this->assertSame([], $resumen['sucursales_nuevas'], 'No se inventa una sucursal con el código de nombre.');
    }

    public function test_un_codigo_que_si_esta_cargado_resuelve_sin_crear_nada(): void
    {
        BranchOffice::create(['external_code' => 'S-14', 'name' => 'Sucursal Ñuñoa', 'active' => true]);

        $resumen = $this->mapper->ensayar([
            $this->fila('77001', ['sucursal_codigo' => 'S-14']),
        ]);

        $this->assertSame(0, $resumen['filas_con_problemas']);
        $this->assertSame([], $resumen['sucursales_faltantes']);
        $this->assertSame([], $resumen['sucursales_nuevas']);
    }

    public function test_con_codigo_y_nombre_la_sucursal_nace_con_los_dos(): void
    {
        $resumen = $this->mapper->ensayar([
            $this->fila('77001', ['sucursal_codigo' => 'S-14', 'sucursal' => 'Sucursal Ñuñoa']),
            // La segunda persona de la misma sucursal no la duplica.
            $this->fila('77002', ['sucursal_codigo' => 'S-14', 'sucursal' => 'Sucursal Ñuñoa']),
        ]);

        $this->assertSame(0, $resumen['filas_con_problemas']);
        $this->assertSame(['Sucursal Ñuñoa (S-14)'], $resumen['sucursales_nuevas']);
    }

    public function test_el_codigo_tambien_sirve_para_encontrar_una_sucursal_puesta_en_la_columna_del_nombre(): void
    {
        BranchOffice::create(['external_code' => 'S-14', 'name' => 'Sucursal Ñuñoa', 'active' => true]);

        // La planilla puso el código donde va el nombre. Buscar es permisivo:
        // encuentra la fila en vez de abrir una sucursal llamada «S-14».
        $resumen = $this->mapper->ensayar([
            $this->fila('77001', ['sucursal' => 'S-14']),
        ]);

        $this->assertSame([], $resumen['sucursales_nuevas']);
        $this->assertSame(0, $resumen['filas_con_problemas']);
    }

    /**
     * Una fila con lo mínimo, para no repetir ocho claves en cada prueba.
     *
     * @param  array<string, string>  $campos
     * @return array<string, string>
     */
    private function fila(string $codigo, array $campos = []): array
    {
        return array_merge([
            'codigo' => $codigo,
            'nombre' => 'Ana',
            'apellido' => '',
            'correo' => '',
            'cargo' => '',
            'cargo_codigo' => '',
            'sucursal' => '',
            'sucursal_codigo' => '',
            'codigo_supervisor' => '',
        ], $campos);
    }
}
