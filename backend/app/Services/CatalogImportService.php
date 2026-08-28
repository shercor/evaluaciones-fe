<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\BranchOffice;
use App\Models\Import;
use App\Models\ImportRow;
use App\Models\JobPosition;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

/**
 * Carga las sucursales o los cargos desde una planilla.
 *
 * Existe por un caso muy concreto: la nómina que trae **el código de la
 * sucursal y no su nombre**. Con un código suelto no hay con qué crear la
 * sucursal, así que hasta acá esas filas se rechazaban una por una y la única
 * salida era cargar 129 sucursales a mano. Con la planilla del catálogo
 * primero, esa misma nómina entra sin tocar nada.
 *
 * Las reglas son tres:
 *
 * - **El código manda.** Si la fila trae un código que ya está cargado, es esa
 *   fila y se le actualiza el nombre. Es lo que hace que reimportar el
 *   catálogo entero cada mes sea seguro.
 * - **El nombre alcanza cuando no hay código.** Sirve para el caso inverso:
 *   la nómina ya creó las sucursales por su nombre y esta planilla viene a
 *   ponerles el código que les faltaba.
 * - **Nada se renombra a algo que ya existe**, ni se le cambia el código a una
 *   fila que ya tiene otro. Las dos cosas son casi siempre un error de la
 *   planilla, y las dos dejarían el catálogo con duplicados que después hay
 *   que desenredar a mano.
 */
final class CatalogImportService
{
    /** @var array<string, class-string<Model>> */
    private const MODELOS = [
        'sucursales' => BranchOffice::class,
        'cargos' => JobPosition::class,
    ];

    /** Columnas que entiende la planilla. */
    public const COLUMNS = ['codigo', 'nombre'];

    /**
     * Las mismas columnas, explicadas para la pantalla de homologación.
     *
     * @var array<string, array{etiqueta: string, obligatoria: bool, ayuda: string}>
     */
    public const COLUMN_DEFINITIONS = [
        'codigo' => [
            'etiqueta' => 'Código',
            'obligatoria' => false,
            'ayuda' => 'El código con el que aparece en la planilla de personas. Es lo que une a las dos: si tu nómina trae «S-14» donde va la sucursal, este es el campo que hace que «S-14» signifique algo.',
        ],
        'nombre' => [
            'etiqueta' => 'Nombre',
            'obligatoria' => true,
            'ayuda' => 'Cómo se llama, en texto. Es lo que se ve en el directorio, en los filtros y en los informes.',
        ],
    ];

    /**
     * Nombres con los que suelen venir esas dos columnas.
     *
     * Se parte de los generales y se agregan los del tipo: en una planilla de
     * sucursales la columna del nombre se llama «local» o «sede», y en una de
     * cargos, «puesto» o «descripcion_cargo».
     *
     * @return array<string, array<int, string>>
     */
    public function sinonimos(string $tipo): array
    {
        $propios = $tipo === 'sucursales'
            ? [
                'codigo' => ['codigo_sucursal', 'cod_sucursal', 'id_sucursal', 'codigo_local', 'cod_local', 'id_local', 'codigo_sede', 'cod_sede', 'centro_costo', 'centro_de_costo'],
                'nombre' => ['sucursal', 'local', 'tienda', 'branch', 'oficina', 'sede', 'establecimiento', 'nombre_sucursal', 'nombre_local'],
            ]
            : [
                'codigo' => ['codigo_cargo', 'cod_cargo', 'id_cargo', 'codigo_puesto', 'cod_puesto', 'id_puesto'],
                'nombre' => ['cargo', 'puesto', 'position', 'job_title', 'funcion', 'descripcion_cargo', 'nombre_cargo', 'denominacion'],
            ];

        return [
            // Los propios van **primero**: en una planilla de sucursales con
            // «codigo» y «codigo_local», el segundo es el que importa.
            'codigo' => [...$propios['codigo'], 'codigo', 'code', 'cod', 'clave', 'id', 'identificador', 'codigo_interno'],
            'nombre' => [...$propios['nombre'], 'nombre', 'name', 'descripcion', 'description', 'glosa', 'detalle'],
        ];
    }

