<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Enums\Role;
use App\Models\Import;
use App\Models\ImportRow;
use App\Models\User;
use App\Services\DirectoryImportSchema;
use App\Services\DirectoryImportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * El directorio como espejo de la nómina.
 *
 * Una importación de nómina no es una carga: es una **sincronización**. Quien
 * viene se crea o se actualiza, quien viene marcado de baja se desactiva, y
 * —si se pide— quien no viene también. Eso último es lo que puede hacer daño,
 * así que la mitad de estas pruebas son sobre a quién **no** hay que tocar.
 *
 * Nada de esto borra filas. La baja es `active = false` con su motivo y su
 * fecha, porque una persona borrada se lleva por delante su historial de
 * evaluaciones y la jefatura de quienes le reportan, y porque la mitad de las
 * veces vuelve en la nómina del mes siguiente.
 */
class DirectorySyncTest extends TestCase
{
    use RefreshDatabase;

    private DirectoryImportService $importador;

    private User $ejecutor;

    protected function setUp(): void
    {
        parent::setUp();

        $this->importador = app(DirectoryImportService::class);

        // Quien importa es siempre un administrador: es el único rol que llega
        // a esta pantalla, y es la cuenta que ninguna baja puede tocar.
        $this->ejecutor = User::factory()->create([
            'external_code' => 'ADM-001',
            'role' => Role::ADMIN,
            'active' => true,
        ]);
    }

    // -----------------------------------------------------------------

    /**
     * @param  array<int, array<string, string>>  $filas
     * @param  array<string, mixed>  $opciones
     */
    private function importar(array $filas, array $opciones = []): Import
    {
        $import = Import::create([
            'user_id' => $this->ejecutor->id,
            'filename' => 'nomina.csv',
            'destino' => 'nomina',
            'status' => Import::PENDING,
        ]);

        return $this->importador->import(
            array_map($this->completar(...), $filas),
            $import,
            $opciones + ['enviar_invitaciones' => false, 'ejecutor_id' => $this->ejecutor->id],
        );
    }

    /** Una fila con todas las claves que el importador espera. */
    private function completar(array $fila): array
    {
        return array_merge(array_fill_keys(DirectoryImportService::COLUMNS, ''), $fila);
    }

    /** @param  array<int, array<string, string>>  $filas */
    private function ensayar(array $filas, array $opciones = []): array
    {
        return app(DirectoryImportSchema::class)->ensayar(
            array_map($this->completar(...), $filas),
            $opciones + ['ejecutor_id' => $this->ejecutor->id],
        );
    }

    private function persona(string $codigo, array $atributos = []): User
    {
        return User::factory()->create($atributos + [
            'external_code' => $codigo,
            'role' => Role::COLLABORATOR,
            'active' => true,
        ]);
    }

    // -- Repetidos ----------------------------------------------------

    public function test_un_codigo_repetido_en_el_archivo_no_crea_dos_personas(): void
    {
        $import = $this->importar([
            ['codigo' => 'P-1', 'nombre' => 'Ana', 'apellido' => 'Pérez'],
            ['codigo' => 'P-1', 'nombre' => 'Ana', 'apellido' => 'Pérez Soto'],
        ]);

        $this->assertSame(1, User::where('external_code', 'P-1')->count());

        // La segunda no es un rechazo: es una actualización de la primera.
        $this->assertSame(1, $import->rows_created);
        $this->assertSame(1, $import->rows_updated);
        $this->assertSame(0, $import->rows_failed);

        // Y gana la última, que es la regla que el resumen anuncia.
        $this->assertSame('Pérez Soto', User::where('external_code', 'P-1')->value('lastname'));
    }

    public function test_reimportar_la_misma_planilla_no_duplica_a_nadie(): void
    {
        $filas = [
            ['codigo' => 'P-1', 'nombre' => 'Ana'],
            ['codigo' => 'P-2', 'nombre' => 'Luis'],
        ];

        $primera = $this->importar($filas);
        $segunda = $this->importar($filas);

        $this->assertSame(2, $primera->rows_created);
        $this->assertSame(0, $segunda->rows_created);
        $this->assertSame(2, $segunda->rows_updated);
        $this->assertSame(2, User::whereIn('external_code', ['P-1', 'P-2'])->count());
    }

