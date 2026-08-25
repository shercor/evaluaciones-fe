<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Support\E360\E360Response;
use App\Support\E360\Resources\ResultsApi;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Tableros de resultados y monitoreo, para administración.
 *
 * Los endpoints de Evaluación 360 comparten envoltura —`evaluacion`,
 * `nota_maxima`, `grupo`, `titulo`, `resultado`— así que se normalizan una
 * sola vez en `bloque()` y el frontend recibe siempre la misma forma. Eso es
 * lo que permite que un mismo componente dibuje cualquiera de ellos.
 */
class ResultsController extends Controller
{
    public function __construct(private readonly ResultsApi $api) {}

    /**
     * Tablero general de la evaluación.
     *
     * Se piden cinco cosas de una vez porque la pantalla las muestra juntas:
     * hacer cinco viajes desde el navegador sería más lento y dejaría la
     * página armándose de a pedazos.
     */
    public function dashboard(int $id): JsonResponse
    {
        $promedios = $this->api->average($id);

        if ($promedios->failed()) {
            return $this->errorDeApi($promedios);
        }

        $climaLaboral = (int) config('e360.form_types.clima_laboral');

        return response()->json([
            'contexto' => $this->contexto($promedios),
            'promedios' => $this->bloque($promedios),
            'participacion' => $this->bloque($this->api->participation($id)),
            'por_fuente' => $this->bloque($this->api->bySource($id)),
            'por_categoria' => $this->bloque($this->api->byCategoryAndSource($id)),
            'respuestas_abiertas' => $this->bloque($this->api->openAnswers($id, $climaLaboral)),
        ]);
    }

    /**
     * Tablero de una persona.
     *
     * Mismo formato que usa el portal para «mis resultados»: es el mismo panel
     * mirado desde otro lugar.
     */
    public function person(int $id, int $userId): JsonResponse
    {
        $promedios = $this->api->personAverage($id, $userId);

        if ($promedios->failed()) {
            return $this->errorDeApi($promedios);
        }

        return response()->json([
            'contexto' => $this->contexto($promedios),
            'participante' => $promedios->data->participante ?? null,
            'promedios' => $this->bloque($promedios),
            'categorias' => $this->bloque($this->api->personCategories($id, $userId)),
            'comentarios_recibidos' => $this->bloque($this->api->commentsReceived($id, $userId)),
            'comentarios_enviados' => $this->bloque($this->api->commentsSent($id, $userId)),
            'detalle' => $this->detalle($id, $userId),
        ]);
    }

    /** Resultados individuales de todos, para elegir a quién mirar. */
    public function people(Request $request, int $id): JsonResponse
    {
        $respuesta = $this->api->individualResults($id, array_filter([
            'page' => $request->integer('page', 1),
            'nombre' => $request->string('nombre')->trim()->toString(),
            'sucursal' => $request->string('sucursal')->trim()->toString(),
            'cargo' => $request->string('cargo')->trim()->toString(),
            'sort' => $request->string('sort')->toString(),
            'direction' => $request->string('direction')->toString(),
        ]));

        if ($respuesta->failed()) {
            return $this->errorDeApi($respuesta);
        }

        return response()->json([
            'data' => $respuesta->data,
            'meta' => $respuesta->meta,
        ]);
    }

    /** Avance del proceso: quién respondió y quién no. */
    public function monitor(int $id): JsonResponse
    {
        $metricas = $this->api->monitorMetrics($id);

        if ($metricas->failed()) {
            return $this->errorDeApi($metricas);
        }

        return response()->json([
            'evaluacion' => $metricas->data->evaluacion ?? null,
            'grupo' => $metricas->data->grupo->nombre ?? null,
            'metricas' => array_map(static fn ($m) => [
                'titulo' => $m->titulo ?? null,
                'realizadas' => (int) ($m->realizadas ?? 0),
                'total' => (int) ($m->total ?? 0),
                'porcentaje' => ($m->total ?? 0) > 0
                    ? (int) round(($m->realizadas ?? 0) / $m->total * 100)
                    : 0,
            ], $metricas->collect('metricas')),
        ]);
    }

