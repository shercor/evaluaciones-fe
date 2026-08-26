<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\Import;
use App\Models\ImportDraft;
use App\Models\ImportRow;
use App\Models\User;
use App\Services\ImportHousekeeping;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * La limpieza de lo que dejan las importaciones.
 *
 * Es todo trabajo invisible —nadie mira la carpeta del servidor ni la columna
 * de contraseñas—, así que si un día deja de correr, no se nota hasta que
 * molesta. Por eso está probado.
 */
class ImportHousekeepingTest extends TestCase
{
    use RefreshDatabase;

    private const CARPETA = 'importaciones/borradores';

    private ImportHousekeeping $limpieza;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->limpieza = app(ImportHousekeeping::class);
    }

    private function borrador(string $nombre, int $horasDeAntiguedad): ImportDraft
    {
        $ruta = self::CARPETA.'/'.$nombre;
        Storage::disk('local')->put($ruta, 'codigo,nombre');

        $borrador = ImportDraft::create([
            'user_id' => User::factory()->create()->id,
            'filename' => $nombre,
            'stored_path' => $ruta,
            'headers' => [],
            'samples' => [],
            'rows_total' => 1,
        ]);

        // `forceFill` y no `update`: `created_at` no está en `$fillable` y una
        // asignación masiva lo descartaría sin decir nada.
        $borrador->forceFill(['created_at' => now()->subHours($horasDeAntiguedad)])->save();

        return $borrador;
    }

    public function test_borra_las_planillas_abandonadas_con_su_archivo(): void
    {
        $vieja = $this->borrador('vieja.csv', 30);
        $reciente = $this->borrador('reciente.csv', 2);

        $this->assertSame(1, $this->limpieza->borrarBorradores(24));

        $this->assertNull(ImportDraft::find($vieja->id));
        Storage::disk('local')->assertMissing($vieja->stored_path);

        $this->assertNotNull(ImportDraft::find($reciente->id));
        Storage::disk('local')->assertExists($reciente->stored_path);
    }

    public function test_barre_los_archivos_que_quedaron_sin_fila(): void
    {
        // Así queda el disco cuando la fila se va por otro camino: el borrado
        // en cascada de la persona que subió la planilla, un cambio de base de
        // datos, un respaldo restaurado.
        $huerfano = self::CARPETA.'/sin-dueno.csv';
        $reciente = self::CARPETA.'/de-hoy.csv';

        Storage::disk('local')->put($huerfano, 'x');
        Storage::disk('local')->put($reciente, 'x');
        touch(Storage::disk('local')->path($huerfano), now()->subHours(30)->getTimestamp());

        $this->assertSame(1, $this->limpieza->barrerArchivosHuerfanos(24));

        Storage::disk('local')->assertMissing($huerfano);
        Storage::disk('local')->assertExists($reciente);
    }

    public function test_olvida_las_contrasenas_viejas_pero_conserva_la_auditoria(): void
    {
        $import = Import::create([
            'user_id' => User::factory()->create()->id,
            'filename' => 'nomina.csv',
            'status' => Import::DONE,
        ]);

        $fila = function (int $linea, int $dias) use ($import) {
            $fila = ImportRow::create([
                'import_id' => $import->id,
                'line' => $linea,
                'outcome' => ImportRow::CREATED,
                'payload' => ['codigo' => 'X-'.$linea],
                'temporary_password' => 'ABC123XYZ0',
            ]);

            $fila->forceFill(['created_at' => now()->subDays($dias)])->save();

            return $fila;
        };

        $vieja = $fila(2, 120);
        $nueva = $fila(3, 5);

        $this->assertSame(1, $this->limpieza->olvidarContrasenas(90));

        // La fila sigue estando: el registro de qué pasó con cada línea es la
        // auditoría de la carga. Lo que se va es solo la contraseña.
        $this->assertNotNull($vieja->fresh());
        $this->assertNull($vieja->fresh()->temporary_password);
        $this->assertSame(['codigo' => 'X-2'], $vieja->fresh()->payload);

        $this->assertSame('ABC123XYZ0', $nueva->fresh()->temporary_password);
    }
}