    /**
     * Importa de verdad, siguiendo el plan.
     *
     * Sin transacción por fila a propósito: cada una es una sola escritura
     * —crear o actualizar— y envolverla no la haría más atómica. La nómina sí
     * la necesita porque una fila suya toca varias tablas.
     *
     * @param  array<int, array<string, string>>  $filas
     */
    public function import(string $tipo, array $filas, Import $import): Import
    {
        $import->update(['rows_total' => count($filas), 'status' => Import::PENDING]);

        /** @var class-string<Model> $modelo */
        $modelo = self::MODELOS[$tipo];

        $creados = 0;
        $actualizados = 0;
        $fallidos = 0;

        // Lo que el plan llamó «nuevo:3» y ya existe de verdad. Una planilla
        // puede nombrar dos veces la misma sucursal: la primera fila la crea y
        // la segunda tiene que encontrarla, no crearla de nuevo.
        $reales = [];

        foreach ($this->planificar($tipo, $filas) as $paso) {
            if ($paso['accion'] === 'rechazar') {
                $this->registrar($import, $paso, ImportRow::FAILED, implode(' ', $paso['motivos']));
                $fallidos++;

                continue;
            }

            try {
                if ($paso['accion'] === 'crear') {
                    $fila = $modelo::query()->create([
                        'external_code' => $paso['fila']['codigo'] === '' ? null : $paso['fila']['codigo'],
                        'name' => $paso['fila']['nombre'],
                        'active' => true,
                    ]);

                    $reales[$paso['ref']] = $fila->id;

                    $this->registrar($import, $paso, ImportRow::CREATED);
                    $creados++;

                    continue;
                }

                $id = $paso['id'] ?? $reales[$paso['ref']] ?? null;

                if ($id !== null && $paso['cambios'] !== []) {
                    $modelo::query()->whereKey($id)->update($paso['cambios']);
                }

                $this->registrar($import, $paso, ImportRow::UPDATED);
                $actualizados++;
            } catch (\Throwable $e) {
                Log::error('[importación de catálogo] fila '.$paso['linea'].': '.$e->getMessage());
                $this->registrar($import, $paso, ImportRow::FAILED, 'Error al guardar: '.$e->getMessage());
                $fallidos++;
            }
        }

        $import->update([
            'status' => Import::DONE,
            'rows_created' => $creados,
            'rows_updated' => $actualizados,
            'rows_failed' => $fallidos,
        ]);

        return $import->refresh();
    }

    /**
     * Qué se haría con cada fila, sin escribir nada.
     *
     * Es una sola función y no dos porque el resumen previo y la importación
     * **tienen** que decir lo mismo. Si el ensayo tuviera su propia copia de
     * estas reglas, el día que se corrija una de las dos el resumen empezaría
     * a prometer algo distinto de lo que pasa, que es el peor error posible en
     * una pantalla cuyo único propósito es avisar antes de tocar nada.
     *
     * @param  array<int, array<string, string>>  $filas
     * @return array<int, array{
     *     linea: int,
     *     fila: array<string, string>,
     *     accion: string,
     *     motivos: array<int, string>,
     *     ref: string|null,
     *     id: int|null,
     *     cambios: array<string, string|null>
     * }>
     */
    public function planificar(string $tipo, array $filas): array
    {
        [$refs, $porCodigo, $porNombre] = $this->indice($tipo);

        $plan = [];
        $nuevas = 0;

        foreach ($filas as $indice => $cruda) {
            // +2: la primera línea es el encabezado y las planillas cuentan desde 1.
            $linea = $indice + 2;
            $fila = $this->normalizar($cruda);
            $motivos = $this->problemas($fila);

            if ($motivos !== []) {
                $plan[] = $this->paso($linea, $fila, 'rechazar', $motivos);

                continue;
            }

            $codigo = $fila['codigo'];
            $nombre = $fila['nombre'];
            $clave = CatalogResolver::clave($nombre);

            $refCodigo = $codigo === '' ? null : ($porCodigo[$codigo] ?? null);
            $refNombre = $porNombre[$clave] ?? null;

            // 1. El código manda: es esa fila, y el nombre que traiga la
            //    planilla es el bueno.
            if ($refCodigo !== null) {
                if ($refNombre !== null && $refNombre !== $refCodigo) {
                    $plan[] = $this->paso($linea, $fila, 'rechazar', [
                        "Ya hay otro registro llamado «{$nombre}»: dos no pueden llamarse igual. "
                        .'Cambiá el nombre en la planilla o unificalos en Directorio.',
                    ]);

                    continue;
                }

                $cambios = [];

                if ($refs[$refCodigo]['nombre'] !== $nombre) {
                    $cambios['name'] = $nombre;
                    unset($porNombre[CatalogResolver::clave($refs[$refCodigo]['nombre'])]);
                    $porNombre[$clave] = $refCodigo;
                    $refs[$refCodigo]['nombre'] = $nombre;
                }

                $plan[] = $this->paso($linea, $fila, 'actualizar', [], $refCodigo, $refs[$refCodigo]['id'], $cambios);

                continue;
            }

            // 2. Sin código conocido, el nombre alcanza para encontrarla. Es el
            //    caso de la sucursal que creó la nómina y que esta planilla
            //    viene a completar con su código.
            if ($refNombre !== null) {
                $actual = $refs[$refNombre]['codigo'];

                if ($codigo !== '' && $actual !== '' && $actual !== $codigo) {
                    $plan[] = $this->paso($linea, $fila, 'rechazar', [
                        "«{$nombre}» ya está cargada con el código «{$actual}» y la planilla trae "
                        ."«{$codigo}». Corregí el archivo, o cambiale el código en Directorio.",
                    ]);

                    continue;
                }

                $cambios = [];

                if ($codigo !== '' && $actual === '') {
                    $cambios['external_code'] = $codigo;
                    $refs[$refNombre]['codigo'] = $codigo;
                    $porCodigo[$codigo] = $refNombre;
                }

                $plan[] = $this->paso($linea, $fila, 'actualizar', [], $refNombre, $refs[$refNombre]['id'], $cambios);

                continue;
            }

            // 3. No está ni por código ni por nombre: nace.
            $ref = 'nuevo:'.(++$nuevas);
            $refs[$ref] = ['id' => null, 'codigo' => $codigo, 'nombre' => $nombre];
            $porNombre[$clave] = $ref;

            if ($codigo !== '') {
                $porCodigo[$codigo] = $ref;
            }

            $plan[] = $this->paso($linea, $fila, 'crear', [], $ref);
        }

        return $plan;
    }

