<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\BranchOffice;
use App\Models\ImportDraft;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * El camino entero: subir la planilla del catálogo y después la nómina.
 *
 * Prueba la historia que motivó todo esto, y en el orden en que pasa: una
 * nómina que trae el **código** de la sucursal y no su nombre no se puede
 * importar sola —no hay con qué crear la sucursal—, pero sí después de cargar
 * el catálogo. Las piezas están probadas por separado; esto comprueba que
 * encajan, incluida la parte que las une: que el destino elegido al subir el
 * archivo siga vigente tres peticiones después.
 */
class CatalogImportFlowTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');

        // `active` explícito: la fábrica no lo pone y el middleware de rol
        // trata a quien no está activo como no autenticado.
        $this->admin = User::factory()->create(['role' => Role::ADMIN, 'active' => true]);

        // Por `Sanctum::actingAs` y no por `actingAs`: las rutas van con el
        // guard `sanctum`, que resuelve mirando la petición y no la sesión.
        Sanctum::actingAs($this->admin);
    }

    private function subir(string $contenido, string $destino): array
    {
        $respuesta = $this->post('/api/admin/importaciones/homologacion', [
            'file' => UploadedFile::fake()->createWithContent('planilla.csv', $contenido),
            'destino' => $destino,
        ]);

        $respuesta->assertCreated();

        return $respuesta->json();
    }

    public function test_carga_las_sucursales_desde_su_planilla_y_despues_la_nomina_por_codigo(): void
    {
        // -- La planilla del catálogo, con los encabezados de quien la armó --
        $subida = $this->subir(
            "COD_LOCAL;DESCRIPCION;REGION\nS-14;Sucursal Ñuñoa;RM\nS-20;Sucursal Maipú;RM\n",
            'sucursales',
        );

        $this->assertSame('sucursales', $subida['data']['destino']);
        // La sugerencia reconoce los nombres de una planilla de sucursales, que
        // no son los de una de personas.
        $this->assertSame('cod_local', $subida['sugerencia']['codigo']);
        $this->assertSame('descripcion', $subida['sugerencia']['nombre']);

        $borrador = $subida['data']['id'];
        $mapa = ['codigo' => 'cod_local', 'nombre' => 'descripcion'];

        $resumen = $this->postJson("/api/admin/importaciones/homologacion/{$borrador}/resumen", ['mapping' => $mapa])
            ->assertOk()
            ->json('resumen');

        $this->assertSame(2, $resumen['se_crearan']);
        $this->assertSame(0, $resumen['filas_con_problemas']);
        $this->assertSame(['REGION'], $this->postJson("/api/admin/importaciones/homologacion/{$borrador}/resumen", ['mapping' => $mapa])
            ->json('sin_usar'));
        // Mirar el resumen no escribe nada.
        $this->assertSame(0, BranchOffice::count());

        $this->postJson("/api/admin/importaciones/homologacion/{$borrador}/importar", ['mapping' => $mapa])
            ->assertOk()
            ->assertJsonPath('data.destino', 'sucursales');

        $this->assertSame(2, BranchOffice::count());
        $this->assertSame('Sucursal Ñuñoa', BranchOffice::where('external_code', 'S-14')->value('name'));
        // El borrador se tira al confirmar: la planilla no queda en el disco.
        $this->assertSame(0, ImportDraft::count());

        // -- Y ahora la nómina, que solo trae el código de la sucursal --
        $nomina = $this->subir("RUT;NOMBRE;COD_LOCAL\n11111111-1;Ana;S-14\n22222222-2;Bruno;S-20\n", 'nomina');
        $borradorNomina = $nomina['data']['id'];
        $mapaNomina = ['codigo' => 'rut', 'nombre' => 'nombre', 'sucursal_codigo' => 'cod_local'];

        $resumenNomina = $this->postJson("/api/admin/importaciones/homologacion/{$borradorNomina}/resumen", ['mapping' => $mapaNomina])
            ->assertOk()
            ->json('resumen');

        $this->assertSame(2, $resumenNomina['se_crearan']);
        $this->assertSame(0, $resumenNomina['filas_con_problemas'], 'Los códigos ya están cargados.');
        $this->assertSame([], $resumenNomina['sucursales_faltantes']);
        // Sin cargo entran las dos, y el resumen lo dice antes de importar.
        $this->assertSame(2, $resumenNomina['sin_cargo']);
        $this->assertSame(0, $resumenNomina['sin_sucursal']);

        $this->postJson("/api/admin/importaciones/homologacion/{$borradorNomina}/importar", ['mapping' => $mapaNomina])
            ->assertOk();

        $ana = User::where('external_code', '11111111-1')->first();
        $this->assertSame('Sucursal Ñuñoa', $ana->branchOffice->name);
        $this->assertNull($ana->job_position_id);
    }

    public function test_sin_el_catalogo_cargado_esa_misma_nomina_se_rechaza_entera(): void
    {
        $nomina = $this->subir("RUT;NOMBRE;COD_LOCAL\n11111111-1;Ana;S-14\n", 'nomina');
        $mapa = ['codigo' => 'rut', 'nombre' => 'nombre', 'sucursal_codigo' => 'cod_local'];

        $resumen = $this->postJson("/api/admin/importaciones/homologacion/{$nomina['data']['id']}/resumen", ['mapping' => $mapa])
            ->assertOk()
            ->json('resumen');

        $this->assertSame(1, $resumen['filas_con_problemas']);
        $this->assertSame(['S-14'], $resumen['sucursales_faltantes']);
    }

    public function test_una_planilla_de_catalogo_sin_la_columna_del_nombre_no_pasa(): void
    {
        $subida = $this->subir("COD_LOCAL;REGION\nS-14;RM\n", 'sucursales');

        $this->postJson("/api/admin/importaciones/homologacion/{$subida['data']['id']}/resumen", [
            'mapping' => ['codigo' => 'cod_local'],
        ])
            ->assertStatus(422)
            ->assertJsonFragment(['mapping' => ['Falta conectar «Nombre», que es obligatoria.']]);
    }

    public function test_el_historial_de_nomina_no_muestra_las_cargas_de_catalogo(): void
    {
        $subida = $this->subir("codigo;nombre\nS-14;Sucursal Ñuñoa\n", 'sucursales');

        $this->postJson("/api/admin/importaciones/homologacion/{$subida['data']['id']}/importar", [
            'mapping' => ['codigo' => 'codigo', 'nombre' => 'nombre'],
        ])
            ->assertOk();

        // Comparten tabla, pero cada carga se ve donde tiene sentido: esta, en
        // la pantalla de Sucursales.
        $this->getJson('/api/admin/importaciones')
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    public function test_no_se_puede_inventar_un_destino(): void
    {
        $this->post('/api/admin/importaciones/homologacion', [
            'file' => UploadedFile::fake()->createWithContent('planilla.csv', "codigo;nombre\n1;Ana\n"),
            'destino' => 'lo_que_sea',
        ])
            ->assertStatus(422);
    }
}
