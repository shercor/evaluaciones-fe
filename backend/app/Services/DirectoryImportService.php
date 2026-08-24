<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\Role;
use App\Models\BranchOffice;
use App\Models\Import;
use App\Models\ImportRow;
use App\Models\JobPosition;
use App\Models\User;
use App\Notifications\DirectoryInvitation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

/**
 * Carga la nómina desde una planilla.
 *
 * Reglas de fondo:
 *
 * - **Una fila mala no voltea el archivo.** Cada fila se procesa y se registra
 *   por separado; al final se informa qué entró, qué se actualizó y qué se
 *   rechazó con su motivo. Un archivo de 800 personas con 12 errores importa
 *   788 y explica los 12.
 * - **`external_code` es la identidad.** Con él se decide si una fila crea o
 *   actualiza, así que reimportar la misma planilla es idempotente.
 * - **La jerarquía se resuelve al final.** Una fila puede nombrar como
 *   supervisor a alguien que aparece más abajo en el archivo, así que primero
 *   entran todas las personas y después se enlazan.
 * - **Dos caminos para la contraseña**, porque en retail buena parte de la
 *   nómina no tiene correo corporativo: con dirección se envía invitación; sin
 *   dirección se genera una temporal que el administrador entrega en mano.
 */
class DirectoryImportService
{
    public function __construct(private readonly SupervisionChain $chain) {}

    /**
     * Columnas que entiende la planilla. La primera fila es el encabezado.
     */
    public const COLUMNS = [
        'codigo', 'nombre', 'apellido', 'correo',
        'cargo', 'sucursal', 'codigo_supervisor', 'rol',
    ];

    /**
     * @param  array<int, array<string, mixed>>  $rows  filas ya leídas del archivo
     */
    public function import(array $rows, Import $import, bool $enviarInvitaciones = true): Import
    {
        $import->update(['rows_total' => count($rows), 'status' => Import::PENDING]);

        /** @var array<string, string> $supervisoresPendientes  codigo => codigo_supervisor */
        $supervisoresPendientes = [];
        $creados = 0;
        $actualizados = 0;
        $fallidos = 0;

        foreach ($rows as $indice => $fila) {
            // +2: la primera línea es el encabezado y las planillas cuentan desde 1.
            $linea = $indice + 2;
            $fila = $this->normalizar($fila);

            $validador = Validator::make($fila, $this->reglas(), $this->mensajes());

            if ($validador->fails()) {
                $this->registrarFila($import, $linea, ImportRow::FAILED, $fila, implode(' ', $validador->errors()->all()));
                $fallidos++;

                continue;
            }

            try {
                [$resultado, $temporal] = DB::transaction(
                    fn () => $this->guardarPersona($fila, $enviarInvitaciones),
                );

                $this->registrarFila($import, $linea, $resultado, $fila, null, $temporal);

                $resultado === ImportRow::CREATED ? $creados++ : $actualizados++;

                if (! blank($fila['codigo_supervisor'])) {
                    $supervisoresPendientes[$fila['codigo']] = $fila['codigo_supervisor'];
                }
            } catch (\Throwable $e) {
                Log::error('[importación] fila '.$linea.': '.$e->getMessage());
                $this->registrarFila($import, $linea, ImportRow::FAILED, $fila, 'Error al guardar: '.$e->getMessage());
                $fallidos++;
            }
        }

        // Recién ahora existen todas las personas, así que se puede enlazar.
        $erroresJerarquia = $this->enlazarSupervisores($supervisoresPendientes);

        $import->update([
            'status' => Import::DONE,
            'rows_created' => $creados,
            'rows_updated' => $actualizados,
            'rows_failed' => $fallidos,
            'error' => $erroresJerarquia === [] ? null : implode(' | ', $erroresJerarquia),
        ]);

        return $import->refresh();
    }

    // -----------------------------------------------------------------

    /**
     * @return array{0: string, 1: string|null}  resultado y contraseña temporal
     */
    private function guardarPersona(array $fila, bool $enviarInvitaciones): array
    {
        $existente = User::where('external_code', $fila['codigo'])->first();

        $datos = [
            'external_code' => $fila['codigo'],
            'name' => $fila['nombre'],
            'lastname' => $fila['apellido'],
            'role' => $fila['rol'] ?: Role::COLLABORATOR->value,
            'active' => true,
            'branch_office_id' => $this->resolverSucursal($fila['sucursal']),
            'job_position_id' => $this->resolverCargo($fila['cargo']),
        ];

        // El correo puede faltar. Cuando falta se inventa uno interno para no
        // romper la unicidad de la columna, pero no se le manda nada.
        $tieneCorreo = ! blank($fila['correo']);
        $datos['email'] = $tieneCorreo
            ? $fila['correo']
            : ($existente?->email ?? $this->correoInterno($fila['codigo']));

        $temporal = null;

        if ($existente) {
            // Actualizar no toca la contraseña: quien ya entraba, sigue entrando.
            $existente->update($datos);

            return [ImportRow::UPDATED, null];
        }

        if ($tieneCorreo) {
            // Sin contraseña: la define desde el enlace de la invitación.
            $datos['password'] = null;
            $datos['must_set_password'] = true;
        } else {
            $temporal = $this->generarContrasenaTemporal();
            $datos['password'] = Hash::make($temporal);
            $datos['must_set_password'] = true;
        }

        $usuario = User::create($datos);

        if ($tieneCorreo && $enviarInvitaciones) {
            // Si el correo falla, la persona igual quedó creada: se le puede
            // reenviar la invitación desde el directorio.
            try {
                $usuario->notify(new DirectoryInvitation);
            } catch (\Throwable $e) {
                Log::warning('[importación] no se pudo invitar a '.$usuario->email.': '.$e->getMessage());
            }
        }

        return [ImportRow::CREATED, $temporal];
    }