    /**
     * Deja la fila como la va a guardar el sistema.
     *
     * @param  array<string, mixed>  $fila
     * @return array<string, string>
     */
    public function normalizar(array $fila): array
    {
        $limpia = [];

        foreach (self::COLUMNS as $columna) {
            $limpia[$columna] = trim((string) ($fila[$columna] ?? ''));
        }

        return $limpia;
    }

    /**
     * Qué le falta a una fila, en castellano.
     *
     * @param  array<string, string>  $fila
     * @return array<int, string> vacío si está bien
     */
    public function problemas(array $fila): array
    {
        $validador = Validator::make($fila, [
            'nombre' => ['required', 'string', 'max:255'],
            'codigo' => ['nullable', 'string', 'max:255'],
        ], [
            'nombre.required' => 'Falta el nombre, que es lo único imprescindible.',
            'nombre.max' => 'El nombre no puede pasar de 255 caracteres.',
            'codigo.max' => 'El código no puede pasar de 255 caracteres.',
        ]);

        return $validador->fails() ? $validador->errors()->all() : [];
    }

    /** Cómo se llama esto en singular y en plural, para los mensajes. */
    public static function palabras(string $tipo): array
    {
        return $tipo === 'sucursales'
            ? ['singular' => 'sucursal', 'plural' => 'sucursales']
            : ['singular' => 'cargo', 'plural' => 'cargos'];
    }

    // -----------------------------------------------------------------

    /**
     * El catálogo entero en memoria, indexado por código y por nombre.
     *
     * Igual que en `CatalogResolver` y por la misma razón: una consulta por
     * fila sobre una planilla de 129 sucursales son 258 consultas para leer
     * una tabla que entra entera en un `SELECT`.
     *
     * @return array{0: array<string, array{id: int|null, codigo: string, nombre: string}>, 1: array<string, string>, 2: array<string, string>}
     */
    private function indice(string $tipo): array
    {
        /** @var class-string<Model> $modelo */
        $modelo = self::MODELOS[$tipo] ?? abort(404, 'Catálogo desconocido.');

        $refs = [];
        $porCodigo = [];
        $porNombre = [];

        foreach ($modelo::query()->get(['id', 'external_code', 'name']) as $fila) {
            $ref = 'db:'.$fila->id;
            $codigo = (string) $fila->external_code;

            $refs[$ref] = ['id' => $fila->id, 'codigo' => $codigo, 'nombre' => (string) $fila->name];

            if ($codigo !== '') {
                $porCodigo[$codigo] = $ref;
            }

            $porNombre[CatalogResolver::clave((string) $fila->name)] = $ref;
        }

        return [$refs, $porCodigo, $porNombre];
    }

    /**
     * @param  array<string, string>  $fila
     * @param  array<int, string>  $motivos
     * @param  array<string, string|null>  $cambios
     * @return array<string, mixed>
     */
    private function paso(
        int $linea,
        array $fila,
        string $accion,
        array $motivos = [],
        ?string $ref = null,
        ?int $id = null,
        array $cambios = [],
    ): array {
        return compact('linea', 'fila', 'accion', 'motivos', 'ref', 'id', 'cambios');
    }

    /** @param  array<string, mixed>  $paso */
    private function registrar(Import $import, array $paso, string $resultado, ?string $error = null): void
    {
        ImportRow::create([
            'import_id' => $import->id,
            'line' => $paso['linea'],
            'outcome' => $resultado,
            'payload' => $paso['fila'],
            'error' => $error,
        ]);
    }
}
