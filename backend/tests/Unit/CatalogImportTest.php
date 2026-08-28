<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\BranchOffice;
use App\Models\Import;
use App\Models\ImportRow;
use App\Models\JobPosition;
use App\Models\User;
use App\Services\CatalogImportSchema;
use App\Services\CatalogImportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Cargar las sucursales y los cargos desde una planilla.
 *
 * Lo que se prueba acá es lo que decide sin que nadie mire: con qué fila del
 * catálogo se corresponde cada línea del archivo. Equivocarse en eso no falla
 * —crea una sucursal de más, o le cambia el código a la que no era— y se
 * descubre semanas después, cuando la nómina empieza a repartir gente entre
 * dos «Sucursal Norte».
 *
 * El resumen previo sale del mismo plan que ejecuta la importación, así que
 * hay una prueba dedicada a que los dos digan lo mismo: si se separan, la
 * pantalla que existe para avisar antes de tocar nada empieza a mentir.
 */
class CatalogImportTest extends TestCase
{
    use RefreshDatabase;

    private CatalogImportService $servicio;

    protected function setUp(): void
    {
        parent::setUp();
        $this->servicio = app(CatalogImportService::class);
    }

    /** @param  array<int, array{0: string, 1: string}>  $filas  código y nombre */
    private function importar(string $tipo, array $filas): Import
    {
        $import = Import::create([
            'user_id' => User::factory()->create()->id,
            'filename' => 'catalogo.csv',
            'destino' => $tipo,
            'status' => Import::PENDING,
        ]);

        return $this->servicio->import($tipo, $this->planilla($filas), $import);
    }

    /** @param  array<int, array{0: string, 1: string}>  $filas */
    private function planilla(array $filas): array
    {
        return array_map(fn (array $f) => ['codigo' => $f[0], 'nombre' => $f[1]], $filas);
    }

    public function test_crea_las_que_no_estan(): void
    {
        $import = $this->importar('sucursales', [
            ['S-01', 'Sucursal Norte'],
            ['S-02', 'Sucursal Sur'],
        ]);

        $this->assertSame(2, $import->rows_created);
        $this->assertSame(0, $import->rows_failed);
        $this->assertSame('S-01', BranchOffice::where('name', 'Sucursal Norte')->value('external_code'));
        $this->assertTrue(BranchOffice::where('name', 'Sucursal Sur')->value('active'));
    }

    public function test_reimportar_la_misma_planilla_no_duplica_nada(): void
    {
        $filas = [['S-01', 'Sucursal Norte'], ['S-02', 'Sucursal Sur']];

        $this->importar('sucursales', $filas);
        $segunda = $this->importar('sucursales', $filas);

        $this->assertSame(0, $segunda->rows_created);
        $this->assertSame(2, $segunda->rows_updated);
        $this->assertSame(2, BranchOffice::count());
    }

    public function test_el_codigo_manda_y_le_corrige_el_nombre(): void
    {
        BranchOffice::create(['external_code' => 'S-01', 'name' => 'Suc. Norte', 'active' => true]);

        $import = $this->importar('sucursales', [['S-01', 'Sucursal Norte']]);

        $this->assertSame(1, $import->rows_updated);
        $this->assertSame(1, BranchOffice::count());
        $this->assertSame('Sucursal Norte', BranchOffice::where('external_code', 'S-01')->value('name'));
    }

    public function test_le_pone_el_codigo_a_la_que_habia_creado_la_nomina(): void
    {
        // El caso que justifica todo esto al revés: la nómina ya creó la
        // sucursal por su nombre, sin código, y esta planilla viene a darle el
        // que usa la planilla de personas.
        BranchOffice::create(['external_code' => null, 'name' => 'Sucursal Ñuñoa', 'active' => true]);

        $import = $this->importar('sucursales', [['S-14', 'sucursal ñuñoa']]);

        $this->assertSame(1, $import->rows_updated);
        $this->assertSame(0, $import->rows_created);
        $this->assertSame(1, BranchOffice::count());
        $this->assertSame('S-14', BranchOffice::first()->external_code);
        // El nombre no se toca: la planilla lo escribió en minúsculas y el
        // cotejo las trata como la misma, así que no hay nada que corregir.
        $this->assertSame('Sucursal Ñuñoa', BranchOffice::first()->name);
    }

    public function test_no_le_cambia_el_codigo_a_una_que_ya_tiene_otro(): void
    {
        BranchOffice::create(['external_code' => 'S-01', 'name' => 'Sucursal Norte', 'active' => true]);

        $import = $this->importar('sucursales', [['S-99', 'Sucursal Norte']]);

        $this->assertSame(1, $import->rows_failed);
        $this->assertSame('S-01', BranchOffice::first()->external_code);
        $this->assertSame(1, BranchOffice::count());
        $this->assertStringContainsString(
            'ya está cargada con el código «S-01»',
            (string) ImportRow::where('import_id', $import->id)->value('error'),
        );
    }

