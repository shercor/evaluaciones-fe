<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\Role;
use App\Models\Import;
use App\Models\ImportRow;
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
        'cargo', 'cargo_codigo', 'sucursal', 'sucursal_codigo',
        'codigo_supervisor',
    ];

    /**
     * Las mismas columnas, explicadas.
     *
     * Esto lo consume la pantalla de homologación, donde hay que decirle a una
     * persona qué es cada campo del sistema **antes** de que elija con cuál de
     * su planilla conectarlo. Sin la explicación, «código» y «código
     * supervisor» se confunden todo el tiempo.
     *
     * @var array<string, array{etiqueta: string, obligatoria: bool, ayuda: string}>
     */
    public const COLUMN_DEFINITIONS = [
        'codigo' => [
            'etiqueta' => 'Código interno',
            'obligatoria' => true,
            'ayuda' => 'La identidad de la persona: RUT, ficha o número de empleado. Con esto se decide si la fila crea a alguien nuevo o actualiza a quien ya está.',
        ],
        'nombre' => [
            'etiqueta' => 'Nombre',
            'obligatoria' => true,
            'ayuda' => 'Solo el nombre. Si tu planilla trae el nombre completo en una sola columna, conectala acá: el apellido puede quedar vacío.',
        ],
        'apellido' => [
            'etiqueta' => 'Apellido',
            'obligatoria' => false,
            'ayuda' => 'Opcional. Se usa para las iniciales y para ordenar el directorio.',
        ],
        'correo' => [
            'etiqueta' => 'Correo',
            'obligatoria' => false,
            'ayuda' => 'Quien lo tenga recibe una invitación para definir su contraseña. Quien no, queda con una contraseña temporal que se descarga al terminar.',
        ],
        'cargo' => [
            'etiqueta' => 'Cargo',
            'obligatoria' => false,
            'ayuda' => 'El nombre del cargo, en texto. Los que no existan se crean solos.',
        ],
        'cargo_codigo' => [
            'etiqueta' => 'Código del cargo',
            'obligatoria' => false,
            'ayuda' => 'Solo si tu planilla trae el código además del nombre. Si conectás el código sin el nombre, el cargo tiene que estar cargado de antes: con un código suelto no hay con qué nombrarlo.',
        ],
        'sucursal' => [
            'etiqueta' => 'Sucursal',
            'obligatoria' => false,
            'ayuda' => 'El nombre de la sucursal, en texto. Las que no existan se crean solas.',
        ],
        'sucursal_codigo' => [
            'etiqueta' => 'Código de la sucursal',
            'obligatoria' => false,
            'ayuda' => 'Solo si tu planilla trae el código además del nombre. Si conectás el código sin el nombre, la sucursal tiene que estar cargada de antes: con un código suelto no hay con qué nombrarla.',
        ],
        'codigo_supervisor' => [
            'etiqueta' => 'Código del supervisor',
            'obligatoria' => false,
            'ayuda' => 'El código de quien la supervisa, no su nombre. Puede estar más abajo en el mismo archivo: la jerarquía se arma al final.',
        ],
    ];

    /**
     * @param  array<int, array<string, mixed>>  $rows  filas ya leídas del archivo
     */
    public function import(array $rows, Import $import, bool $enviarInvitaciones = true): Import
    {
        $import->update(['rows_total' => count($rows), 'status' => Import::PENDING]);

        // Uno por corrida, no uno por fila: guarda el catálogo en memoria y
        // las sucursales que va creando, así la segunda persona de la misma
        // sucursal ya la encuentra.
        $catalogos = new CatalogResolver;

        /** @var array<string, string> $supervisoresPendientes  codigo => codigo_supervisor */
        $supervisoresPendientes = [];
        $creados = 0;
        $actualizados = 0;
        $fallidos = 0;

        foreach ($rows as $indice => $fila) {
            // +2: la primera línea es el encabezado y las planillas cuentan desde 1.
            $linea = $indice + 2;
            $fila = $this->normalizar($fila);

            $problemas = $this->problemas($fila);

            if ($problemas !== []) {
                $this->registrarFila($import, $linea, ImportRow::FAILED, $fila, implode(' ', $problemas));
                $fallidos++;

                continue;
            }

            // La sucursal y el cargo se resuelven antes de guardar: un código
            // que no está cargado rechaza la fila, y eso no es un error de
            // ejecución sino un dato que falta.
            [$idSucursal, $errorSucursal] = $catalogos->resolver('sucursal', $fila['sucursal_codigo'], $fila['sucursal']);
            [$idCargo, $errorCargo] = $catalogos->resolver('cargo', $fila['cargo_codigo'], $fila['cargo']);

            if ($errorSucursal !== null || $errorCargo !== null) {
                $this->registrarFila(
                    $import, $linea, ImportRow::FAILED, $fila,
                    trim(($errorSucursal ?? '').' '.($errorCargo ?? '')),
                );
                $fallidos++;

                continue;
            }

            try {
                [$resultado, $temporal] = DB::transaction(
                    fn () => $this->guardarPersona($fila, $enviarInvitaciones, $idSucursal, $idCargo),
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
    private function guardarPersona(
        array $fila,
        bool $enviarInvitaciones,
        ?int $idSucursal,
        ?int $idCargo,
    ): array {
        $existente = User::where('external_code', $fila['codigo'])->first();

        $datos = [
            'external_code' => $fila['codigo'],
            'name' => $fila['nombre'],
            'lastname' => $fila['apellido'],
            'active' => true,
            'branch_office_id' => $idSucursal,
            'job_position_id' => $idCargo,
        ];

        // El correo puede faltar. Cuando falta se inventa uno interno para no
        // romper la unicidad de la columna, pero no se le manda nada.
        $tieneCorreo = ! blank($fila['correo']);
        $datos['email'] = $tieneCorreo
            ? $fila['correo']
            : ($existente?->email ?? $this->correoInterno($fila['codigo']));

        $temporal = null;

        if ($existente) {
            // Actualizar no toca la contraseña ni el rol. El rol **no viene en
            // la planilla**: los administradores se nombran a mano en el
            // directorio, y si cada importación lo reescribiera, la segunda
            // carga de la nómina los devolvería a todos a colaborador.
            $existente->update($datos);

            return [ImportRow::UPDATED, null];
        }

        // Quien entra por planilla entra como colaborador, siempre.
        $datos['role'] = Role::COLLABORATOR->value;

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
     * Deja la fila como la va a guardar el sistema.
     *
     * Es pública porque la homologación necesita mostrar **exactamente** esto
     * en su resumen: de nada sirve una vista previa que muestre otra cosa que
     * lo que después se guarda.
     */
    public function normalizar(array $fila): array
    {
        $limpia = [];

        foreach (self::COLUMNS as $columna) {
            $valor = $fila[$columna] ?? null;
            $limpia[$columna] = is_string($valor) ? trim($valor) : $valor;
        }

        $limpia['correo'] = mb_strtolower((string) $limpia['correo']);

        return $limpia;
    }

    /**
     * Qué le falta o le sobra a una fila, en castellano.
     *
     * La usan el importador y el ensayo previo de la homologación, para que
     * el resumen que se muestra antes de importar diga exactamente lo mismo
     * que va a decir el resultado.
     *
     * @return array<int, string>  vacío si la fila está bien
     */
    public function problemas(array $filaNormalizada): array
    {
        $validador = Validator::make($filaNormalizada, $this->reglas(), $this->mensajes());

        return $validador->fails() ? $validador->errors()->all() : [];
    }

    private function reglas(): array
    {
        return [
            'codigo' => ['required', 'string', 'max:255'],
            'nombre' => ['required', 'string', 'max:255'],
            'apellido' => ['nullable', 'string', 'max:255'],
            'correo' => ['nullable', 'email', 'max:255'],
            'cargo' => ['nullable', 'string', 'max:255'],
            'cargo_codigo' => ['nullable', 'string', 'max:255'],
            'sucursal' => ['nullable', 'string', 'max:255'],
            'sucursal_codigo' => ['nullable', 'string', 'max:255'],
            'codigo_supervisor' => ['nullable', 'string', 'max:255'],
        ];
    }

    private function mensajes(): array
    {
        return [
            'codigo.required' => 'Falta el código de la persona.',
            'nombre.required' => 'Falta el nombre.',
            'correo.email' => 'El correo no tiene un formato válido.',
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
