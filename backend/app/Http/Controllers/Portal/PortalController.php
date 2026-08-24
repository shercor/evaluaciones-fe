<?php

declare(strict_types=1);

namespace App\Http\Controllers\Portal;

use App\Http\Controllers\Controller;
use App\Models\Evaluation;
use App\Models\EvaluationUser;
use App\Support\E360\Resources\ParticipantsApi;
use App\Support\E360\Resources\TasksApi;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Portal del colaborador: responder la propia evaluación.
 *
 * **Regla que gobierna todo este controlador:** la persona siempre sale de
 * `$request->user()`, jamás de un parámetro. Ninguna ruta de acá acepta un
 * `user_id` desde el navegador.
 *
 * Es justo lo que la intranet no respeta: su `updateTasksStatus()` es público
 * y recibe el `user_id` por POST, así que cualquiera puede marcar las tareas
 * de otro como completadas.
 */
class PortalController extends Controller
{
    public function __construct(
        private readonly TasksApi $tasks,
        private readonly ParticipantsApi $participants,
    ) {}

    /**
     * Mis evaluaciones: la que está en curso y las ya finalizadas.
     */
    public function myEvaluations(Request $request): JsonResponse
    {
        $userId = $request->user()->id;

        $enCurso = null;
        $respuestaEnCurso = $this->tasks->ongoingEvaluation();

        if ($respuestaEnCurso->ok) {
            $evaluacion = $respuestaEnCurso->get('evaluaciones');

            // La API devuelve un objeto suelto, o nada si no hay ninguna abierta.
            if ($evaluacion && isset($evaluacion->id)) {
                $participacion = $this->tasks->participation((int) $evaluacion->id, $userId);

                // Solo se ofrece si esta persona realmente participa: una
                // evaluación abierta no incluye necesariamente a todo el mundo.
                if ($participacion->ok && $participacion->get('participante')) {
                    $enCurso = [
                        'id' => (int) $evaluacion->id,
                        'titulo' => $evaluacion->titulo ?? null,
                        'year' => $evaluacion->year ?? null,
                        'periodo' => $evaluacion->periodo ?? null,
                        'tareas_completadas' => $this->tasksCompleted((int) $evaluacion->id, $userId),
                    ];
                }
            }
        }

        $finalizadas = [];
        $respuestaFinalizadas = $this->participants->finishedEvaluations($userId);

        if ($respuestaFinalizadas->ok) {
            foreach ($respuestaFinalizadas->collect('evaluaciones') as $e) {
                $finalizadas[] = [
                    'id' => $e->id ?? null,
                    'participacion_id' => $e->participacion_id ?? ($e->id_participacion ?? null),
                    'titulo' => $e->titulo ?? null,
                    'year' => $e->year ?? null,
                    'periodo' => $e->periodo ?? null,
                    'publicado' => (bool) ($e->publicado ?? false),
                    'tiene_supervisados' => (bool) ($e->tiene_supervisados ?? false),
                ];
            }
        }

        return response()->json([
            'en_curso' => $enCurso,
            'finalizadas' => $finalizadas,
        ]);
    }

