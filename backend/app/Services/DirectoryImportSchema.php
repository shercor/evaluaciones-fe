<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Import;
use App\Models\User;

/**
 * La nómina: personas, con su sucursal, su cargo y su jefatura.
 *
 * Es el destino original de la homologación y el único que existió hasta que
 * se pudieron cargar también los catálogos. Todo lo que sabe de personas vive
 * en `DirectoryImportService`; acá está lo que necesita la pantalla de
 * homologación para trabajar sobre eso.
 */
final class DirectoryImportSchema implements ImportSchema
{
    /** Cuántas filas de ejemplo se muestran en el resumen. */
    private const FILAS_DE_MUESTRA = 8;

    /** Cuántos problemas se detallan antes de resumirlos en un número. */
    private const PROBLEMAS_DETALLADOS = 50;

    /**
     * Cuántas de las que se darían de baja se nombran en el resumen.
     *
     * Se muestran nombres y no solo un número porque «se van a desactivar 34
     * personas» no se puede verificar, y ver tres apellidos conocidos —o tres
     * que no deberían estar ahí— es lo que hace que alguien frene.
     */
    private const AUSENTES_DE_MUESTRA = 12;

    /**
     * Nombres con los que cada columna del sistema suele aparecer.
     *
     * No es adivinación seria: es un atajo para que quien homologa una
     * planilla de 14 columnas no empiece de cero. Todo lo que se sugiere queda
     * a la vista y se puede cambiar, y nada se importa sin que la persona
     * confirme el resumen.
     *
     * @var array<string, array<int, string>>
     */
    private const SINONIMOS = [
        'codigo' => ['codigo', 'code', 'rut', 'run', 'dni', 'ficha', 'n_ficha', 'numero_ficha', 'legajo', 'matricula', 'id', 'identificador', 'codigo_interno', 'codigo_empleado', 'cod_empleado', 'documento', 'cedula'],
        'nombre' => ['nombre', 'nombres', 'name', 'first_name', 'primer_nombre', 'nombre_completo', 'nombre_empleado', 'nombre_trabajador'],
        'apellido' => ['apellido', 'apellidos', 'last_name', 'surname', 'apellido_paterno', 'ape_paterno', 'apellidos_completos'],
        'correo' => ['correo', 'email', 'e_mail', 'mail', 'correo_electronico', 'correo_corporativo', 'email_corporativo'],
        'cargo' => ['cargo', 'puesto', 'position', 'job_title', 'titulo', 'funcion', 'rol_cargo', 'descripcion_cargo'],
        'cargo_codigo' => ['codigo_cargo', 'cod_cargo', 'id_cargo', 'codigo_puesto', 'cod_puesto'],
        'sucursal' => ['sucursal', 'local', 'tienda', 'branch', 'oficina', 'sede', 'establecimiento', 'centro_de_costo', 'centro_costo', 'ubicacion'],
        'sucursal_codigo' => ['codigo_sucursal', 'cod_sucursal', 'id_sucursal', 'codigo_local', 'cod_local', 'id_local', 'codigo_sede', 'cod_sede'],
        'codigo_supervisor' => ['codigo_supervisor', 'cod_supervisor', 'rut_supervisor', 'id_supervisor', 'supervisor', 'jefe', 'jefe_directo', 'jefatura', 'codigo_jefe', 'rut_jefe'],
        'activo' => ['activo', 'activa', 'active', 'estado', 'estado_empleado', 'estado_trabajador', 'vigente', 'vigencia', 'habilitado', 'situacion', 'situacion_laboral', 'es_activo', 'is_active', 'enabled', 'deleted', 'eliminado', 'baja'],
        'rol' => ['rol', 'role', 'perfil', 'tipo_usuario', 'tipo_de_usuario', 'permiso'],
    ];

    public function __construct(private readonly DirectoryImportService $importer) {}

    public function definiciones(): array
    {
        return DirectoryImportService::COLUMN_DEFINITIONS;
    }

    public function sinonimos(): array
    {
        return self::SINONIMOS;
    }

    public function importar(array $filasMapeadas, Import $import, array $opciones = []): Import
    {
        return $this->importer->import($filasMapeadas, $import, $opciones);
    }