    public function monitorPeople(Request $request, int $id): JsonResponse
    {
        $respuesta = $this->api->monitorParticipants($id, array_filter([
            'page' => $request->integer('page', 1),
            'nombre' => $request->string('nombre')->trim()->toString(),
            'estado' => $request->string('estado')->trim()->toString(),
            'sucursal' => $request->string('sucursal')->trim()->toString(),
            'cargo' => $request->string('cargo')->trim()->toString(),
        ]));

        if ($respuesta->failed()) {
            return $this->errorDeApi($respuesta);
        }

        return response()->json([
            'data' => array_map(static fn ($t) => [
                'id' => $t->id ?? null,
                'nombre' => $t->nombre ?? null,
                'cargo' => $t->cargo ?? null,
                'sucursal' => $t->sucursal ?? null,
                'completados' => $t->formularios_completados ?? null,
                'ultima_actividad' => $t->ultima_actividad ?? null,
                'estado' => $t->estado ?? null,
            ], $respuesta->collect('trabajadores')),
            'meta' => $respuesta->meta,
        ]);
    }

    /** Detalle por categoría, con sus preguntas. */
    public function categories(int $id): JsonResponse
    {
        $respuesta = $this->api->detailsByCategory($id);

        if ($respuesta->failed()) {
            return $this->errorDeApi($respuesta);
        }

        return response()->json(['data' => $respuesta->data]);
    }

    public function question(Request $request, int $id, int $questionId): JsonResponse
    {
        $respuesta = $this->api->questionDetails($id, $questionId, [
            'page' => $request->integer('page', 1),
        ]);

        if ($respuesta->failed()) {
            return $this->errorDeApi($respuesta);
        }

        return response()->json(['data' => $respuesta->data, 'meta' => $respuesta->meta]);
    }

    // -----------------------------------------------------------------

    /**
     * Los datos que enmarcan cualquier tablero: de qué evaluación es, sobre
     * qué nota está calculado y a qué grupo apunta.
     */
    private function contexto(E360Response $r): array
    {
        return [
            'evaluacion' => $r->data->evaluacion ?? null,
            'nota_maxima' => $r->data->nota_maxima ?? 5,
            'grupo' => $r->data->grupo->nombre ?? null,
        ];
    }

    /**
     * Normaliza un bloque del tablero.
     *
     * Si la llamada falló se devuelve el bloque vacío con su mensaje, en vez
     * de romper la pantalla entera: que no se pueda calcular un promedio no
     * debería impedir ver el resto.
     */
    private function bloque(E360Response $r): array
    {
        if ($r->failed()) {
            return [
                'titulo' => null,
                'promedio' => null,
                'resultado' => null,
                'error' => $r->message,
            ];
        }

        return [
            'titulo' => $r->data->titulo ?? null,
            // Según el endpoint se llama de una forma o de la otra.
            'promedio' => $r->data->promedio_general ?? ($r->data->promedio_categorias ?? null),
            'resultado' => $r->data->resultado ?? null,
            'error' => null,
        ];
    }

    /** Desglose pregunta por pregunta. Ver la nota en PortalController. */
    private function detalle(int $evaluationId, int $userId): ?array
    {
        $r = $this->api->participantDetail($evaluationId, $userId);

        if ($r->failed()) {
            return null;
        }

        return [
            'formularios' => $r->data->formularios ?? [],
            'categorias' => $r->data->categorias ?? [],
        ];
    }

    private function errorDeApi(E360Response $r): JsonResponse
    {
        return response()->json([
            'message' => $r->message ?? 'Error al consultar Evaluación 360.',
        ], $r->errorKind === 'connection' ? 503 : 502);
    }
}
