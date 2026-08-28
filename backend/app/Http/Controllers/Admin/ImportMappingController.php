<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\ImportResource;
use App\Models\Import;
use App\Models\ImportDraft;
use App\Services\ImportHousekeeping;
use App\Services\ImportMapping;
use App\Services\ImportSchemas;
use App\Services\SpreadsheetReader;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Importar una planilla que **no** viene con el formato del sistema.
 *
 * Sirve para los tres destinos —la nómina, las sucursales y los cargos— con el
 * mismo camino y el mismo código. Lo único que cambia entre ellos es el
 * `ImportSchema`, que se elige al subir el archivo y queda anotado en el
 * borrador.
 *
 * Son tres pasos, y están separados en tres peticiones porque entre medio
 * trabaja una persona: se sube el archivo y se leen sus encabezados, se
 * homologa columna por columna, se revisa el resumen y recién ahí se importa.
 *
 * El archivo queda guardado en el disco privado mientras dura eso. La
 * alternativa —pedirlo de nuevo en cada paso— haría subir 5 MB tres veces y,
 * peor, permitiría que el resumen que alguien aprobó y el archivo que termina
 * importándose no sean el mismo.
 */
class ImportMappingController extends Controller
{
    /** Los borradores sin confirmar se tiran a las 24 horas. */
    private const HORAS_DE_VIDA = 24;

    private const CARPETA = 'importaciones/borradores';

    public function __construct(
        private readonly SpreadsheetReader $reader,
        private readonly ImportSchemas $esquemas,
        private readonly ImportHousekeeping $limpieza,
    ) {}

    /**
     * Paso 1: subir el archivo y mirar qué columnas trae.
     */
    public function store(Request $request): JsonResponse
    {
        $request->validate([
            'file' => ['required', 'file', 'mimes:csv,txt,xlsx,xls', 'max:10240'],
            'destino' => ['sometimes', Rule::in(ImportSchemas::DESTINOS)],
        ], [
            'file.required' => 'Elegí una planilla.',
            'file.mimes' => 'La planilla tiene que ser CSV o Excel.',
            'file.max' => 'La planilla no puede pesar más de 10 MB.',
        ]);

        $destino = $request->string('destino', ImportSchemas::NOMINA)->toString();
        $mapper = $this->mapperDe($destino);

        // Aprovecha el paso para tirar lo que otros abandonaron. La misma
        // limpieza corre por reloj en `importaciones:limpiar`; acá sirve para
        // que el disco no espere a la hora en punto.
        $this->limpieza->borrarBorradores(self::HORAS_DE_VIDA);

        $archivo = $request->file('file');
        $extension = mb_strtolower($archivo->getClientOriginalExtension());

        // Se guarda antes de leer: si el archivo resulta ilegible hay que
        // borrarlo, pero leerlo desde el temporal de PHP y guardarlo después
        // no sirve —el temporal se esfuma al terminar la petición—.
        // El disco va escrito y no por defecto: si alguna vez `FILESYSTEM_DISK`
        // apunta a S3, guardar allá y leer de acá dejaría de encontrarse.
        $ruta = $archivo->store(self::CARPETA, 'local');

        try {
            $leido = $this->reader->readRaw(Storage::disk('local')->path($ruta), $extension);
        } catch (\Throwable $e) {
            Storage::disk('local')->delete($ruta);

            throw ValidationException::withMessages(['file' => $e->getMessage()]);
        }

        if ($leido['headers'] === []) {
            Storage::disk('local')->delete($ruta);

            throw ValidationException::withMessages([
                'file' => 'La primera fila del archivo tiene que traer los nombres de las columnas.',
            ]);
        }

        $borrador = ImportDraft::create([
            'user_id' => $request->user()->id,
            'filename' => $archivo->getClientOriginalName(),
            'destino' => $destino,
            'stored_path' => $ruta,
            'headers' => array_values($leido['headers']),
            'samples' => $this->muestras($leido['headers'], $leido['rows']),
            'rows_total' => count($leido['rows']),
        ]);

        return response()->json([
            'data' => $this->presentar($borrador),
            'columnas_sistema' => $mapper->columnasDelSistema(),
            'sugerencia' => $mapper->sugerir($leido['headers']),
        ], 201);
    }