    public function mensajeFinal(Import $import): string
    {
        $partes = [];

        if ($import->rows_created > 0) {
            $partes[] = "{$import->rows_created} creadas";
        }
        if ($import->rows_updated > 0) {
            $partes[] = "{$import->rows_updated} actualizadas";
        }
        if ($import->rows_reactivated > 0) {
            $partes[] = "{$import->rows_reactivated} reincorporadas";
        }
        if ($import->rows_deactivated > 0) {
            $partes[] = "{$import->rows_deactivated} dadas de baja";
        }
        if ($import->rows_failed > 0) {
            $partes[] = "{$import->rows_failed} rechazadas";
        }
        if ($import->rows_skipped > 0) {
            $partes[] = "{$import->rows_skipped} omitidas";
        }

        return $partes === []
            ? 'La planilla no tenía filas para procesar.'
            : 'Importación terminada: '.implode(', ', $partes).'.';
    }

    /**
     * Ensaya la importación sin tocar nada.
     *
     * Es el resumen que se muestra antes de confirmar, y tiene que responder
     * tres preguntas distintas: **¿conecté bien las columnas?** —para eso
     * están las filas de muestra, con los datos de verdad del archivo puestos
     * bajo el nombre del campo del sistema—, **¿qué va a entrar?**, para lo que
     * hacen falta los números de creadas, actualizadas y rechazadas con su
     * motivo, y desde que el directorio se sincroniza, **¿qué va a salir?**.
     *
     * La tercera es la que puede hacer daño, así que es la que más se explica:
     * quién se da de baja porque la planilla lo trae inactivo, quién porque no
     * viene, y —lo más importante— qué fracción del directorio cubre este
     * archivo. Una nómina parcial subida con la sincronización puesta desactiva
     * a todo el resto de la empresa, y el único momento en que eso se puede
     * ver venir es este.
     *
     * Las filas con problemas se informan pero **no detienen nada**: la
     * importación es fila a fila y las buenas entran igual. Acá solo se
     * adelanta la cuenta para que nadie se entere después.
     *
     * @param  array<string, mixed>  $opciones  `sincronizar_bajas`, `ejecutor_id`, `activo_conectado`
     */
    public function ensayar(array $filasMapeadas, array $opciones = []): array
    {
        $ejecutorId = isset($opciones['ejecutor_id']) ? (int) $opciones['ejecutor_id'] : null;
        $activoConectado = (bool) ($opciones['activo_conectado'] ?? false);

        $muestra = [];
        $problemas = [];
        $repetidosEnArchivo = [];
        $sinCorreo = 0;
        $sinSucursal = 0;
        $sinCargo = 0;
        $conProblemas = 0;
        $activoVacio = 0;

        /**
         * Estado final de cada código del archivo, y gana el último.
         *
         * Es la misma regla que aplica la importación —fila a fila, de arriba
         * abajo— y por eso hay que contar por código y no por fila: si la 5
         * trae a alguien activo y la 90 lo trae de baja, se crea y después se
         * da de baja, así que lo que corresponde contar es la baja.
         *
         * @var array<string, string>
         */
        $estados = [];

        /**
         * Todo código que el archivo nombre, incluso en una fila rechazada.
         * Es lo que decide quién está ausente, igual que en la importación.
         *
         * @var array<string, true>
         */
        $presentes = [];

        /** @var array<string, int> Valores de «activo» que no se entienden. */
        $ilegibles = [];

        // El mismo resolvedor que usa la importación, pero sin escribir. Así
        // el resumen no *parece* lo que va a pasar: lo calcula con el mismo
        // código, incluidos los rechazos por un código de sucursal que no
        // está cargado.
        $catalogos = new CatalogResolver(simular: true);

        // Se normaliza una sola vez y por adelantado: hace falta la lista
        // completa de correos antes de empezar, para poder preguntar de una
        // sola vez cuáles ya son de otra persona.
        $normalizadas = array_map($this->importer->normalizar(...), $filasMapeadas);
        $duenos = $this->duenosDelCorreo($normalizadas);

        /** @var array<string, string> correo => código que lo usó primero en el archivo */
        $correosDelArchivo = [];

        foreach ($normalizadas as $indice => $normalizada) {
            // +2: la primera línea es el encabezado y las planillas cuentan desde 1.
            $linea = $indice + 2;

            if (! blank($normalizada['codigo'])) {
                $presentes[$normalizada['codigo']] = true;
            }

            $estado = $this->importer->interpretarActivo($normalizada['activo']);

            if ($estado === DirectoryImportService::ACTIVO_ILEGIBLE) {
                $valor = trim((string) $normalizada['activo']);
                $ilegibles[$valor] = ($ilegibles[$valor] ?? 0) + 1;
            }

            $problemasFila = $this->importer->problemas($normalizada);

            // El correo repetido rechaza la fila igual que cualquier otro
            // problema, así que se cuenta acá y no como un aviso aparte: el
            // resumen tiene que decir el mismo número de rechazos que el
            // resultado.
            if ($problemasFila === [] && ! blank($normalizada['correo'])) {
                $choque = $this->choqueDeCorreo($normalizada, $duenos, $correosDelArchivo);

                if ($choque !== null) {
                    $problemasFila[] = $choque;
                } else {
                    $correosDelArchivo[$normalizada['correo']] = $normalizada['codigo'];
                }
            }

            // Los catálogos se miran solo si la fila ya pasó lo básico y si
            // además va a entrar: la sucursal de alguien que viene de baja no
            // se crea, así que avisar de ella sería avisar de algo que no va
            // a pasar.
            if ($problemasFila === [] && $estado !== DirectoryImportService::ACTIVO_NO) {
                foreach (['sucursal', 'cargo'] as $catalogo) {
                    [, $error] = $catalogos->resolver(
                        $catalogo,
                        (string) $normalizada[$catalogo.'_codigo'],
                        (string) $normalizada[$catalogo],
                    );

                    if ($error !== null) {
                        $problemasFila[] = $error;
                    }
                }
            }

            if ($problemasFila !== []) {
                $conProblemas++;

                if (count($problemas) < self::PROBLEMAS_DETALLADOS) {
                    $problemas[] = [
                        'linea' => $linea,
                        'codigo' => $normalizada['codigo'],
                        'nombre' => trim($normalizada['nombre'].' '.$normalizada['apellido']),
                        'motivos' => $problemasFila,
                    ];
                }

                continue;
            }

            // Un mismo código dos veces en el archivo no es un error —la
            // segunda fila actualiza a la primera— pero casi siempre es un
            // descuido, y en silencio se pierde una de las dos.
            $codigo = $normalizada['codigo'];
            if (isset($estados[$codigo])) {
                $repetidosEnArchivo[$codigo] = ($repetidosEnArchivo[$codigo] ?? 1) + 1;
            }
            $estados[$codigo] = $estado;

            if ($estado === DirectoryImportService::ACTIVO_NO) {
                // Lo que no entra no se cuenta como si entrara: una fila de
                // baja no tiene correo que avisar ni sucursal que crear.
                continue;
            }

            if ($activoConectado && $estado === DirectoryImportService::ACTIVO_VACIO) {
                $activoVacio++;
            }

            if (blank($normalizada['correo'])) {
                $sinCorreo++;
            }

            // Entrar sin sucursal o sin cargo es válido —la persona queda con
            // el campo vacío y se le puede poner después— pero casi siempre es
            // una columna que se olvidaron de conectar, y en silencio se
            // descubre recién al armar la primera evaluación.
            if (blank($normalizada['sucursal']) && blank($normalizada['sucursal_codigo'])) {
                $sinSucursal++;
            }

            if (blank($normalizada['cargo']) && blank($normalizada['cargo_codigo'])) {
                $sinCargo++;
            }

            if (count($muestra) < self::FILAS_DE_MUESTRA) {
                $muestra[] = ['linea' => $linea] + $normalizada;
            }
        }

        $enDirectorio = $this->estadoDe(array_keys($estados));
        $entran = $this->contarLasQueEntran($estados, $enDirectorio);
        $bajasPorOrigen = $this->contarBajasPorOrigen($estados, $enDirectorio);
        $ausentes = $this->ausentes($presentes, $ejecutorId);

        return [
            'filas_totales' => count($filasMapeadas),
            'filas_validas' => count($filasMapeadas) - $conProblemas,
            'filas_con_problemas' => $conProblemas,
            'se_crearan' => $entran['crear'],
            'se_actualizaran' => $entran['actualizar'],
            'se_reactivaran' => $entran['reactivar'],
            'sin_correo' => $sinCorreo,
            'sin_sucursal' => $sinSucursal,
            'sin_cargo' => $sinCargo,
            'codigos_repetidos' => $repetidosEnArchivo,
            'sucursales_nuevas' => $catalogos->porCrear('sucursal'),
            'cargos_nuevos' => $catalogos->porCrear('cargo'),
            // Códigos que la planilla usa y que no están cargados. A
            // diferencia de los anteriores, estos **bloquean** su fila.
            'sucursales_faltantes' => $catalogos->faltantes('sucursal'),
            'cargos_faltantes' => $catalogos->faltantes('cargo'),
            'muestra' => $muestra,
            'problemas' => $problemas,
            'problemas_omitidos' => max(0, $conProblemas - count($problemas)),

            // -- Lo que sale del directorio --------------------------------
            'columna_activo_conectada' => $activoConectado,
            'activo_vacio' => $activoVacio,
            'activo_ilegible' => $ilegibles,
            'bajas_por_origen' => $bajasPorOrigen['bajas'],
            'omitidas_por_inactivas' => $bajasPorOrigen['omitidas'],
            'bajas_por_ausencia' => $ausentes['total'],
            'muestra_ausentes' => $ausentes['muestra'],
            // Qué parte del directorio cubre este archivo. Es el dato con el
            // que se decide si la sincronización de bajas corresponde o si la
            // planilla era parcial.
            'cobertura' => [
                'nombradas_en_archivo' => count($presentes),
                'sincronizables' => $ausentes['sincronizables'],
            ],
        ];
    }

