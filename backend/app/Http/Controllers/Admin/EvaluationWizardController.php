<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Enums\EvaluationStatus;
use App\Http\Controllers\Controller;
use App\Models\Evaluation;
use App\Models\EvaluationUser;
use App\Services\ParticipantChanges;
use App\Services\ParticipantRoster;
use App\Services\ParticipationEditor;
use App\Services\ParticipationSubmission;
use App\Services\SupervisorGroups;
use App\Support\E360\Resources\EvaluationsApi;
use App\Support\E360\Resources\GroupsApi;
use App\Support\E360\Resources\TemplatesApi;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Asistente de creación de una evaluación.
 *
 * Los seis pasos del flujo. Cada uno persiste apenas se completa, así que se
 * puede abandonar y retomar — igual que `continueCreationProcess()` en la
 * intranet, pero sin la bandera `?creating=1` viajando por toda la secuencia:
 * acá el paso lo determina la ruta y el estado del proceso.
 */
class EvaluationWizardController extends Controller
{
    public function __construct(
        private readonly EvaluationsApi $api,
        private readonly TemplatesApi $templates,
        private readonly GroupsApi $groupsApi,
        private readonly ParticipantRoster $roster,
        private readonly ParticipationEditor $editor,
        private readonly SupervisorGroups $groups,
        private readonly ParticipationSubmission $submission,
        private readonly ParticipantChanges $changes,
    ) {}

    // -- Paso 1 · Definir el proceso ----------------------------------

    /**
     * Datos para armar el formulario: plantillas, grupos y años posibles.
     */
    public function options(): JsonResponse
    {
        $plantillas = $this->templates->withForms();
        $grupos = $this->groupsApi->list();

        if ($plantillas->failed() || $grupos->failed()) {
            return response()->json([
                'message' => $plantillas->failed() ? $plantillas->message : $grupos->message,
            ], 502);
        }

        return response()->json([
            'templates' => array_map(static fn ($t) => [
                'id' => $t->id,
                'nombre' => $t->nombre,
                'formularios' => array_map(static fn ($f) => [
                    'id' => $f->id_tipo_formulario,
                    'nombre' => $f->nombre_tipo_formulario,
                ], $t->formularios ?? []),
            ], $plantillas->collect('templates')),
            'groups' => array_map(static fn ($g) => [
                'id' => $g->id,
                'nombre' => $g->nombre,
            ], $grupos->collect('grupos')),
            // La API solo admite el año actual o el anterior.
            'years' => [(int) date('Y'), (int) date('Y') - 1],
        ]);
    }

