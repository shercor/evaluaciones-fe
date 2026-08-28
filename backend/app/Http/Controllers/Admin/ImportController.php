<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\ImportResource;
use App\Models\Import;
use App\Models\ImportRow;
use App\Services\DirectoryImportSchema;
use App\Services\DirectoryImportService;
use App\Services\ImportSchemas;
use App\Services\SpreadsheetReader;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Carga de la nómina desde planilla.
 */
class ImportController extends Controller
{
    public function __construct(
        private readonly SpreadsheetReader $reader,
        private readonly DirectoryImportService $importer,
        private readonly DirectoryImportSchema $esquema,
    ) {}

    /**
     * Historial de cargas de nómina.
     *
     * Solo las de nómina: las de sucursales y cargos comparten tabla pero se
     * ven en la pantalla del catálogo que cargaron, donde tienen sentido.
     */
    public function index(): JsonResponse
    {
        $imports = Import::with('user:id,name,lastname')
            ->where('destino', ImportSchemas::NOMINA)
            ->latest()
            ->paginate(15);

        return response()->json([
            'data' => ImportResource::collection($imports->getCollection())->resolve(),
            'meta' => [
                'current_page' => $imports->currentPage(),
                'last_page' => $imports->lastPage(),
                'total' => $imports->total(),
            ],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $request->validate([
            'file' => ['required', 'file', 'mimes:csv,txt,xlsx,xls', 'max:10240'],
            'send_invitations' => ['sometimes', 'boolean'],
            'sincronizar_bajas' => ['sometimes', 'boolean'],
        ]);

        $archivo = $request->file('file');

        $import = Import::create([
            'user_id' => $request->user()->id,
            'filename' => $archivo->getClientOriginalName(),
            'destino' => ImportSchemas::NOMINA,
            'status' => Import::PENDING,
        ]);

        try {
            $filas = $this->reader->read($archivo);
        } catch (\Throwable $e) {
            $import->update(['status' => Import::FAILED, 'error' => $e->getMessage()]);

            return response()->json([
                'message' => $e->getMessage(),
                'data' => (new ImportResource($import))->resolve(),
            ], 422);
        }

        $import = $this->importer->import($filas, $import, [
            'enviar_invitaciones' => $request->boolean('send_invitations', true),
            // Apagado por omisión, igual que en el camino homologado: es la
            // única opción que puede sacar gente del directorio. Este camino
            // no tiene resumen previo, así que la pantalla avisa antes de
            // mandarla puesta.
            'sincronizar_bajas' => $request->boolean('sincronizar_bajas'),
            'ejecutor_id' => $request->user()->id,
        ]);

        return response()->json([
            'message' => $this->mensajeFinal($import),
            'data' => (new ImportResource($import))->resolve(),
        ]);
    }

    /**
     * Detalle de una carga: qué pasó con cada fila.
     */
    public function show(Request $request, Import $import): JsonResponse
    {
        $query = $import->rows()->orderBy('line');

        if ($resultado = $request->string('outcome')->toString()) {
            $query->where('outcome', $resultado);
        }

        $filas = $query->paginate(50);

        return response()->json([
            'import' => (new ImportResource($import))->resolve(),
            'rows' => $filas->getCollection()->map(fn (ImportRow $r) => [
                'line' => $r->line,
                'outcome' => $r->outcome,
                'error' => $r->error,
                'payload' => $r->payload,
                'has_temporary_password' => $r->temporary_password !== null,
            ]),
            'meta' => [
                'current_page' => $filas->currentPage(),
                'last_page' => $filas->lastPage(),
                'total' => $filas->total(),
            ],
        ]);
    }

    /**
     * Descarga las contraseñas temporales en CSV.
     *
     * Es el camino para el personal sin correo corporativo: el administrador
     * baja la planilla y entrega cada clave en mano. Se sirve como descarga y
     * no se vuelve a mostrar en pantalla.
     */
    public function downloadPasswords(Import $import): StreamedResponse
    {
        $nombre = 'contrasenas-temporales-'.$import->id.'.csv';

        return response()->streamDownload(function () use ($import) {
            $salida = fopen('php://output', 'wb');

            // BOM para que Excel abra las tildes correctamente.
            fwrite($salida, "\xEF\xBB\xBF");
            fputcsv($salida, ['codigo', 'nombre', 'apellido', 'contrasena_temporal']);

            $import->rowsWithPassword()->orderBy('line')->chunk(200, function ($filas) use ($salida) {
                foreach ($filas as $fila) {
                    fputcsv($salida, [
                        $fila->payload['codigo'] ?? '',
                        $fila->payload['nombre'] ?? '',
                        $fila->payload['apellido'] ?? '',
                        $fila->temporary_password,
                    ]);
                }
            });

            fclose($salida);
        }, $nombre, [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }

    /**
     * Planilla de ejemplo, con los encabezados que entiende el sistema.
     */
    public function template(): StreamedResponse
    {
        return response()->streamDownload(function () {
            $salida = fopen('php://output', 'wb');

            fwrite($salida, "\xEF\xBB\xBF");
            fputcsv($salida, DirectoryImportService::COLUMNS);

            // Las columnas de código van vacías a propósito en la primera
            // fila y llenas en las otras: se ve que son opcionales y que,
            // cuando están, viajan **junto** al nombre.
            //
            // La última columna es el estado. La cuarta fila viene en `0`
            // para que se vea qué hace: esa persona no se crea, y si estaba
            // en el directorio se da de baja.
            fputcsv($salida, ['RUT-100', 'Ana', 'Pérez', 'ana.perez@empresa.cl', 'Gerente General', '', 'Casa Matriz', '', '', '1']);
            fputcsv($salida, ['RUT-101', 'Luis', 'Gómez', 'luis.gomez@empresa.cl', 'Supervisor', 'SUP', 'Sucursal Norte', 'SUC-N', 'RUT-100', '1']);
            fputcsv($salida, ['RUT-102', 'Sofía', 'Díaz', '', 'Vendedor', 'VEND', 'Sucursal Norte', 'SUC-N', 'RUT-101', '1']);
            fputcsv($salida, ['RUT-103', 'Marta', 'Rojas', '', 'Vendedor', 'VEND', 'Sucursal Norte', 'SUC-N', 'RUT-101', '0']);

            fclose($salida);
        }, 'plantilla-nomina.csv', [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }

    // -----------------------------------------------------------------

    /**
     * El texto lo arma el esquema de la nómina, que es el mismo que usa la
     * carga homologada: para quien mira la pantalla terminó una importación,
     * y las dos tienen que contarlo igual.
     */
    private function mensajeFinal(Import $import): string
    {
        return $this->esquema->mensajeFinal($import);
    }
}
