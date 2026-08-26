<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\User;
use Illuminate\Validation\ValidationException;

/**
 * Homologa una planilla ajena con las columnas del sistema.
 *
 * El camino normal de importación exige una planilla con los encabezados que
 * el sistema espera. Este es el otro camino: la planilla llega como la tenía
 * Recursos Humanos —«RUT», «Nombre Completo», «Local», «Jefe Directo»— y es
 * una persona la que dice qué columna es cuál.
 *
 * Todo lo que se homologa es **texto**. Ninguna columna del directorio guarda
 * números ni fechas, así que traducir es renombrar claves, no convertir tipos:
 * el lector ya dejó cada celda como cadena. Eso es lo que hace que esto sea
 * seguro de hacer sobre un archivo desconocido.
 *
 * Lo que este servicio **no** hace es importar. Su salida es exactamente la
 * misma forma que espera `DirectoryImportService`, así que el motor de
 * importación —con su fila a fila, su idempotencia por código y su jerarquía
 * resuelta al final— es el mismo para los dos caminos. Un segundo importador
 * en paralelo sería la manera más rápida de que los dos se comporten distinto.
 */
final class ImportMapping
{
    /** Cuántas filas de ejemplo se muestran en el resumen. */
    private const FILAS_DE_MUESTRA = 8;

