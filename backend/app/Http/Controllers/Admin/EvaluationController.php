<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Enums\EvaluationStatus;
use App\Http\Controllers\Controller;
use App\Jobs\NotifyEvaluationOpened;
use App\Jobs\NotifyEvaluationReminder;
use App\Jobs\NotifyResultsPublished;
use App\Jobs\NotifyRoster;
use App\Models\Evaluation;
use App\Models\EvaluationUserChange;
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

        // Cuántos cambios sin enviar tiene cada proceso, en una sola consulta.
        // Sin esto el listado ofrecía «Abrir» sobre una evaluación editada a
        // medias, que es justo lo que no hay que dejar hacer.
        $pendientes = EvaluationUserChange::query()
            ->join('evaluations', 'evaluations.id', '=', 'evaluation_user_changes.evaluation_id')
            ->whereIn('evaluations.e360_id', array_map(static fn ($e) => $e->id, $evaluaciones))
            ->selectRaw('evaluations.e360_id, COUNT(*) as total')
            ->groupBy('evaluations.e360_id')
            ->pluck('total', 'e360_id');

        return response()->json([
            'data' => array_map(
                fn ($e) => $this->presentar($e) + [
                    'cambios_pendientes' => (int) ($pendientes[$e->id] ?? 0),
                ],
                $evaluaciones,
            ),
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

    /**
     * Abrir el proceso, con o sin aviso a los participantes.
     *
     * Son dos acciones y no una, igual que en la intranet
     * (`PersonalEvaluationsController::openEvaluation($id, $notify)`): abrir
     * en silencio tiene que seguir siendo posible, porque un proceso de
     * prueba abierto por error son miles de correos que no se pueden
     * despachar de vuelta.
     */
    public function open(Request $request, int $id): JsonResponse
    {
        $respuesta = $this->transicion($id, EvaluationActions::OPEN, fn () => $this->api->open($id),
            'El proceso fue abierto. Los participantes ya pueden responder.');

        if ($respuesta->getStatusCode() !== 200 || ! $request->boolean('notificar')) {
            return $respuesta;
        }

        return $this->avisar($id, $respuesta, NotifyEvaluationOpened::class, 'El proceso fue abierto. ');
    }

    /**
     * Encola un aviso y le cuenta al administrador a cuántos va.
     *
     * El reparto es diferido, pero los números se cuentan acá y ahora: son
     * dos consultas con índice, y decir «se avisa a 120, 8 se quedan afuera»
     * en el momento vale más que hacerlo rápido. La intranet no lo hace y el
     * administrador nunca se entera de quién quedó sin aviso.
     *
     * @param  class-string<NotifyRoster>  $job
     */
    private function avisar(int $id, JsonResponse $respuesta, string $job, string $encabezado): JsonResponse
    {
        $evaluacion = Evaluation::where('e360_id', $id)->first();

        if (! $evaluacion) {
            return $respuesta;
        }

        $destinatarios = $job::audiencia($evaluacion->id)->count();
        $sinCorreo = $job::sinCorreo($evaluacion->id);

        $datos = $respuesta->getData(true);
        $nombre = $datos['data']['titulo'] ?? $evaluacion->name ?? 'el proceso';

        if ($destinatarios > 0) {
            $job::dispatch($id, (string) $nombre);
        }

        $datos['message'] = $encabezado.$this->resumenDelAviso($destinatarios, $sinCorreo);
        $datos['aviso'] = ['destinatarios' => $destinatarios, 'sin_correo' => $sinCorreo];

        return response()->json($datos);
    }

    /**
     * El texto que ve el administrador. No promete entrega inmediata: el
     * reparto va por la cola y puede tardar minutos.
     */
    private function resumenDelAviso(int $destinatarios, int $sinCorreo): string
    {
        $mensaje = match (true) {
            // Que no haya destinatarios significa dos cosas distintas, y
            // decir «nadie tiene correo» sobre un padrón vacío manda al
            // administrador a revisar los correos en vez del padrón.
            $destinatarios === 0 && $sinCorreo === 0 => 'No hay participantes en el padrón, así que no se envió ningún aviso.',
            $destinatarios === 0 => 'Nadie del padrón tiene correo registrado, así que no se envió ningún aviso.',
            $destinatarios === 1 => 'Se está avisando a 1 participante.',
            default => "Se está avisando a {$destinatarios} participantes.",
        };

        if ($sinCorreo > 0 && $destinatarios > 0) {
            $mensaje .= $sinCorreo === 1
                ? ' 1 persona no tiene correo registrado y no fue avisada.'
                : " {$sinCorreo} personas no tienen correo registrado y no fueron avisadas.";
        }

        return $mensaje;
    }

    public function close(int $id): JsonResponse
    {
        return $this->transicion($id, EvaluationActions::CLOSE, fn () => $this->api->close($id),
            'El proceso fue cerrado. Podés reabrirlo mientras no publiques los resultados.');
    }

    /**
     * Publicar los resultados, con o sin aviso a los participantes.
     *
     * Los dos caminos existen por la misma razón que en «Abrir»: publicar en
     * silencio tiene que seguir siendo posible. Acá pesa todavía más, porque
     * publicar **no se puede deshacer**: si el aviso saliera solo, un clic de
     * más serían miles de correos anunciando resultados que nadie eligió
     * anunciar todavía.
     */
    public function publish(Request $request, int $id): JsonResponse
    {
        $respuesta = $this->transicion($id, EvaluationActions::PUBLISH, fn () => $this->api->publish($id),
            'Resultados publicados. Esta acción no se puede deshacer.');

        if ($respuesta->getStatusCode() !== 200 || ! $request->boolean('notificar')) {
            return $respuesta;
        }

        return $this->avisar($id, $respuesta, NotifyResultsPublished::class, 'Resultados publicados. ');
    }

    /**
     * Recordarles a los que todavía no respondieron.
     *
     * No es una transición: el proceso queda exactamente como estaba. Por eso
     * no pasa por [transicion], pero sí repite la comprobación de permiso —
     * que Angular haya ocultado el botón no es garantía de nada— y devuelve la
     * fila al día, para que el listado se refresque igual que con el resto de
     * las acciones.
     *
     * Se puede mandar cuantas veces haga falta, y a propósito: es la única
     * herramienta que tiene el administrador para mover a los rezagados. Lo
     * que no hace es mandarse solo.
     */
    public function remind(int $id): JsonResponse
    {
        $actual = $this->api->show($id);

        if ($actual->failed()) {
            return $this->errorDeApi($actual->message, $actual->errorKind);
        }

        $evaluacion = $actual->get('evaluacion');

        if (! $this->actions->allows($evaluacion, EvaluationActions::REMIND)) {
            return response()->json([
                'message' => $this->actions->reasonFor($evaluacion, EvaluationActions::REMIND),
            ], 422);
        }

        $this->sincronizar($evaluacion);

        $espejo = Evaluation::where('e360_id', $id)->first();

        if (! $espejo) {
            return response()->json([
                'message' => 'Todavía no hay padrón local para este proceso, así que no hay a quién recordarle.',
            ], 422);
        }

        $destinatarios = NotifyEvaluationReminder::audiencia($espejo->id)->count();
        $sinCorreo = NotifyEvaluationReminder::sinCorreo($espejo->id);
        $nombre = $evaluacion->titulo ?? $espejo->name ?? 'el proceso';

        if ($destinatarios > 0) {
            NotifyEvaluationReminder::dispatch($id, (string) $nombre);
        }

        return response()->json([
            'message' => $this->resumenDelRecordatorio($destinatarios, $sinCorreo),
            'data' => $this->presentar($evaluacion),
            'aviso' => ['destinatarios' => $destinatarios, 'sin_correo' => $sinCorreo],
        ]);
    }

    /**
     * El recordatorio necesita su propio texto porque «cero» significa dos
     * cosas distintas, y confundirlas dejaría al administrador creyendo que
     * falló algo cuando en realidad ya terminaron todos.
     */
    private function resumenDelRecordatorio(int $destinatarios, int $sinCorreo): string
    {
        if ($destinatarios === 0 && $sinCorreo === 0) {
            return 'No quedan participantes con tareas pendientes: no se envió ningún recordatorio.';
        }

        if ($destinatarios === 0) {
            return $sinCorreo === 1
                ? 'La única persona con tareas pendientes no tiene correo registrado, así que no se envió ningún recordatorio.'
                : "Las {$sinCorreo} personas con tareas pendientes no tienen correo registrado, así que no se envió ningún recordatorio.";
        }

        $mensaje = $destinatarios === 1
            ? 'Se le está recordando a 1 participante con tareas pendientes.'
            : "Se les está recordando a {$destinatarios} participantes con tareas pendientes.";

        if ($sinCorreo > 0) {
            $mensaje .= $sinCorreo === 1
                ? ' 1 persona más está pendiente pero no tiene correo registrado.'
                : " {$sinCorreo} personas más están pendientes pero no tienen correo registrado.";
        }

        return $mensaje;
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