    public function test_actualizar_no_le_quita_el_rol_ni_la_contrasena_a_quien_ya_estaba(): void
    {
        $existente = $this->persona('P-1', [
            'role' => Role::ADMIN,
            'name' => 'Ana',
        ]);
        $clave = $existente->password;

        $this->importar([['codigo' => 'P-1', 'nombre' => 'Ana María']]);

        $existente->refresh();

        $this->assertSame('Ana María', $existente->name);
        $this->assertSame(Role::ADMIN, $existente->role);
        $this->assertSame($clave, $existente->password);
    }

    public function test_el_resumen_avisa_de_los_codigos_repetidos_antes_de_importar(): void
    {
        $resumen = $this->ensayar([
            ['codigo' => 'P-1', 'nombre' => 'Ana'],
            ['codigo' => 'P-1', 'nombre' => 'Ana'],
            ['codigo' => 'P-2', 'nombre' => 'Luis'],
        ]);

        $this->assertSame(['P-1' => 2], $resumen['codigos_repetidos']);
        // Dos filas repetidas son **una** persona a crear, no dos.
        $this->assertSame(2, $resumen['se_crearan']);
    }

    public function test_un_correo_que_ya_es_de_otra_persona_rechaza_la_fila_con_un_motivo_legible(): void
    {
        // Misma casilla, otro código: no es la misma persona repetida, son dos
        // personas y una de las dos está mal cargada.
        $this->persona('P-1', ['email' => 'ana@empresa.cl', 'name' => 'Ana', 'lastname' => 'Pérez']);

        $import = $this->importar([
            ['codigo' => 'P-2', 'nombre' => 'Otra', 'correo' => 'ana@empresa.cl'],
        ]);

        $this->assertSame(1, $import->rows_failed);
        $this->assertSame(0, $import->rows_created);
        $this->assertNull(User::where('external_code', 'P-2')->first());

        $error = ImportRow::where('import_id', $import->id)->value('error');

        // Con el nombre de quien lo tiene y su código: es lo único que permite
        // arreglarlo sin salir a buscar.
        $this->assertStringContainsString('ana@empresa.cl', $error);
        $this->assertStringContainsString('Ana Pérez', $error);
        $this->assertStringContainsString('P-1', $error);

        // Y sin una sola letra del motor de base de datos.
        $this->assertStringNotContainsString('SQLSTATE', $error);
        $this->assertStringNotContainsString('Duplicate entry', $error);
    }

    public function test_el_mismo_correo_en_dos_filas_con_codigos_distintos_no_rompe_la_carga(): void
    {
        $import = $this->importar([
            ['codigo' => 'P-1', 'nombre' => 'Ana', 'correo' => 'compartido@empresa.cl'],
            ['codigo' => 'P-2', 'nombre' => 'Luis', 'correo' => 'compartido@empresa.cl'],
        ]);

        // La primera entra, la segunda se rechaza. Lo que no puede pasar es
        // que la segunda voltee el archivo.
        $this->assertSame(1, $import->rows_created);
        $this->assertSame(1, $import->rows_failed);
    }

    public function test_el_resumen_avisa_del_correo_repetido_antes_de_importar(): void
    {
        $this->persona('P-1', ['email' => 'ana@empresa.cl']);

        $resumen = $this->ensayar([
            ['codigo' => 'P-2', 'nombre' => 'Otra', 'correo' => 'ana@empresa.cl'],
            ['codigo' => 'P-3', 'nombre' => 'Eva', 'correo' => 'eva@empresa.cl'],
            ['codigo' => 'P-4', 'nombre' => 'Sol', 'correo' => 'eva@empresa.cl'],
        ]);

        // Dos rechazos: la que choca con el directorio y la que choca con la
        // fila de arriba del mismo archivo.
        $this->assertSame(2, $resumen['filas_con_problemas']);
        $this->assertSame(1, $resumen['se_crearan']);

        $motivos = implode(' ', array_merge(...array_column($resumen['problemas'], 'motivos')));
        $this->assertStringContainsString('no pueden compartir la casilla', $motivos);
    }

    public function test_actualizar_a_alguien_con_su_propio_correo_no_es_un_choque(): void
    {
        $this->persona('P-1', ['email' => 'ana@empresa.cl', 'name' => 'Ana']);

        $import = $this->importar([
            ['codigo' => 'P-1', 'nombre' => 'Ana María', 'correo' => 'ana@empresa.cl'],
        ]);

        $this->assertSame(0, $import->rows_failed);
        $this->assertSame(1, $import->rows_updated);
        $this->assertSame('Ana María', User::where('external_code', 'P-1')->value('name'));
    }