    /**
     * Mis tareas pendientes en una evaluación.
     */
    public function tasks(Request $request, int $evaluationId): JsonResponse
    {
        $userId = $request->user()->id;

        $respuesta = $this->tasks->forParticipant($evaluationId, $userId);

        if ($respuesta->failed()) {
            return response()->json(['message' => $respuesta->message], 502);
        }

        // Ojo con la forma: `tareas_pendientes` es un **booleano**, no una
        // lista. Las tareas vienen en `tareas`, agrupadas por formulario, y
        // cada grupo trae a quiénes hay que evaluar desde esa perspectiva.
        $hayPendientes = (bool) ($respuesta->data->tareas_pendientes ?? false);

        // Se deja constancia local: es lo que consume el aviso de «tenés
        // cosas por responder».
        $this->markCompleted($evaluationId, $userId, ! $hayPendientes);

        $formularios = [];
        $totalTareas = 0;
        $totalHechas = 0;

        foreach ($respuesta->collect('tareas') as $grupo) {
            $evaluados = [];

            foreach ($grupo->evaluados ?? [] as $evaluado) {
                $hecha = (bool) ($evaluado->realizado ?? false);
                $totalTareas++;
                $hecha && $totalHechas++;

                $evaluados[] = [
                    'tarea_id' => $evaluado->tarea_id ?? null,
                    'nombre' => $evaluado->nombre ?? null,
                    'realizado' => $hecha,
                ];
            }

            $formularios[] = [
                'form_id' => $grupo->form->form_id ?? null,
                'nombre' => $grupo->form->nombre ?? 'Formulario',
                'evaluados' => $evaluados,
            ];
        }

        return response()->json([
            'evaluacion' => [
                'id' => $evaluationId,
                'titulo' => $respuesta->data->titulo ?? null,
                'descripcion' => $respuesta->data->descripcion ?? null,
                'estado' => $respuesta->data->estado ?? null,
                'grupo' => $respuesta->data->grupo->nombre ?? null,
            ],
            'formularios' => $formularios,
            'resumen' => [
                'total' => $totalTareas,
                'completadas' => $totalHechas,
                'pendientes' => $totalTareas - $totalHechas,
                'hay_pendientes' => $hayPendientes,
            ],
        ]);
    }

    /**
     * Las preguntas de una tarea mía.
     */
    public function questions(Request $request, int $taskId): JsonResponse
    {
        $respuesta = $this->tasks->questions($taskId, $request->user()->id);

        if ($respuesta->failed()) {
            // La API responde 404 si la tarea no es de esta persona, así que
            // este mismo camino cubre el intento de leer la de otro.
            return response()->json([
                'message' => $respuesta->message ?? 'No se pudo cargar la tarea.',
            ], $respuesta->httpStatus === 404 ? 404 : 502);
        }

        return response()->json([
            'data' => $respuesta->data,
            'cerrada' => ($respuesta->data->estado_evaluacion ?? null) === 'finalizado',
        ]);
    }

    /**
     * Guarda mis respuestas.
     */
    public function answer(Request $request, int $taskId): JsonResponse
    {
        $datos = $request->validate([
            'respuestas' => ['required', 'array', 'min:1'],
            'respuestas.*.pregunta_id' => ['required', 'integer'],
            'respuestas.*.respuesta' => ['present'],
        ]);

        $respuesta = $this->tasks->saveAnswers($taskId, $request->user()->id, $datos['respuestas']);

        if ($respuesta->failed()) {
            // Que la evaluación se haya cerrado mientras alguien respondía no
            // es un error del sistema: hay que decirlo con esas palabras.
            $cerrada = str_contains((string) $respuesta->message, 'cerrada');

            return response()->json([
                'message' => $cerrada
                    ? 'La evaluación fue cerrada mientras respondías, así que no se pudieron guardar estas respuestas.'
                    : ($respuesta->message ?? 'No se pudieron guardar las respuestas.'),
                'cerrada' => $cerrada,
            ], $cerrada ? 409 : 502);
        }

        return response()->json(['message' => 'Respuestas guardadas.']);
    }

    // -----------------------------------------------------------------

    private function tasksCompleted(int $e360Id, int $userId): bool
    {
        $evaluation = Evaluation::where('e360_id', $e360Id)->first();

        if (! $evaluation) {
            return false;
        }

        return (bool) EvaluationUser::where('evaluation_id', $evaluation->id)
            ->where('user_id', $userId)
            ->value('tasks_completed');
    }

    private function markCompleted(int $e360Id, int $userId, bool $completed): void
    {
        $evaluation = Evaluation::where('e360_id', $e360Id)->first();

        if (! $evaluation) {
            return;
        }

        EvaluationUser::where('evaluation_id', $evaluation->id)
            ->where('user_id', $userId)
            ->update(['tasks_completed' => $completed]);
    }
}
