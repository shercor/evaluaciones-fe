<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Import;
use App\Models\ImportRow;
use App\Services\DirectoryImportService;
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
    ) {}

    /** Historial de cargas. */
    public function index(): JsonResponse
    {
        $imports = Import::with('user:id,name,lastname')
            ->latest()
            ->paginate(15);

        return response()->json([
            'data' => $imports->getCollection()->map(fn (Import $i) => $this->resumen($i)),
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
        ]);

        $archivo = $request->file('file');

        $import = Import::create([
            'user_id' => $request->user()->id,
            'filename' => $archivo->getClientOriginalName(),
            'status' => Import::PENDING,
        ]);

        try {
            $filas = $this->reader->read($archivo);
        } catch (\Throwable $e) {
            $import->update(['status' => Import::FAILED, 'error' => $e->getMessage()]);

            return response()->json([
                'message' => $e->getMessage(),
                'data' => $this->resumen($import),
            ], 422);
        }

        $import = $this->importer->import(
            $filas,
            $import,
            $request->boolean('send_invitations', true),
        );

        return response()->json([
            'message' => $this->mensajeFinal($import),
            'data' => $this->resumen($import),
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
            'import' => $this->resumen($import),
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
            fputcsv($salida, ['RUT-100', 'Ana', 'Pérez', 'ana.perez@empresa.cl', 'Gerente General', 'Casa Matriz', '', 'admin']);
            fputcsv($salida, ['RUT-101', 'Luis', 'Gómez', 'luis.gomez@empresa.cl', 'Supervisor', 'Sucursal Norte', 'RUT-100', 'collaborator']);
            fputcsv($salida, ['RUT-102', 'Sofía', 'Díaz', '', 'Vendedor', 'Sucursal Norte', 'RUT-101', 'collaborator']);

            fclose($salida);
        }, 'plantilla-nomina.csv', [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }

    // -----------------------------------------------------------------

    private function resumen(Import $import): array
    {
        return [
            'id' => $import->id,
            'filename' => $import->filename,
            'status' => $import->status,
            'rows_total' => $import->rows_total,
            'rows_created' => $import->rows_created,
            'rows_updated' => $import->rows_updated,
            'rows_failed' => $import->rows_failed,
            'error' => $import->error,
            'has_passwords' => $import->rowsWithPassword()->exists(),
            'created_at' => $import->created_at?->toIso8601String(),
            'user' => $import->relationLoaded('user') && $import->user
                ? trim($import->user->name.' '.$import->user->lastname)
                : null,
        ];
    }

    private function mensajeFinal(Import $import): string
    {
        $partes = [];

        if ($import->rows_created > 0) {
            $partes[] = "{$import->rows_created} creadas";
        }
        if ($import->rows_updated > 0) {
            $partes[] = "{$import->rows_updated} actualizadas";
        }
        if ($import->rows_failed > 0) {
            $partes[] = "{$import->rows_failed} rechazadas";
        }

        return $partes === []
            ? 'La planilla no tenía filas para procesar.'
            : 'Importación terminada: '.implode(', ', $partes).'.';
    }
}
