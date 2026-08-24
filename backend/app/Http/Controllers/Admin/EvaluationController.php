<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Enums\EvaluationStatus;
use App\Http\Controllers\Controller;
use App\Models\Evaluation;
use App\Services\EvaluationActions;
use App\Support\E360\Resources\EvaluationsApi;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Listado y operación de los procesos de evaluación.
 *
 * Los datos vienen de Evaluación 360; este controlador los enriquece con lo
 * que la API no sabe —qué acciones corresponden en cada estado— y mantiene el
 * espejo local al día.
 */
class EvaluationController extends Controller
{
    public function __construct(
        private readonly EvaluationsApi $api,
        private readonly EvaluationActions $actions,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $respuesta = $this->api->list([
            'page' => $request->integer('page', 1),
            'nombre' => $request->string('nombre')->trim()->toString(),
            'year' => $request->string('year')->trim()->toString(),
            'periodo' => $request->string('periodo')->trim()->toString(),
            'estado' => $request->string('estado')->trim()->toString(),
        ]);

        if ($respuesta->failed()) {
            return $this->errorDeApi($respuesta->message, $respuesta->errorKind);
        }

        $evaluaciones = $respuesta->collect('evaluaciones');

        return response()->json([
            'data' => array_map($this->presentar(...), $evaluaciones),
            'meta' => $respuesta->meta,
            'statuses' => $this->catalogoDeEstados(),
        ]);
    }

    public function show(int $id): JsonResponse
    {
        $respuesta = $this->api->show($id);

        if ($respuesta->failed()) {
            return $this->errorDeApi($respuesta->message, $respuesta->errorKind);
        }

        $evaluacion = $respuesta->get('evaluacion');
        $this->sincronizar($evaluacion);

        return response()->json(['data' => $this->presentar($evaluacion)]);
    }

    /**
     * Estado actual, para que la interfaz sepa cuándo terminó la preparación.
     *
     * La intranet consultaba esto cada 10 segundos y **recargaba la página
     * entera** al detectar el cambio, guardando la posición del scroll en
     * `localStorage` para disimular. Acá devuelve solo lo necesario para
     * refrescar esa fila.
     */
    public function status(int $id): JsonResponse
    {
        $respuesta = $this->api->show($id);

        if ($respuesta->failed()) {
            return $this->errorDeApi($respuesta->message, $respuesta->errorKind);
        }

        $evaluacion = $respuesta->get('evaluacion');
        $this->sincronizar($evaluacion);

        return response()->json(['data' => $this->presentar($evaluacion)]);
    }

    // -- Transiciones --------------------------------------------------

    public function open(int $id): JsonResponse
    {
        return $this->transicion($id, EvaluationActions::OPEN, fn () => $this->api->open($id),
            'El proceso fue abierto. Los participantes ya pueden responder.');
    }

    public function close(int $id): JsonResponse
    {
        return $this->transicion($id, EvaluationActions::CLOSE, fn () => $this->api->close($id),
            'El proceso fue cerrado. Podés reabrirlo mientras no publiques los resultados.');
    }

    public function publish(int $id): JsonResponse
    {
        return $this->transicion($id, EvaluationActions::PUBLISH, fn () => $this->api->publish($id),
            'Resultados publicados. Esta acción no se puede deshacer.');
    }

    public function destroy(int $id): JsonResponse
    {
        return $this->transicion($id, EvaluationActions::DELETE, fn () => $this->api->delete($id),
            'La evaluación fue desactivada.');
    }

    public function restore(int $id): JsonResponse
    {
        return $this->transicion($id, EvaluationActions::RESTORE, fn () => $this->api->restore($id),
            'La evaluación fue reactivada.');
    }

    // -----------------------------------------------------------------

    /**
     * Ejecuta una transición comprobando antes que corresponda.
     *
     * La comprobación se repite en el servidor aunque el frontend ya haya
     * ocultado el botón: lo que Angular decide es qué mostrar, no qué se
     * permite.
     */
    private function transicion(
        int $id,
        string $accion,
        callable $ejecutar,
        string $mensajeExito,
    ): JsonResponse {
        $actual = $this->api->show($id);

        if ($actual->failed()) {
            return $this->errorDeApi($actual->message, $actual->errorKind);
        }

        $evaluacion = $actual->get('evaluacion');

        if (! $this->actions->allows($evaluacion, $accion)) {
            return response()->json([
                'message' => $this->actions->reasonFor($evaluacion, $accion),
            ], 422);
        }

        $respuesta = $ejecutar();

        if ($respuesta->failed()) {
            return $this->errorDeApi($respuesta->message, $respuesta->errorKind);
        }

        // Se relee para devolver el estado real y no uno supuesto: algunas
        // transiciones dejan el proceso «preparando» y no en el estado final.
        $refrescada = $this->api->show($id);
        $evaluacion = $refrescada->ok ? $refrescada->get('evaluacion') : $evaluacion;
        $this->sincronizar($evaluacion);

        return response()->json([
            'message' => $mensajeExito,
            'data' => $this->presentar($evaluacion),
        ]);
    }

    /**
     * Agrega a la evaluación de la API lo que sabe este servicio.
     */
    private function presentar(object $evaluacion): array
    {
        $estado = EvaluationStatus::tryFromLabel($evaluacion->estado ?? null);

        return [
            'id' => $evaluacion->id,
            'titulo' => $evaluacion->titulo ?? null,
            'year' => $evaluacion->year ?? null,
            'periodo' => $evaluacion->periodo ?? null,
            'fecha_inicio' => $evaluacion->fecha_inicio ?? null,
            'fecha_fin' => $evaluacion->fecha_fin ?? null,
            'fecha_creacion' => $evaluacion->fecha_creacion ?? null,
            'estado' => $evaluacion->estado ?? null,
            'estado_label' => $estado?->label(),
            'estado_descripcion' => $estado?->description(),
            'estado_color' => $estado?->color(),
            'en_transicion' => $estado?->isTransient() ?? false,
            'activo' => (bool) ($evaluacion->activo ?? true),
            'publicado' => (bool) ($evaluacion->publicado ?? false),
            'acciones' => $this->actions->for($evaluacion),
        ];
    }

    private function sincronizar(object $evaluacion): void
    {
        if (! isset($evaluacion->id)) {
            return;
        }

        Evaluation::forE360((int) $evaluacion->id, $evaluacion->titulo ?? null)
            ->syncStatus($evaluacion->estado ?? null);
    }

    /**
     * Los seis estados con su etiqueta y color, para el filtro y los
     * distintivos del listado.
     */
    private function catalogoDeEstados(): array
    {
        return array_map(
            static fn (EvaluationStatus $e) => [
                'valor' => $e->value,
                'label' => $e->label(),
                'color' => $e->color(),
            ],
            EvaluationStatus::cases(),
        );
    }

    /**
     * Un fallo de conexión no es lo mismo que un rechazo de la API, y la
     * interfaz tiene que poder decirlo con distintas palabras.
     */
    private function errorDeApi(?string $mensaje, ?string $tipo): JsonResponse
    {
        $codigo = $tipo === 'connection' ? 503 : 502;

        return response()->json([
            'message' => $mensaje ?? 'Error al consultar Evaluación 360.',
            'kind' => $tipo,
        ], $codigo);
    }
}