    // -----------------------------------------------------------------

    /**
     * Cuántas se crean, cuántas se actualizan y cuántas vuelven de una baja.
     *
     * @param  array<string, string>  $estados
     * @param  array<string, array{activa: bool, administrativa: bool}>  $enDirectorio
     * @return array{crear: int, actualizar: int, reactivar: int}
     */
    private function contarLasQueEntran(array $estados, array $enDirectorio): array
    {
        $crear = 0;
        $actualizar = 0;
        $reactivar = 0;

        foreach ($estados as $codigo => $estado) {
            if ($estado === DirectoryImportService::ACTIVO_NO) {
                continue;
            }

            $existente = $enDirectorio[$codigo] ?? null;

            if ($existente === null) {
                $crear++;

                continue;
            }

            $actualizar++;

            if (! $existente['activa']) {
                $reactivar++;
            }
        }

        return ['crear' => $crear, 'actualizar' => $actualizar, 'reactivar' => $reactivar];
    }

    /**
     * Las que la planilla trae marcadas como inactivas.
     *
     * Solo son una baja de verdad las que están en el directorio y activas.
     * Una nómina completa arrastra a los egresados de años: vienen de baja,
     * nunca estuvieron acá y no hay nada que hacer con ellas.
     *
     * @param  array<string, string>  $estados
     * @param  array<string, array{activa: bool, administrativa: bool}>  $enDirectorio
     * @return array{bajas: int, omitidas: int}
     */
    private function contarBajasPorOrigen(array $estados, array $enDirectorio): array
    {
        $bajas = 0;
        $omitidas = 0;

        foreach ($estados as $codigo => $estado) {
            if ($estado !== DirectoryImportService::ACTIVO_NO) {
                continue;
            }

            $existente = $enDirectorio[$codigo] ?? null;

            if ($existente === null || ! $existente['activa'] || $existente['administrativa']) {
                $omitidas++;

                continue;
            }

            $bajas++;
        }

        return ['bajas' => $bajas, 'omitidas' => $omitidas];
    }