    /**
     * Paso 2: el resumen de cómo quedó la homologación, sin importar nada.
     */
    public function preview(Request $request, ImportDraft $borrador): JsonResponse
    {
        $this->autorizar($request, $borrador);

        $mapper = $this->mapperDe($borrador->destino);

        $mapa = $mapper->validar(
            $this->mapaPedido($request),
            $borrador->headers,
        );

        $filas = $mapper->aplicar($this->filasDe($borrador), $mapa);

        return response()->json([
            'data' => $this->presentar($borrador),
            'mapping' => $mapa,
            // Qué columnas del archivo quedan sin usar. No es un error, pero
            // verlo listado es la forma más rápida de notar que se olvidó una.
            'sin_usar' => $this->sinUsar($borrador, $mapa),
            'resumen' => $mapper->ensayar($filas, $this->opciones($request, $mapa)),
        ]);
    }

    /**
     * Paso 3: importar de verdad, con la homologación aprobada.
     */
    public function confirm(Request $request, ImportDraft $borrador): JsonResponse
    {
        $this->autorizar($request, $borrador);

        $esquema = $this->esquemas->para($borrador->destino);
        $mapper = new ImportMapping($esquema);

        $mapa = $mapper->validar(
            $this->mapaPedido($request),
            $borrador->headers,
        );

        $filas = $mapper->aplicar($this->filasDe($borrador), $mapa);

        $import = Import::create([
            'user_id' => $request->user()->id,
            'filename' => $borrador->filename,
            'destino' => $borrador->destino,
            'status' => Import::PENDING,
            // Queda registrado con qué homologación se cargó: si algo sale
            // torcido, lo primero que hay que poder mirar es qué se conectó
            // con qué.
            'mapping' => $mapa,
        ]);

        // Las mismas opciones con las que se calculó el resumen. Si acá
        // llegaran otras, lo que alguien aprobó y lo que se ejecuta no serían
        // lo mismo, que es justamente lo que este camino de tres pasos existe
        // para evitar.
        $import = $esquema->importar($filas, $import, $this->opciones($request, $mapa));

        $this->descartar($borrador);

        return response()->json([
            'message' => $esquema->mensajeFinal($import),
            'data' => (new ImportResource($import))->resolve(),
        ]);
    }

    /** Tirar el borrador sin importarlo. */
    public function destroy(Request $request, ImportDraft $borrador): JsonResponse
    {
        $this->autorizar($request, $borrador);
        $this->descartar($borrador);

        return response()->json(['message' => 'Planilla descartada.']);
    }

    // -----------------------------------------------------------------

    /**
     * El homologador para lo que se esté cargando.
     *
     * Se arma por petición y no se inyecta: depende del destino, que recién se
     * sabe al mirar el borrador.
     */
    private function mapperDe(string $destino): ImportMapping
    {
        return new ImportMapping($this->esquemas->para($destino));
    }

    /**
     * Lo que no es la homologación pero cambia el resultado.
     *
     * Se arma en un solo lugar porque lo usan los dos últimos pasos —el
     * resumen y la importación— y tienen que ser idénticas: el resumen dice
     * «se van a dar de baja 34 personas» y esa frase solo vale si la
     * importación se ejecuta con la misma casilla puesta.
     *
     * @param  array<string, string>  $mapa
     * @return array<string, mixed>
     */
    private function opciones(Request $request, array $mapa): array
    {
        $request->validate([
            'send_invitations' => ['sometimes', 'boolean'],
            'sincronizar_bajas' => ['sometimes', 'boolean'],
        ]);

        return [
            'enviar_invitaciones' => $request->boolean('send_invitations', true),
            // Dar de baja a quien el archivo no nombra. Va apagado por
            // omisión: es la única opción de esta pantalla que puede sacar
            // gente del directorio, y un valor por omisión que la encienda
            // convierte cualquier llamada distraída a la API en una purga.
            'sincronizar_bajas' => $request->boolean('sincronizar_bajas'),
            // Quién está importando, para no darse de baja a sí mismo.
            'ejecutor_id' => $request->user()->id,
            // Si la columna de estado se conectó. Sin esto no se puede
            // distinguir «la planilla no trae el dato» de «lo trae vacío».
            'activo_conectado' => isset($mapa['activo']),
        ];
    }