    /**
     * Enlaza cada persona con su supervisor, rechazando los ciclos.
     *
     * @param  array<string, string>  $pendientes  codigo => codigo_supervisor
     * @return array<int, string>  avisos para el resumen
     */
    private function enlazarSupervisores(array $pendientes): array
    {
        if ($pendientes === []) {
            return [];
        }

        $ids = User::whereIn('external_code', array_unique([...array_keys($pendientes), ...array_values($pendientes)]))
            ->pluck('id', 'external_code');

        $avisos = [];

        foreach ($pendientes as $codigo => $codigoSupervisor) {
            $id = $ids[$codigo] ?? null;
            $supervisorId = $ids[$codigoSupervisor] ?? null;

            if ($id === null) {
                continue;
            }

            if ($supervisorId === null) {
                $avisos[] = "El supervisor «{$codigoSupervisor}» de «{$codigo}» no existe en el archivo ni en el directorio.";

                continue;
            }

            if ($this->chain->wouldCreateCycle($id, $supervisorId)) {
                $avisos[] = "Asignar «{$codigoSupervisor}» como supervisor de «{$codigo}» habría creado un ciclo en el organigrama.";

                continue;
            }

            User::whereKey($id)->update(['supervisor_id' => $supervisorId]);
        }

        return $avisos;
    }

    private function registrarFila(
        Import $import,
        int $linea,
        string $resultado,
        array $payload,
        ?string $error = null,
        ?string $temporal = null,
    ): void {
        ImportRow::create([
            'import_id' => $import->id,
            'line' => $linea,
            'outcome' => $resultado,
            'payload' => $payload,
            'error' => $error,
            'temporary_password' => $temporal,
        ]);
    }

    /**
     * Las sucursales y los cargos se crean solos si no existen: obligar a
     * cargarlos antes convertiría la importación en un trámite de dos pasos.
     */
    private function resolverSucursal(?string $nombre): ?int
    {
        if (blank($nombre)) {
            return null;
        }

        return BranchOffice::firstOrCreate(['name' => trim($nombre)], ['active' => true])->id;
    }

    private function resolverCargo(?string $nombre): ?int
    {
        if (blank($nombre)) {
            return null;
        }

        return JobPosition::firstOrCreate(['name' => trim($nombre)], ['active' => true])->id;
    }

    private function normalizar(array $fila): array
    {
        $limpia = [];

        foreach (self::COLUMNS as $columna) {
            $valor = $fila[$columna] ?? null;
            $limpia[$columna] = is_string($valor) ? trim($valor) : $valor;
        }

        $limpia['rol'] = mb_strtolower((string) $limpia['rol']);
        $limpia['correo'] = mb_strtolower((string) $limpia['correo']);

        return $limpia;
    }

    private function reglas(): array
    {
        return [
            'codigo' => ['required', 'string', 'max:255'],
            'nombre' => ['required', 'string', 'max:255'],
            'apellido' => ['nullable', 'string', 'max:255'],
            'correo' => ['nullable', 'email', 'max:255'],
            'cargo' => ['nullable', 'string', 'max:255'],
            'sucursal' => ['nullable', 'string', 'max:255'],
            'codigo_supervisor' => ['nullable', 'string', 'max:255'],
            // El super administrador no se reparte por planilla: es personal
            // de Idea Uno, no de la empresa.
            'rol' => ['nullable', 'in:admin,collaborator'],
        ];
    }

    private function mensajes(): array
    {
        return [
            'codigo.required' => 'Falta el código de la persona.',
            'nombre.required' => 'Falta el nombre.',
            'correo.email' => 'El correo no tiene un formato válido.',
            'rol.in' => 'El rol debe ser «admin» o «collaborator».',
        ];
    }

    private function correoInterno(string $codigo): string
    {
        return 'sin-correo.'.Str::slug($codigo).'@interno.local';
    }

    /**
     * Contraseña temporal legible: se dicta o se escribe a mano en un papel,
     * así que se evitan los caracteres que se confunden entre sí.
     */
    private function generarContrasenaTemporal(): string
    {
        $alfabeto = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
        $clave = '';

        for ($i = 0; $i < 10; $i++) {
            $clave .= $alfabeto[random_int(0, strlen($alfabeto) - 1)];
        }

        return $clave;
    }
}