    /** Cuántos problemas se detallan antes de resumirlos en un número. */
    private const PROBLEMAS_DETALLADOS = 50;

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
        'rol' => ['rol', 'role', 'perfil', 'tipo_usuario', 'tipo_de_usuario', 'permiso'],
    ];

    public function __construct(private readonly DirectoryImportService $importer) {}

    /**
     * Las columnas del sistema, para que la pantalla sepa qué pedir.
     *
     * @return array<int, array{clave: string, etiqueta: string, obligatoria: bool, ayuda: string}>
     */
    public function columnasDelSistema(): array
    {
        $columnas = [];

        foreach (DirectoryImportService::COLUMN_DEFINITIONS as $clave => $definicion) {
            $columnas[] = ['clave' => $clave] + $definicion;
        }

        return $columnas;
    }

    /**
     * Propone una homologación mirando los nombres de los encabezados.
     *
     * Primero busca coincidencias exactas, y recién después parciales: si una
     * planilla tiene «supervisor» y «codigo_supervisor», la exacta gana y no
     * se las lleva la primera que pase. Un encabezado ya usado no se ofrece
     * dos veces.
     *
     * @param  array<int, array{clave: string, etiqueta: string}>  $encabezados
     * @return array<string, string|null>  columna del sistema => columna del archivo
     */
    public function sugerir(array $encabezados): array
    {
        $disponibles = array_column($encabezados, 'clave');
        $mapa = array_fill_keys(array_keys(DirectoryImportService::COLUMN_DEFINITIONS), null);

        foreach ([true, false] as $exacta) {
            foreach ($mapa as $columna => $elegida) {
                if ($elegida !== null) {
                    continue;
                }

                // Los sinónimos se recorren **en su orden**, y el orden es la
                // prioridad: para «código del supervisor» primero se busca
                // `codigo_supervisor` y recién después `supervisor`. Al revés
                // —recorriendo las columnas del archivo por fuera— gana la que
                // aparezca antes en la planilla, y una que tenga las dos deja
                // el código del jefe sin conectar y la jerarquía sin armar.
                foreach (self::SINONIMOS[$columna] ?? [] as $sinonimo) {
                    foreach ($disponibles as $indice => $clave) {
                        if ($exacta ? $clave === $sinonimo : str_contains($clave, $sinonimo)) {
                            if (! $exacta && $this->esNombreExactoDeOtro($clave, $columna)) {
                                continue;
                            }

                            $mapa[$columna] = $clave;
                            unset($disponibles[$indice]);

                            // Sale del recorrido de encabezados y del de
                            // sinónimos, y sigue con el campo siguiente.
                            continue 3;
                        }
                    }
                }
            }
        }

        return $mapa;
    }

    /**
     * ¿Este encabezado es, palabra por palabra, el nombre de otro campo?
     *
     * Frena a la segunda vuelta, la de coincidencias parciales. Sin esto, en
     * una planilla que trae «codigo_supervisor» pero no «codigo», el campo
     * «código interno» —que se resuelve antes— se lleva la columna del jefe
     * porque la contiene, y quedan las dos mal.
     */
    private function esNombreExactoDeOtro(string $clave, string $columnaActual): bool
    {
        foreach (self::SINONIMOS as $columna => $sinonimos) {
            if ($columna !== $columnaActual && in_array($clave, $sinonimos, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Revisa la homologación elegida antes de dejarla pasar.
     *
     * Tres cosas se rechazan acá, y las tres son errores de verdad y no
     * escrúpulos: que falte una columna obligatoria, que se apunte a un
     * encabezado que el archivo no tiene, y que dos campos del sistema se
     * conecten al mismo. Este último es siempre un descuido —nadie quiere el
     * mismo dato en «nombre» y en «apellido»— y si pasara sin aviso se
     * descubriría cuando el directorio ya tiene 800 personas mal cargadas.
     *
     * @param  array<string, string|null>  $mapa
     * @param  array<int, array{clave: string, etiqueta: string}>  $encabezados
     * @return array<string, string>  la homologación limpia, sin las vacías
     *
     * @throws ValidationException
     */
    public function validar(array $mapa, array $encabezados): array
    {
        $delArchivo = array_column($encabezados, 'etiqueta', 'clave');
        $limpio = [];
        $errores = [];

        foreach ($mapa as $columna => $origen) {
            if (! isset(DirectoryImportService::COLUMN_DEFINITIONS[$columna])) {
                $errores['mapping'][] = "«{$columna}» no es una columna del sistema.";

                continue;
            }

            if (blank($origen)) {
                continue;
            }

            if (! isset($delArchivo[$origen])) {
                $etiqueta = DirectoryImportService::COLUMN_DEFINITIONS[$columna]['etiqueta'];
                $errores['mapping'][] = "La columna «{$etiqueta}» apunta a un encabezado que ya no está en el archivo.";

                continue;
            }

            $limpio[$columna] = $origen;
        }

        foreach (DirectoryImportService::COLUMN_DEFINITIONS as $columna => $definicion) {
            if ($definicion['obligatoria'] && ! isset($limpio[$columna])) {
                $errores['mapping'][] = "Falta conectar «{$definicion['etiqueta']}», que es obligatoria.";
            }
        }

        foreach ($this->repetidos($limpio) as $origen => $columnas) {
            $nombres = array_map(
                fn (string $c) => '«'.DirectoryImportService::COLUMN_DEFINITIONS[$c]['etiqueta'].'»',
                $columnas,
            );

            $errores['mapping'][] = 'La columna «'.$delArchivo[$origen].'» del archivo está conectada a '
                .implode(' y ', $nombres).'. Cada columna del archivo puede alimentar solo un campo.';
        }

        if ($errores !== []) {
            throw ValidationException::withMessages($errores);
        }

        return $limpio;
    }

    /**
     * @param  array<string, string>  $mapa
     * @return array<string, array<int, string>>  columna del archivo => columnas del sistema
     */
    private function repetidos(array $mapa): array
    {
        $porOrigen = [];

        foreach ($mapa as $columna => $origen) {
            $porOrigen[$origen][] = $columna;
        }

        return array_filter($porOrigen, fn (array $columnas) => count($columnas) > 1);
    }

    /**
     * Traduce las filas del archivo a las columnas del sistema.
     *
     * Lo que no se homologó viaja vacío, no ausente: el importador espera
     * todas las claves y una columna que falte sería un aviso distinto —«falta
     * el dato»— del que corresponde, que es «esta planilla no lo trae».
     *
     * @param  array<int, array<string, string>>  $filas
     * @param  array<string, string>  $mapa
     * @return array<int, array<string, string>>
     */
    public function aplicar(array $filas, array $mapa): array
    {
        $traducidas = [];

        foreach ($filas as $fila) {
            $nueva = [];

            foreach (DirectoryImportService::COLUMNS as $columna) {
                $origen = $mapa[$columna] ?? null;
                $nueva[$columna] = $origen === null ? '' : (string) ($fila[$origen] ?? '');
            }

            $traducidas[] = $nueva;
        }

        return $traducidas;
    }

    /**
     * Ensaya la importación sin tocar nada.
     *
     * Es el resumen que se muestra antes de confirmar, y tiene que responder
     * dos preguntas distintas: **¿conecté bien las columnas?** —para eso están
     * las filas de muestra, con los datos de verdad del archivo puestos bajo
     * el nombre del campo del sistema— y **¿qué va a pasar?**, para lo que
     * hacen falta los números: cuántas se crean, cuántas se actualizan y
     * cuáles se van a rechazar, con su motivo y su número de línea.
     *
     * Las filas con problemas se informan pero **no detienen nada**: la
     * importación es fila a fila y las buenas entran igual. Acá solo se
     * adelanta la cuenta para que nadie se entere después.
     *
     * @param  array<int, array<string, string>>  $filasMapeadas
     * @return array<string, mixed>
     */
    public function ensayar(array $filasMapeadas): array
    {
        $muestra = [];
        $problemas = [];
        $codigos = [];
        $repetidosEnArchivo = [];
        $sinCorreo = 0;
        $conProblemas = 0;

        // El mismo resolvedor que usa la importación, pero sin escribir. Así
        // el resumen no *parece* lo que va a pasar: lo calcula con el mismo
        // código, incluidos los rechazos por un código de sucursal que no
        // está cargado.
        $catalogos = new CatalogResolver(simular: true);

        foreach ($filasMapeadas as $indice => $fila) {
            // +2: la primera línea es el encabezado y las planillas cuentan desde 1.
            $linea = $indice + 2;
            $normalizada = $this->importer->normalizar($fila);
            $problemasFila = $this->importer->problemas($normalizada);

            // Los catálogos se miran solo si la fila ya pasó lo básico: no
            // tiene sentido avisar de la sucursal de una fila sin nombre.
            if ($problemasFila === []) {
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

            if (blank($normalizada['correo'])) {
                $sinCorreo++;
            }

            // Un mismo código dos veces en el archivo no es un error —la
            // segunda fila actualiza a la primera— pero casi siempre es un
            // descuido, y en silencio se pierde una de las dos.
            $codigo = $normalizada['codigo'];
            if (isset($codigos[$codigo])) {
                $repetidosEnArchivo[$codigo] = ($repetidosEnArchivo[$codigo] ?? 1) + 1;
            }
            $codigos[$codigo] = true;

            if (count($muestra) < self::FILAS_DE_MUESTRA) {
                $muestra[] = ['linea' => $linea] + $normalizada;
            }
        }

        $yaExisten = $this->cuantosYaExisten(array_keys($codigos));

        return [
            'filas_totales' => count($filasMapeadas),
            'filas_validas' => count($filasMapeadas) - $conProblemas,
            'filas_con_problemas' => $conProblemas,
            'se_crearan' => count($codigos) - $yaExisten,
            'se_actualizaran' => $yaExisten,
            'sin_correo' => $sinCorreo,
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
        ];
    }

    /**
     * Cuántos de esos códigos ya están en el directorio.
     *
     * Por lotes: una nómina completa son miles de códigos y meterlos todos en
     * un solo `IN (...)` es una consulta que el motor no quiere recibir.
     *
     * @param  array<int, string>  $codigos
     */
    private function cuantosYaExisten(array $codigos): int
    {
        if ($codigos === []) {
            return 0;
        }

        $total = 0;

        foreach (array_chunk($codigos, 500) as $lote) {
            $total += User::whereIn('external_code', $lote)->count();
        }

        return $total;
    }
}