    /**
     * El borrador es de quien lo subió.
     *
     * No alcanza con que ambos sean administradores: el archivo con la nómina
     * está en el disco del servidor y su borrador es la única llave.
     */
    private function autorizar(Request $request, ImportDraft $borrador): void
    {
        abort_unless($borrador->user_id === $request->user()->id, 403, 'Esta planilla la subió otra persona.');
    }

    /**
     * @return array<string, string|null>
     */
    private function mapaPedido(Request $request): array
    {
        $request->validate([
            'mapping' => ['required', 'array'],
            'mapping.*' => ['nullable', 'string'],
        ], [
            'mapping.required' => 'No llegó ninguna homologación.',
        ]);

        return $request->input('mapping');
    }

    /**
     * Vuelve a leer el archivo guardado.
     *
     * Se relee en cada paso en vez de guardar las filas ya interpretadas: son
     * miles y ocuparían más en la base que el archivo entero en disco.
     *
     * @return array<int, array<string, string>>
     */
    private function filasDe(ImportDraft $borrador): array
    {
        if (! Storage::disk('local')->exists($borrador->stored_path)) {
            throw ValidationException::withMessages([
                'file' => 'La planilla ya no está en el servidor. Volvé a subirla.',
            ]);
        }

        $extension = pathinfo($borrador->filename, PATHINFO_EXTENSION);

        return $this->reader->readRaw(
            Storage::disk('local')->path($borrador->stored_path),
            $extension,
        )['rows'];
    }

    /**
     * Hasta tres valores distintos de cada columna, para reconocerla.
     *
     * Un encabezado que dice «CC» no le dice nada a nadie; ver «Santiago
     * Centro», «Viña del Mar» debajo, sí.
     *
     * @param  array<int, array{clave: string, etiqueta: string}>  $encabezados
     * @param  array<int, array<string, string>>  $filas
     * @return array<string, array<int, string>>
     */
    private function muestras(array $encabezados, array $filas): array
    {
        $muestras = [];
        $primeras = array_slice($filas, 0, 50);

        foreach ($encabezados as $columna) {
            $valores = [];

            foreach ($primeras as $fila) {
                $valor = trim((string) ($fila[$columna['clave']] ?? ''));

                if ($valor !== '' && ! in_array($valor, $valores, true)) {
                    $valores[] = $valor;
                }

                if (count($valores) === 3) {
                    break;
                }
            }

            $muestras[$columna['clave']] = $valores;
        }

        return $muestras;
    }

    /**
     * @param  array<string, string>  $mapa
     * @return array<int, string> etiquetas de las columnas que no se usaron
     */
    private function sinUsar(ImportDraft $borrador, array $mapa): array
    {
        $usadas = array_values($mapa);

        return array_values(array_map(
            fn (array $columna) => $columna['etiqueta'],
            array_filter(
                $borrador->headers,
                fn (array $columna) => ! in_array($columna['clave'], $usadas, true),
            ),
        ));
    }

    private function descartar(ImportDraft $borrador): void
    {
        Storage::disk('local')->delete($borrador->stored_path);
        $borrador->delete();
    }

    private function presentar(ImportDraft $borrador): array
    {
        return [
            'id' => $borrador->id,
            'filename' => $borrador->filename,
            'destino' => $borrador->destino,
            'rows_total' => $borrador->rows_total,
            'headers' => array_map(fn (array $c) => $c + [
                'ejemplos' => $borrador->samples[$c['clave']] ?? [],
            ], $borrador->headers),
        ];
    }
}