    /**
     * Quiénes quedarían fuera si se sincronizan las bajas.
     *
     * Se calcula **siempre**, esté o no marcada la casilla, porque el número
     * es justamente lo que hace falta para decidir si marcarla. Mostrarlo solo
     * cuando ya está activada sería enseñar la consecuencia después de haber
     * elegido.
     *
     * @param  array<string, true>  $presentes
     * @return array{total: int, muestra: array<int, array{codigo: string, nombre: string}>, sincronizables: int}
     */
    private function ausentes(array $presentes, ?int $ejecutorId): array
    {
        $total = 0;
        $muestra = [];
        $sincronizables = 0;

        User::query()
            ->deactivatableByPayroll()
            ->when($ejecutorId !== null, fn ($q) => $q->whereKeyNot($ejecutorId))
            ->select(['id', 'external_code', 'name', 'lastname'])
            ->chunkById(500, function ($lote) use (&$total, &$muestra, &$sincronizables, $presentes) {
                foreach ($lote as $persona) {
                    $sincronizables++;

                    if (isset($presentes[$persona->external_code])) {
                        continue;
                    }

                    $total++;

                    if (count($muestra) < self::AUSENTES_DE_MUESTRA) {
                        $muestra[] = [
                            'codigo' => (string) $persona->external_code,
                            'nombre' => $persona->fullName(),
                        ];
                    }
                }
            });

        return ['total' => $total, 'muestra' => $muestra, 'sincronizables' => $sincronizables];
    }