    public function test_el_resumen_cuenta_los_mismos_rechazos_que_la_importacion(): void
    {
        $this->persona('P-1', ['email' => 'ana@empresa.cl']);

        $filas = [
            ['codigo' => 'P-2', 'nombre' => 'Otra', 'correo' => 'ana@empresa.cl'],
            ['codigo' => 'P-3', 'nombre' => ''],
            ['codigo' => 'P-4', 'nombre' => 'Eva'],
        ];

        $resumen = $this->ensayar($filas);
        $import = $this->importar($filas);

        $this->assertSame($import->rows_failed, $resumen['filas_con_problemas']);
        $this->assertSame($import->rows_created, $resumen['se_crearan']);
    }

    // -- La columna de estado -----------------------------------------

    public function test_una_fila_inactiva_da_de_baja_a_quien_ya_estaba(): void
    {
        $persona = $this->persona('P-1');

        $import = $this->importar([
            ['codigo' => 'P-1', 'nombre' => 'Ana', 'activo' => '0'],
        ]);

        $persona->refresh();

        $this->assertFalse($persona->active);
        $this->assertSame(User::BAJA_INACTIVA_EN_ORIGEN, $persona->deactivated_reason);
        $this->assertNotNull($persona->deactivated_at);
        $this->assertSame($import->id, $persona->deactivated_import_id);

        // Se dio de baja, no se actualizó ni se rechazó.
        $this->assertSame(1, $import->rows_deactivated);
        $this->assertSame(0, $import->rows_updated);
        $this->assertSame(0, $import->rows_failed);

        // Y sigue existiendo: su historial de evaluaciones depende de eso.
        $this->assertDatabaseHas('users', ['external_code' => 'P-1']);
    }

    public function test_una_fila_inactiva_de_alguien_que_no_esta_no_lo_crea(): void
    {
        $import = $this->importar([
            ['codigo' => 'P-9', 'nombre' => 'Egresada', 'activo' => 'BAJA'],
        ]);

        $this->assertNull(User::where('external_code', 'P-9')->first());
        $this->assertSame(0, $import->rows_created);
        $this->assertSame(1, $import->rows_skipped);
        $this->assertSame(0, $import->rows_deactivated);
    }

    public function test_entiende_las_formas_en_que_se_escribe_lo_mismo(): void
    {
        foreach (['0', 'NO', 'Baja', 'Inactivo', 'FALSE', 'Desvinculado'] as $valor) {
            $this->assertSame(
                DirectoryImportService::ACTIVO_NO,
                $this->importador->interpretarActivo($valor),
                "«{$valor}» tendría que leerse como una baja.",
            );
        }

        foreach (['1', 'SI', 'Sí', 'Activo', 'TRUE', 'Vigente'] as $valor) {
            $this->assertSame(
                DirectoryImportService::ACTIVO_SI,
                $this->importador->interpretarActivo($valor),
                "«{$valor}» tendría que leerse como alguien que sigue.",
            );
        }

        $this->assertSame(DirectoryImportService::ACTIVO_VACIO, $this->importador->interpretarActivo(''));
        $this->assertSame(DirectoryImportService::ACTIVO_VACIO, $this->importador->interpretarActivo(null));
    }

    public function test_un_estado_que_no_se_entiende_rechaza_la_fila_en_vez_de_adivinar(): void
    {
        $persona = $this->persona('P-1');

        $import = $this->importar([
            ['codigo' => 'P-1', 'nombre' => 'Ana', 'activo' => 'CON LICENCIA'],
        ]);

        $this->assertSame(1, $import->rows_failed);
        $this->assertTrue($persona->refresh()->active, 'Ante la duda no se toca a nadie.');

        // El motivo trae el valor a la vista: sin eso hay que abrir el archivo
        // para saber qué fue lo que no se entendió.
        $error = ImportRow::where('import_id', $import->id)->value('error');
        $this->assertStringContainsString('CON LICENCIA', $error);
    }