    public function test_no_deja_que_dos_filas_se_llamen_igual(): void
    {
        BranchOffice::create(['external_code' => 'S-01', 'name' => 'Sucursal Norte', 'active' => true]);
        BranchOffice::create(['external_code' => 'S-02', 'name' => 'Sucursal Sur', 'active' => true]);

        // Renombrar la S-02 a «Sucursal Norte» dejaría dos con el mismo nombre.
        $import = $this->importar('sucursales', [['S-02', 'Sucursal Norte']]);

        $this->assertSame(1, $import->rows_failed);
        $this->assertSame('Sucursal Sur', BranchOffice::where('external_code', 'S-02')->value('name'));
    }

    public function test_una_fila_sin_nombre_se_rechaza_y_el_resto_entra(): void
    {
        $import = $this->importar('sucursales', [
            ['S-01', 'Sucursal Norte'],
            ['S-02', '   '],
            ['S-03', 'Sucursal Sur'],
        ]);

        $this->assertSame(2, $import->rows_created);
        $this->assertSame(1, $import->rows_failed);
        $this->assertSame(3, $import->rows_total);
        $this->assertStringContainsString(
            'Falta el nombre',
            (string) ImportRow::where('import_id', $import->id)->where('outcome', ImportRow::FAILED)->value('error'),
        );
    }

    public function test_el_mismo_nombre_dos_veces_en_el_archivo_es_una_sola_fila(): void
    {
        $import = $this->importar('sucursales', [
            ['', 'Sucursal Norte'],
            ['', 'SUCURSAL NORTE'],
        ]);

        $this->assertSame(1, $import->rows_created);
        $this->assertSame(1, $import->rows_updated);
        $this->assertSame(1, BranchOffice::count());
    }

    public function test_sirve_igual_para_los_cargos(): void
    {
        $import = $this->importar('cargos', [['C-1', 'Vendedor'], ['C-2', 'Cajero']]);

        $this->assertSame(2, $import->rows_created);
        $this->assertSame(2, JobPosition::count());
        $this->assertSame(0, BranchOffice::count());
    }

    public function test_el_resumen_previo_no_toca_nada_y_dice_lo_mismo_que_la_importacion(): void
    {
        BranchOffice::create(['external_code' => 'S-01', 'name' => 'Suc. Norte', 'active' => true]);
        BranchOffice::create(['external_code' => 'S-99', 'name' => 'Sucursal Vieja', 'active' => true]);

        $planilla = $this->planilla([
            ['S-01', 'Sucursal Norte'],   // actualiza el nombre
            ['S-02', 'Sucursal Sur'],     // nace
            ['S-03', 'Sucursal Vieja'],   // choca: ese nombre ya tiene otro código
            ['S-04', ''],                 // sin nombre
        ]);

        $esquema = new CatalogImportSchema($this->servicio, 'sucursales');
        $resumen = $esquema->ensayar($planilla);

        $this->assertSame(4, $resumen['filas_totales']);
        $this->assertSame(1, $resumen['se_crearan']);
        $this->assertSame(1, $resumen['se_actualizaran']);
        $this->assertSame(2, $resumen['filas_con_problemas']);
        // Nada cambió por haber mirado.
        $this->assertSame(2, BranchOffice::count());
        $this->assertSame('Suc. Norte', BranchOffice::where('external_code', 'S-01')->value('name'));

        $import = Import::create([
            'user_id' => User::factory()->create()->id,
            'filename' => 'catalogo.csv',
            'destino' => 'sucursales',
            'status' => Import::PENDING,
        ]);

        $hecho = $this->servicio->import('sucursales', $planilla, $import);

        $this->assertSame($resumen['se_crearan'], $hecho->rows_created);
        $this->assertSame($resumen['se_actualizaran'], $hecho->rows_updated);
        $this->assertSame($resumen['filas_con_problemas'], $hecho->rows_failed);
    }

    public function test_el_resumen_avisa_de_los_nombres_repetidos_en_el_archivo(): void
    {
        $esquema = new CatalogImportSchema($this->servicio, 'sucursales');

        $resumen = $esquema->ensayar($this->planilla([
            ['', 'Sucursal Norte'],
            ['', 'sucursal norte'],
            ['', 'Sucursal Sur'],
        ]));

        $this->assertSame(['Sucursal Norte' => 2], $resumen['nombres_repetidos']);
        $this->assertSame(2, $resumen['se_crearan']);
    }
}