    /**
     * De quién es cada uno de los correos del archivo, si ya es de alguien.
     *
     * Una sola consulta por lote en vez de una por fila: en una nómina de
     * 4.000 personas la diferencia es entre una consulta y cuatro mil.
     *
     * @param  array<int, array<string, string>>  $normalizadas
     * @return array<string, array{codigo: string, nombre: string}>
     */
    private function duenosDelCorreo(array $normalizadas): array
    {
        $correos = array_values(array_unique(array_filter(
            array_column($normalizadas, 'correo'),
            fn (string $correo) => $correo !== '',
        )));

        if ($correos === []) {
            return [];
        }

        $duenos = [];

        foreach (array_chunk($correos, 500) as $lote) {
            User::whereIn('email', $lote)
                ->get(['email', 'external_code', 'name', 'lastname'])
                ->each(function (User $persona) use (&$duenos) {
                    $duenos[(string) $persona->email] = [
                        'codigo' => (string) ($persona->external_code ?? ''),
                        'nombre' => $persona->fullName(),
                    ];
                });
        }

        return $duenos;
    }

    /**
     * ¿Este correo es de otra persona? Y si lo es, cómo se lo explica.
     *
     * Dos choques distintos con el mismo remedio: contra el directorio —el
     * correo ya es de alguien con otro código— y contra el propio archivo, que
     * trae dos filas con códigos distintos y la misma casilla. El segundo no
     * llega a la base porque la primera fila entra y la segunda choca contra
     * ella, así que si no se avisa acá se descubre en el resultado.
     *
     * @param  array<string, array{codigo: string, nombre: string}>  $duenos
     * @param  array<string, string>  $correosDelArchivo
     */
    private function choqueDeCorreo(array $fila, array $duenos, array $correosDelArchivo): ?string
    {
        $correo = $fila['correo'];
        $codigo = $fila['codigo'];

        $dueno = $duenos[$correo] ?? null;

        if ($dueno !== null && $dueno['codigo'] !== $codigo) {
            return "El correo «{$correo}» ya es de {$dueno['nombre']} "
                .'(código '.($dueno['codigo'] !== '' ? $dueno['codigo'] : 'sin código').'). '
                .'Dos personas no pueden compartir la casilla.';
        }

        $anterior = $correosDelArchivo[$correo] ?? null;

        if ($anterior !== null && $anterior !== $codigo) {
            return "El correo «{$correo}» ya lo usa el código «{$anterior}» en este mismo archivo. "
                .'Dos personas no pueden compartir la casilla.';
        }

        return null;
    }

    /**
     * Cómo está hoy cada uno de esos códigos en el directorio.
     *
     * Por lotes: una nómina completa son miles de códigos y meterlos todos en
     * un solo `IN (...)` es una consulta que el motor no quiere recibir.
     *
     * @param  array<int, string>  $codigos
     * @return array<string, array{activa: bool, administrativa: bool}>
     */
    private function estadoDe(array $codigos): array
    {
        if ($codigos === []) {
            return [];
        }

        $estado = [];

        foreach (array_chunk($codigos, 500) as $lote) {
            User::whereIn('external_code', $lote)
                ->get(['external_code', 'active', 'role'])
                ->each(function (User $persona) use (&$estado) {
                    $estado[(string) $persona->external_code] = [
                        'activa' => (bool) $persona->active,
                        'administrativa' => $persona->isAdministrative(),
                    ];
                });
        }

        return $estado;
    }
}