    public function test_quien_vuelve_a_la_nomina_vuelve_a_quedar_activo(): void
    {
        $persona = $this->persona('P-1');
        $persona->deactivate(User::BAJA_AUSENTE, 99);

        $import = $this->importar([['codigo' => 'P-1', 'nombre' => 'Ana']]);

        $persona->refresh();

        $this->assertTrue($persona->active);
        $this->assertSame(1, $import->rows_reactivated);

        // Sin historia de baja encima: una fecha de baja en alguien activo es
        // un dato que se contradice a sí mismo.
        $this->assertNull($persona->deactivated_at);
        $this->assertNull($persona->deactivated_reason);
        $this->assertNull($persona->deactivated_import_id);
    }

    // -- Bajas por ausencia -------------------------------------------

    public function test_sin_sincronizar_no_se_da_de_baja_a_nadie(): void
    {
        $quedaFuera = $this->persona('P-9');

        $import = $this->importar([['codigo' => 'P-1', 'nombre' => 'Ana']]);

        $this->assertTrue($quedaFuera->refresh()->active);
        $this->assertSame(0, $import->rows_deactivated);
    }

    public function test_sincronizando_se_da_de_baja_a_quien_no_vino(): void
    {
        $sigue = $this->persona('P-1');
        $seFue = $this->persona('P-9');

        $import = $this->importar(
            [['codigo' => 'P-1', 'nombre' => 'Ana']],
            ['sincronizar_bajas' => true],
        );

        $this->assertTrue($sigue->refresh()->active);

        $seFue->refresh();
        $this->assertFalse($seFue->active);
        $this->assertSame(User::BAJA_AUSENTE, $seFue->deactivated_reason);
        $this->assertSame($import->id, $seFue->deactivated_import_id);
        $this->assertSame(1, $import->rows_deactivated);
    }

    public function test_cada_baja_por_ausencia_queda_registrada_con_nombre_y_apellido(): void
    {
        $this->persona('P-9', ['name' => 'Marta', 'lastname' => 'Rojas']);

        $import = $this->importar(
            [['codigo' => 'P-1', 'nombre' => 'Ana']],
            ['sincronizar_bajas' => true],
        );

        $fila = ImportRow::where('import_id', $import->id)
            ->where('outcome', ImportRow::DEACTIVATED)
            ->first();

        $this->assertNotNull($fila, 'Un número de bajas que no se puede verificar no sirve.');
        $this->assertSame('P-9', $fila->payload['codigo']);
        $this->assertSame('Marta', $fila->payload['nombre']);
        $this->assertSame('Rojas', $fila->payload['apellido']);
        // No sale de ninguna línea del archivo: sale de que no hay ninguna.
        $this->assertSame(0, $fila->line);
    }

    public function test_la_sincronizacion_no_toca_las_cuentas_administrativas(): void
    {
        $admin = $this->persona('OTRO-ADM', ['role' => Role::ADMIN]);
        $super = $this->persona('SUP-001', ['role' => Role::SUPER_ADMIN]);

        $this->importar(
            [['codigo' => 'P-1', 'nombre' => 'Ana']],
            ['sincronizar_bajas' => true],
        );

        $this->assertTrue($admin->refresh()->active, 'Desactivar administradores cierra la puerta con la llave adentro.');
        $this->assertTrue($super->refresh()->active);
        $this->assertTrue($this->ejecutor->refresh()->active);
    }

    public function test_la_sincronizacion_no_toca_a_quien_nunca_entro_por_una_planilla(): void
    {
        // Sin código interno: se creó a mano desde el directorio, así que
        // ninguna planilla puede decidir que se fue.
        $aMano = User::factory()->create([
            'external_code' => null,
            'role' => Role::COLLABORATOR,
            'active' => true,
        ]);

        $this->importar(
            [['codigo' => 'P-1', 'nombre' => 'Ana']],
            ['sincronizar_bajas' => true],
        );

        $this->assertTrue($aMano->refresh()->active);
    }

    public function test_una_baja_vieja_no_se_repisa_con_la_fecha_de_hoy(): void
    {
        $persona = $this->persona('P-9');
        $persona->deactivate(User::BAJA_MANUAL, null);
        $cuando = $persona->refresh()->deactivated_at;

        $this->travel(2)->days();

        $this->importar(
            [['codigo' => 'P-1', 'nombre' => 'Ana']],
            ['sincronizar_bajas' => true],
        );

        $persona->refresh();

        $this->assertSame(User::BAJA_MANUAL, $persona->deactivated_reason);
        $this->assertTrue($cuando->equalTo($persona->deactivated_at), 'La fecha en que se fue de verdad no se pisa.');
    }