    /**
     * Período sugerido para un año y grupo.
     */
    public function period(Request $request): JsonResponse
    {
        $respuesta = $this->groupsApi->period(
            $request->integer('year'),
            $request->integer('group_id'),
        );

        return response()->json([
            'periodo' => $respuesta->ok ? ($respuesta->data->periodo ?? null) : null,
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $datos = $request->validate([
            'titulo' => ['required', 'string', 'max:255'],
            'descripcion' => ['required', 'string', 'max:255'],
            'year' => ['required', 'integer'],
            'periodo' => ['required', 'integer', 'min:1'],
            'group_id' => ['required', 'integer'],
            'template_id' => ['required', 'integer'],
            'formularios' => ['required', 'array', 'min:1'],
            'formularios.*' => ['integer'],
        ]);

        $respuesta = $this->api->create($datos);

        if ($respuesta->failed()) {
            return response()->json([
                'message' => $respuesta->message,
                'errors' => $respuesta->data->errors ?? null,
            ], 422);
        }

        $creada = $respuesta->get('evaluacion');

        $evaluation = Evaluation::forE360((int) $creada->id, $creada->titulo ?? null);
        $evaluation->syncStatus($creada->estado ?? EvaluationStatus::CREATING->value);

        return response()->json([
            'message' => 'Evaluación creada. Ahora elegí las sucursales que participan.',
            'data' => ['id' => $evaluation->e360_id],
        ], 201);
    }

    // -- Paso 2 y 3 · Sucursales y padrón -----------------------------

    /**
     * Sucursales disponibles y las ya elegidas.
     */
    public function branchOffices(int $id): JsonResponse
    {
        $evaluation = $this->localFor($id);

        return response()->json([
            'disponibles' => $this->roster->availableBranchOffices(),
            'seleccionadas' => $this->roster->branchOfficeIds($evaluation),
        ]);
    }

    /**
     * Guarda las sucursales y materializa el padrón.
     *
     * Los dos pasos van juntos porque el padrón se deriva de las sucursales:
     * separarlos dejaría un estado intermedio sin sentido.
     */
    public function saveBranchOffices(Request $request, int $id): JsonResponse
    {
        $request->validate([
            'branch_offices' => ['present', 'array'],
            // `null` representa «Sin sucursal asignada».
            'branch_offices.*' => ['nullable', 'integer', 'exists:branch_offices,id'],
        ]);

        $evaluation = $this->localFor($id);
        $sucursales = $request->input('branch_offices', []);

        if ($sucursales === []) {
            throw ValidationException::withMessages([
                'branch_offices' => 'Elegí al menos una sucursal.',
            ]);
        }

        $this->roster->setBranchOffices($evaluation, $sucursales);
        $resumen = $this->roster->rebuild($evaluation, $sucursales);

        if ($resumen['creados'] + $resumen['actualizados'] === 0) {
            throw ValidationException::withMessages([
                'branch_offices' => 'Las sucursales elegidas no tienen personas evaluables.',
            ]);
        }

        return response()->json([
            'message' => sprintf(
                'Padrón armado: %d personas.',
                $resumen['creados'] + $resumen['actualizados'],
            ),
            'resumen' => $resumen,
        ]);
    }

    // -- Paso 4 · Depurar participantes -------------------------------

    public function participants(Request $request, int $id): JsonResponse
    {
        $evaluation = $this->localFor($id);

        $query = EvaluationUser::query()
            ->where('evaluation_id', $evaluation->id)
            ->with(['user:id,name,lastname', 'jobPosition:id,name', 'branchOffice:id,name', 'supervisor:id,name,lastname']);

        if ($buscar = $request->string('search')->trim()->toString()) {
            $query->whereHas('user', function ($q) use ($buscar) {
                $q->where('name', 'like', "%{$buscar}%")
                    ->orWhere('lastname', 'like', "%{$buscar}%")
                    ->orWhere('external_code', 'like', "%{$buscar}%");
            });
        }

        foreach (['branch_office_id', 'job_position_id', 'supervisor_id'] as $filtro) {
            if ($request->filled($filtro)) {
                $query->where($filtro, $request->input($filtro));
            }
        }

        if ($request->has('participate') && $request->input('participate') !== '') {
            $query->where('participate', $request->boolean('participate'));
        }

        $pagina = $query
            ->join('users', 'users.id', '=', 'evaluation_users.user_id')
            ->orderBy('users.name')
            ->select('evaluation_users.*')
            ->paginate(min($request->integer('per_page', 25), 100));

        $conteos = $this->editor->superviseeCounts(
            $evaluation,
            $pagina->getCollection()->pluck('user_id')->all(),
        );

        return response()->json([
            'data' => $pagina->getCollection()->map(fn (EvaluationUser $f) => [
                'user_id' => $f->user_id,
                'nombre' => $f->user?->fullName(),
                'iniciales' => $f->user?->initials(),
                'participate' => $f->participate,
                'cargo' => $f->jobPosition ? ['id' => $f->jobPosition->id, 'nombre' => $f->jobPosition->name] : null,
                'sucursal' => $f->branchOffice ? ['id' => $f->branchOffice->id, 'nombre' => $f->branchOffice->name] : null,
                'supervisor' => $f->supervisor ? ['id' => $f->supervisor->id, 'nombre' => $f->supervisor->fullName()] : null,
                'supervisados' => $conteos[$f->user_id] ?? 0,
            ]),
            'meta' => [
                'current_page' => $pagina->currentPage(),
                'last_page' => $pagina->lastPage(),
                'total' => $pagina->total(),
                'participando' => $this->submission->countParticipating($evaluation),
            ],
            'cambios_pendientes' => $this->changes->count($evaluation),
        ]);
    }

    public function setParticipation(Request $request, int $id): JsonResponse
    {
        $request->validate([
            'user_id' => ['required', 'integer'],
            'participate' => ['required', 'boolean'],
            'with_supervisees' => ['sometimes', 'boolean'],
        ]);

        $evaluation = $this->localFor($id);

        $afectados = $this->editor->setParticipation(
            $evaluation,
            $request->integer('user_id'),
            $request->boolean('participate'),
            $request->boolean('with_supervisees'),
        );

        return response()->json([
            'message' => count($afectados) === 1
                ? 'Participación actualizada.'
                : sprintf('Se actualizaron %d personas de la cadena.', count($afectados)),
            'afectados' => $afectados,
            'cambios_pendientes' => $this->changes->count($evaluation),
        ]);
    }

    public function updateParticipant(Request $request, int $id): JsonResponse
    {
        $request->validate([
            'user_id' => ['required', 'integer'],
            'branch_office_id' => ['nullable', 'integer', 'exists:branch_offices,id'],
            'job_position_id' => ['nullable', 'integer', 'exists:job_positions,id'],
            'supervisor_id' => ['nullable', 'integer'],
        ]);

        $evaluation = $this->localFor($id);

        $this->editor->updateDetails(
            $evaluation,
            $request->integer('user_id'),
            $request->input('branch_office_id'),
            $request->input('job_position_id'),
            $request->input('supervisor_id'),
        );

        return response()->json([
            'message' => 'Datos del participante actualizados.',
            'cambios_pendientes' => $this->changes->count($evaluation),
        ]);
    }

    /**
     * Candidatos a supervisor, para el buscador del formulario.
     *
     * Solo personas del padrón: asignar a alguien de afuera dejaría al
     * participante apuntando fuera del proceso.
     */
    public function supervisorOptions(Request $request, int $id): JsonResponse
    {
        $evaluation = $this->localFor($id);
        $buscar = $request->string('search')->trim()->toString();

        $filas = EvaluationUser::query()
            ->where('evaluation_id', $evaluation->id)
            ->participating()
            ->when($request->filled('exclude'), fn ($q) => $q->where('user_id', '!=', $request->integer('exclude')))
            ->whereHas('user', function ($q) use ($buscar) {
                if ($buscar !== '') {
                    $q->where('name', 'like', "%{$buscar}%")->orWhere('lastname', 'like', "%{$buscar}%");
                }
            })
            ->with('user:id,name,lastname')
            ->limit(20)
            ->get();

        return response()->json([
            'data' => $filas->map(fn (EvaluationUser $f) => [
                'id' => $f->user_id,
                'nombre' => $f->user?->fullName(),
            ])->values(),
        ]);
    }

    // -- Paso 5 · Grupos por supervisor -------------------------------

    public function preview(int $id): JsonResponse
    {
        $evaluation = $this->localFor($id);

        return response()->json($this->groups->build($evaluation));
    }

    public function excludeOrphans(Request $request, int $id): JsonResponse
    {
        $request->validate([
            'user_ids' => ['required', 'array', 'min:1'],
            'user_ids.*' => ['integer'],
        ]);

        $evaluation = $this->localFor($id);
        $total = $this->groups->excludeOrphans($evaluation, $request->input('user_ids'));

        return response()->json([
            'message' => sprintf('%d participantes quedaron fuera del proceso.', $total),
        ]);
    }

    // -- Paso 6 · Enviar ----------------------------------------------

    public function submit(int $id): JsonResponse
    {
        $evaluation = $this->localFor($id);

        $grupos = $this->groups->build($evaluation);

        if ($grupos['grupos'] === []) {
            throw ValidationException::withMessages([
                'participantes' => 'No se formó ningún grupo por supervisor. Revisá que los '
                    .'participantes tengan supervisores dentro del proceso.',
            ]);
        }

        if ($this->submission->countParticipating($evaluation) === 0) {
            throw ValidationException::withMessages([
                'participantes' => 'No hay participantes activos para enviar.',
            ]);
        }

        $esAlta = $evaluation->status === EvaluationStatus::CREATING;
        $respuesta = $this->submission->submit($evaluation, $esAlta);

        if ($respuesta->failed()) {
            return response()->json(['message' => $respuesta->message], 502);
        }

        // Los cambios ya viajaron: dejan de estar pendientes.
        $this->changes->clear($evaluation);

        // Se relee para reflejar que la API pasó a «preparando».
        $actual = $this->api->show($evaluation->e360_id);
        if ($actual->ok) {
            $evaluation->syncStatus($actual->get('evaluacion')->estado ?? null);
        }

        return response()->json([
            'message' => $esAlta
                ? 'Evaluación creada. La API está generando las tareas; puede tardar varios minutos.'
                : 'Los cambios en los participantes se enviaron correctamente.',
        ]);
    }

    /**
     * Descarta los cambios y vuelve el padrón a como estaba.
     */
    public function undoChanges(int $id): JsonResponse
    {
        $evaluation = $this->localFor($id);
        $total = $this->changes->undo($evaluation);

        return response()->json([
            'message' => $total === 0
                ? 'No había cambios pendientes.'
                : sprintf('Se revirtieron los cambios de %d participantes.', $total),
        ]);
    }

    // -----------------------------------------------------------------

    /**
     * El espejo local de la evaluación, creándolo si hace falta.
     *
     * Se sincroniza el estado desde la API en cada paso: de él dependen la
     * bitácora de cambios y si el envío es alta o corrección.
     */
    private function localFor(int $e360Id): Evaluation
    {
        $respuesta = $this->api->show($e360Id);

        if ($respuesta->failed()) {
            abort(502, $respuesta->message ?? 'No se pudo consultar la evaluación.');
        }

        $remota = $respuesta->get('evaluacion');

        $evaluation = Evaluation::forE360($e360Id, $remota->titulo ?? null);
        $evaluation->syncStatus($remota->estado ?? null);

        return $evaluation->fresh();
    }
}
