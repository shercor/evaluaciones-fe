<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\Role;
use App\Exceptions\ImportRowException;
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
        'codigo_supervisor', 'activo',
    ];

    /**
     * Cómo puede venir escrito «esta persona sigue en la empresa».
     *
     * La lista es larga a propósito. Cada sistema de Recursos Humanos escribe
     * lo mismo distinto —`1`, `SI`, `Activo`, `Vigente`, `A`— y obligar a
     * normalizar la planilla antes de subirla es pedirle a alguien que edite
     * 4.000 celdas a mano, que es exactamente el trabajo que esta pantalla
     * existe para evitar.
     *
     * Lo que **no** hay es una regla general del tipo «cualquier cosa que no
     * sea vacío es verdadero»: ver `interpretarActivo()`.
     *
     * @var array<int, string>
     */
    private const ACTIVA = [
        '1', 'true', 't', 'si', 'sí', 's', 'y', 'yes', 'x',
        'activo', 'activa', 'active', 'vigente', 'vigentes',
        'habilitado', 'habilitada', 'alta', 'a', 'v', 'h',
    ];

    /** @var array<int, string> */
    private const INACTIVA = [
        '0', 'false', 'f', 'no', 'n',
        'inactivo', 'inactiva', 'inactive', 'pasivo', 'pasiva',
        'baja', 'bajas', 'deshabilitado', 'deshabilitada',
        'eliminado', 'eliminada', 'borrado', 'borrada',
        'retirado', 'retirada', 'desvinculado', 'desvinculada',
        'finiquitado', 'finiquitada', 'cesado', 'cesada', 'egresado', 'egresada',
        'i', 'b', 'd', 'e',
    ];

    /** Los cuatro estados posibles de la celda de la columna «activo». */
    public const ACTIVO_VACIO = 'vacio';

    public const ACTIVO_SI = 'si';

    public const ACTIVO_NO = 'no';

    public const ACTIVO_ILEGIBLE = 'ilegible';

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
        'activo' => [
            'etiqueta' => 'Sigue en la empresa',
            'obligatoria' => false,
            'ayuda' => 'La columna con la que tu sistema marca a quien ya no está: «1/0», «SI/NO», «Activo/Baja». Quien venga en falso se da de baja acá en vez de crearse. Si tu planilla ya trae solo gente vigente, dejala sin conectar.',
        ],
    ];

    /**
     * @param  array<int, array<string, mixed>>  $rows  filas ya leídas del archivo
     * @param  array<string, mixed>  $opciones  `enviar_invitaciones`, `sincronizar_bajas`, `ejecutor_id`
     */
    public function import(array $rows, Import $import, array $opciones = []): Import
    {
        $enviarInvitaciones = (bool) ($opciones['enviar_invitaciones'] ?? true);
        $sincronizarBajas = (bool) ($opciones['sincronizar_bajas'] ?? false);
        $ejecutorId = isset($opciones['ejecutor_id']) ? (int) $opciones['ejecutor_id'] : null;

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
        $bajas = 0;
        $reincorporados = 0;
        $omitidos = 0;

        /**
         * Todo código que el archivo **nombre**, pase lo que pase con su fila.
         *
         * Es lo que decide quién está ausente, y por eso se llena antes de
         * validar nada: una fila rechazada porque el correo estaba mal escrito
         * igual nombra a esa persona, y darla de baja por «no vino en la
         * nómina» sería castigarla por un error de tipeo en otra columna.
         *
         * @var array<string, true>
         */
        $presentes = [];

        foreach ($rows as $indice => $fila) {
            // +2: la primera línea es el encabezado y las planillas cuentan desde 1.
            $linea = $indice + 2;
            $fila = $this->normalizar($fila);

            if (! blank($fila['codigo'])) {
                $presentes[$fila['codigo']] = true;
            }

            $problemas = $this->problemas($fila);

            if ($problemas !== []) {
                $this->registrarFila($import, $linea, ImportRow::FAILED, $fila, implode(' ', $problemas));
                $fallidos++;

                continue;
            }

            // La columna de estado se mira antes que ninguna otra: si el
            // origen dice que esta persona ya no está, no hay sucursal que
            // resolver ni cargo que crear. Crear a alguien para darlo de baja
            // en el mismo movimiento ensucia el catálogo con las sucursales de
            // gente que se fue.
            if ($this->interpretarActivo($fila['activo']) === self::ACTIVO_NO) {
                [$resultado, $detalle] = $this->bajarPorOrigen($fila, $import, $ejecutorId);

                $this->registrarFila($import, $linea, $resultado, $fila, $detalle);
                $resultado === ImportRow::DEACTIVATED ? $bajas++ : $omitidos++;

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
                [$resultado, $temporal, $reincorporada] = DB::transaction(
                    fn () => $this->guardarPersona($fila, $enviarInvitaciones, $idSucursal, $idCargo),
                );

                $this->registrarFila(
                    $import, $linea, $resultado, $fila,
                    $reincorporada ? 'Estaba inactiva y vuelve a quedar activa.' : null,
                    $temporal,
                );

                $resultado === ImportRow::CREATED ? $creados++ : $actualizados++;

                if ($reincorporada) {
                    $reincorporados++;
                }

                if (! blank($fila['codigo_supervisor'])) {
                    $supervisoresPendientes[$fila['codigo']] = $fila['codigo_supervisor'];
                }
            } catch (ImportRowException $e) {
                // Un dato mal puesto, no una falla: su mensaje ya está escrito
                // para que alguien lo lea y sepa qué corregir en la planilla.
                $this->registrarFila($import, $linea, ImportRow::FAILED, $fila, $e->getMessage());
                $fallidos++;
            } catch (\Throwable $e) {
                // Cualquier otra cosa sí es una falla. El detalle va al log y
                // no a la pantalla: un SQLSTATE con el nombre de la base y del
                // host adentro no le sirve a quien está cargando la nómina.
                Log::error('[importación] fila '.$linea.': '.$e->getMessage());
                $this->registrarFila(
                    $import, $linea, ImportRow::FAILED, $fila,
                    'No se pudo guardar esta fila. El detalle quedó en el registro del servidor.',
                );
                $fallidos++;
            }
        }

        // Recién ahora existen todas las personas, así que se puede enlazar.
        $erroresJerarquia = $this->enlazarSupervisores($supervisoresPendientes);

        // Y recién ahora se sabe a quién nombró el archivo, que es lo único
        // que permite decir quién falta.
        if ($sincronizarBajas) {
            $bajas += $this->bajarAusentes($presentes, $import, $ejecutorId);
        }

        $import->update([
            'status' => Import::DONE,
            'rows_created' => $creados,
            'rows_updated' => $actualizados,
            'rows_failed' => $fallidos,
            'rows_skipped' => $omitidos,
            'rows_deactivated' => $bajas,
            'rows_reactivated' => $reincorporados,
            'error' => $erroresJerarquia === [] ? null : implode(' | ', $erroresJerarquia),
        ]);

        return $import->refresh();
    }

    /**
     * Qué dice la celda de «activo», en uno de cuatro estados.
     *
     * Son cuatro y no dos porque «vacío» y «no lo entiendo» son cosas
     * distintas y merecen respuestas distintas. Vacío es una planilla que
     * simplemente no trae el dato en esa fila, y se toma como activa. Ilegible
     * es una columna que dice `PASIVO/LICENCIA` o trae una fecha de finiquito,
     * y ahí la fila se **rechaza con el valor a la vista** en vez de adivinar.
     *
     * La tentación es tratar cualquier cosa que no sea vacío como verdadera.
     * Con esa regla, una columna que diga «BAJA» deja activo a todo el mundo y
     * la sincronización que se acaba de pedir no da de baja a nadie, sin que
     * nada avise.
     */
    public function interpretarActivo(?string $valor): string
    {
        $limpio = mb_strtolower(trim((string) $valor));

        return match (true) {
            $limpio === '' => self::ACTIVO_VACIO,
            in_array($limpio, self::ACTIVA, true) => self::ACTIVO_SI,
            in_array($limpio, self::INACTIVA, true) => self::ACTIVO_NO,
            default => self::ACTIVO_ILEGIBLE,
        };
    }

    // -----------------------------------------------------------------

    /**
     * @return array{0: string, 1: string|null, 2: bool} resultado, contraseña temporal y si volvió de una baja
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

        // Dos personas no pueden compartir la casilla, y el código es la
        // identidad: si el correo ya es de **otra** fila del directorio, esto
        // no es la misma persona con el correo repetido, son dos personas y
        // una de las dos está mal.
        //
        // Sin esta comprobación la fila llegaba igual al `INSERT` y explotaba
        // contra el índice único, así que la pantalla mostraba el SQLSTATE en
        // vez de qué corregir.
        if ($tieneCorreo) {
            $otro = User::where('email', $datos['email'])
                ->when($existente !== null, fn ($q) => $q->whereKeyNot($existente->id))
                ->first();

            if ($otro !== null) {
                throw new ImportRowException(
                    'El correo «'.$datos['email'].'» ya es de '.$otro->fullName()
                    .' (código '.($otro->external_code ?? 'sin código').'). '
                    .'Dos personas no pueden compartir la casilla: corregí el correo o el código en la planilla.',
                );
            }
        }

        $temporal = null;

        if ($existente) {
            // Venir en la nómina es la definición de estar activo, así que
            // quien vuelve a aparecer vuelve a quedar activo —y sin la
            // historia de baja encima: dejar `deactivated_at` puesto en
            // alguien activo es un dato que se contradice a sí mismo—.
            $reincorporada = ! $existente->active;

            if ($reincorporada) {
                $datos['deactivated_at'] = null;
                $datos['deactivated_reason'] = null;
                $datos['deactivated_import_id'] = null;
            }

            // Actualizar no toca la contraseña ni el rol. El rol **no viene en
            // la planilla**: los administradores se nombran a mano en el
            // directorio, y si cada importación lo reescribiera, la segunda
            // carga de la nómina los devolvería a todos a colaborador.
            $existente->update($datos);

            return [ImportRow::UPDATED, null, $reincorporada];
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

        return [ImportRow::CREATED, $temporal, false];
    }

    /**
     * La planilla trae a esta persona marcada como inactiva.
     *
     * Tres desenlaces, y solo uno escribe. No está en el directorio: no se
     * crea —crear a alguien para darlo de baja en el mismo movimiento no deja
     * nada útil y ensucia el catálogo con las sucursales de gente que ya no
     * está—. Ya estaba inactiva: no se toca, para no pisar la fecha en que se
     * fue de verdad con la de hoy. Está activa: se da de baja.
     *
     * @return array{0: string, 1: string|null} resultado y detalle para la fila
     */
    private function bajarPorOrigen(array $fila, Import $import, ?int $ejecutorId): array
    {
        $persona = User::where('external_code', $fila['codigo'])->first();

        if (! $persona) {
            return [ImportRow::SKIPPED, 'Viene inactiva en la planilla y no está en el directorio: no se crea.'];
        }

        if ($protegida = $this->motivoDeProteccion($persona, $ejecutorId)) {
            return [ImportRow::SKIPPED, $protegida];
        }

        if (! $persona->active) {
            return [ImportRow::SKIPPED, 'Viene inactiva en la planilla y ya estaba inactiva.'];
        }

        $persona->deactivate(User::BAJA_INACTIVA_EN_ORIGEN, $import->id);

        return [ImportRow::DEACTIVATED, null];
    }

    /**
     * Da de baja a quien el archivo no nombró.
     *
     * Es la otra mitad de la sincronización, y la que puede hacer daño: si la
     * planilla era la nómina de una sucursal y no la de la empresa, esto
     * desactiva a todo el resto. Por eso el resumen previo lo cuenta y lo
     * muestra **antes** de confirmar, y por eso la pantalla avisa cuando el
     * archivo cubre una fracción chica del directorio.
     *
     * Se hace por lotes y con `chunkById`: una nómina real son miles de
     * personas, y ni un `whereNotIn` con 7.000 códigos ni traerlas todas a
     * memoria de una son formas de tratar a esa tabla.
     *
     * @param  array<string, true>  $presentes  códigos que el archivo nombró
     */
    private function bajarAusentes(array $presentes, Import $import, ?int $ejecutorId): int
    {
        /** @var array<int, User> $ausentes */
        $ausentes = [];

        User::query()
            ->deactivatableByPayroll()
            ->when($ejecutorId !== null, fn ($q) => $q->whereKeyNot($ejecutorId))
            ->select(['id', 'external_code', 'name', 'lastname'])
            ->chunkById(500, function ($lote) use (&$ausentes, $presentes) {
                foreach ($lote as $persona) {
                    if (! isset($presentes[$persona->external_code])) {
                        $ausentes[] = $persona;
                    }
                }
            });

        $ahora = now();

        foreach (array_chunk($ausentes, 200) as $lote) {
            User::whereIn('id', array_map(fn (User $p) => $p->id, $lote))->update([
                'active' => false,
                'deactivated_at' => $ahora,
                'deactivated_reason' => User::BAJA_AUSENTE,
                'deactivated_import_id' => $import->id,
            ]);

            // Queda registrada persona por persona. «Se dieron de baja 34» es
            // un número que nadie puede verificar ni revertir; con el detalle
            // se sabe exactamente a quiénes y se los puede volver a activar.
            //
            // `line` va en cero porque una baja por ausencia no sale de
            // ninguna línea: justamente sale de que no hay ninguna.
            ImportRow::insert(array_map(fn (User $p) => [
                'import_id' => $import->id,
                'line' => 0,
                'outcome' => ImportRow::DEACTIVATED,
                'payload' => json_encode([
                    'codigo' => $p->external_code,
                    'nombre' => $p->name,
                    'apellido' => $p->lastname,
                ], JSON_UNESCAPED_UNICODE),
                'error' => 'No vino en la planilla.',
                'created_at' => $ahora,
                'updated_at' => $ahora,
            ], $lote));
        }

        return count($ausentes);
    }

    /**
     * Por qué esta persona no se da de baja por planilla, si es que hay motivo.
     *
     * Las cuentas administrativas y la de quien está importando quedan fuera
     * de cualquier baja automática. No es delicadeza: una importación
     * distraída que desactive al único administrador deja el sistema sin nadie
     * que pueda entrar a arreglarlo, y la nómina de Recursos Humanos —donde
     * esas cuentas no figuran, o figuran mal— no es el lugar desde el que se
     * decide quién administra el portal.
     */
    private function motivoDeProteccion(User $persona, ?int $ejecutorId): ?string
    {
        if ($ejecutorId !== null && $persona->id === $ejecutorId) {
            return 'Es la cuenta con la que estás importando: una planilla no te da de baja a vos mismo.';
        }

        if ($persona->isAdministrative()) {
            return 'Es una cuenta administrativa y las bajas por planilla no la tocan. Si corresponde, dala de baja desde el directorio.';
        }

        return null;
    }

    /**
     * Enlaza cada persona con su supervisor, rechazando los ciclos.
     *
     * @param  array<string, string>  $pendientes  codigo => codigo_supervisor
     * @return array<int, string> avisos para el resumen
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
     * @return array<int, string> vacío si la fila está bien
     */
    public function problemas(array $filaNormalizada): array
    {
        $validador = Validator::make($filaNormalizada, $this->reglas(), $this->mensajes());

        $problemas = $validador->fails() ? $validador->errors()->all() : [];

        // Fuera del validador porque no es un formato sino un vocabulario: la
        // celda dice algo que no se sabe traducir a «sigue» o «se fue», y la
        // única respuesta honesta es rechazar la fila mostrando qué decía.
        // Adivinar acá es decidir en silencio si una persona queda dentro o
        // fuera del directorio.
        if ($this->interpretarActivo($filaNormalizada['activo'] ?? null) === self::ACTIVO_ILEGIBLE) {
            $problemas[] = 'No se entiende «'.$filaNormalizada['activo'].'» como estado. '
                .'Se esperaba algo del tipo 1/0, SI/NO o Activo/Baja.';
        }

        return $problemas;
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
            'activo' => ['nullable', 'string', 'max:255'],
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