    public function test_una_fila_rechazada_no_da_de_baja_por_ausencia_a_esa_persona(): void
    {
        // Está en el archivo, pero su fila no pasa: falta el nombre. Que la
        // planilla la nombre es lo que cuenta —darla de baja sería castigarla
        // por un error de tipeo en otra columna—.
        $persona = $this->persona('P-1');

        $import = $this->importar(
            [['codigo' => 'P-1', 'nombre' => '']],
            ['sincronizar_bajas' => true],
        );

        $this->assertSame(1, $import->rows_failed);
        $this->assertSame(0, $import->rows_deactivated);
        $this->assertTrue($persona->refresh()->active);
    }

    // -- El resumen dice lo que después pasa --------------------------

    public function test_el_resumen_previo_cuenta_exactamente_lo_que_hace_la_importacion(): void
    {
        $this->persona('P-1');                       // viene: se actualiza
        $this->persona('P-2');                       // viene de baja: se desactiva
        $this->persona('P-9');                       // no viene: se desactiva
        $this->persona('P-8')->deactivate(User::BAJA_AUSENTE);  // vuelve

        $filas = [
            ['codigo' => 'P-1', 'nombre' => 'Ana'],
            ['codigo' => 'P-2', 'nombre' => 'Luis', 'activo' => '0'],
            ['codigo' => 'P-8', 'nombre' => 'Eva'],
            ['codigo' => 'P-3', 'nombre' => 'Nueva'],
        ];

        $resumen = $this->ensayar($filas, ['sincronizar_bajas' => true]);
        $import = $this->importar($filas, ['sincronizar_bajas' => true]);

        $this->assertSame($import->rows_created, $resumen['se_crearan']);
        $this->assertSame($import->rows_updated, $resumen['se_actualizaran']);
        $this->assertSame($import->rows_reactivated, $resumen['se_reactivaran']);
        $this->assertSame(
            $import->rows_deactivated,
            $resumen['bajas_por_origen'] + $resumen['bajas_por_ausencia'],
            'Si el resumen y la importación se separan, la pantalla que existe para avisar antes empieza a mentir.',
        );

        $this->assertSame(1, $resumen['se_crearan']);
        $this->assertSame(2, $resumen['se_actualizaran']);
        $this->assertSame(1, $resumen['se_reactivaran']);
        $this->assertSame(1, $resumen['bajas_por_origen']);
        $this->assertSame(1, $resumen['bajas_por_ausencia']);
    }

    public function test_el_resumen_cuenta_las_bajas_por_ausencia_aunque_no_se_haya_pedido_sincronizar(): void
    {
        $this->persona('P-9');

        // El número es justamente lo que hace falta para decidir si marcar la
        // casilla. Mostrarlo solo cuando ya está marcada sería enseñar la
        // consecuencia después de haber elegido.
        $resumen = $this->ensayar([['codigo' => 'P-1', 'nombre' => 'Ana']]);

        $this->assertSame(1, $resumen['bajas_por_ausencia']);
        $this->assertSame('P-9', $resumen['muestra_ausentes'][0]['codigo']);
    }

    public function test_el_resumen_dice_que_parte_del_directorio_cubre_el_archivo(): void
    {
        $this->persona('P-1');
        $this->persona('P-2');
        $this->persona('P-3');

        $resumen = $this->ensayar([['codigo' => 'P-1', 'nombre' => 'Ana']]);

        // Una nómina que nombra a 1 de 3 casi nunca es la nómina completa, y
        // este par de números es lo único que permite verlo venir.
        $this->assertSame(1, $resumen['cobertura']['nombradas_en_archivo']);
        $this->assertSame(3, $resumen['cobertura']['sincronizables']);
    }

    public function test_el_resumen_junta_los_valores_de_estado_que_no_entiende(): void
    {
        $resumen = $this->ensayar([
            ['codigo' => 'P-1', 'nombre' => 'Ana', 'activo' => 'LICENCIA'],
            ['codigo' => 'P-2', 'nombre' => 'Luis', 'activo' => 'LICENCIA'],
            ['codigo' => 'P-3', 'nombre' => 'Eva', 'activo' => '1'],
        ]);

        $this->assertSame(['LICENCIA' => 2], $resumen['activo_ilegible']);
        $this->assertSame(2, $resumen['filas_con_problemas']);
    }
}
