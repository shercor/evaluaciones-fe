<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Enums\EvaluationStatus;
use App\Http\Controllers\Controller;
use App\Models\Evaluation;
use App\Models\EvaluationUser;
use App\Models\User;
use App\Services\ParticipantChanges;
use App\Services\ParticipantRoster;
use App\Services\ParticipationEditor;
use App\Services\ParticipationSubmission;
use App\Services\PersonSuggestions;
use App\Services\SupervisorGroups;
use App\Support\E360\Resources\EvaluationsApi;
use App\Support\E360\Resources\GroupsApi;
use App\Support\E360\Resources\TemplatesApi;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
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
     * Período que le toca a este año y grupo.
     *
     * Ojo con el nombre: la API ya devuelve el período **siguiente** al último
     * usado, no el último. Sumarle uno lo corre de lugar.
     *
     * Devuelve `null` cuando el grupo nunca tuvo una evaluación. Es el único
     * caso en que la persona puede elegir el número: en cualquier otro el
     * período viene dado por los que ya existen y se impone.
     */
    public function period(Request $request): JsonResponse
    {
        $respuesta = $this->groupsApi->period(
            $request->integer('year'),
            $request->integer('group_id'),
        );

        // Un fallo de la API no puede devolver `null`: se confundiría con
        // «grupo nuevo» y dejaría editar un período que en realidad no se
        // pudo consultar.
        if ($respuesta->failed()) {
            return response()->json([
                'message' => $respuesta->message ?? 'No se pudo consultar el período.',
            ], $respuesta->errorKind === 'connection' ? 503 : 502);
        }

        $periodo = $respuesta->data->periodo ?? null;

        return response()->json([
            'periodo' => $periodo,
            'forzado' => $periodo !== null,
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $datos = $request->validate([
            'titulo' => ['required', 'string', 'max:255'],
            'descripcion' => ['required', 'string', 'max:255'],
            // Los mismos límites que la intranet: la API solo acepta el año
            // actual o el anterior, y el período es un mes del 1 al 12.
            'year' => ['required', 'integer', Rule::in([(int) date('Y'), (int) date('Y') - 1])],
            'periodo' => ['required', 'integer', 'min:1', 'max:12'],
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

        $evaluation = $this->editableFor($id);
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

    /**
     * La definición de un proceso ya creado, para volver al paso 1.
     *
     * Devuelve además qué se puede tocar: con la evaluación andando, cambiar
     * el grupo o la plantilla dejaría las respuestas colgando de una
     * configuración que ya no existe.
     */
    public function definition(int $id): JsonResponse
    {
        $evaluation = $this->localFor($id);
        $respuesta = $this->api->show($evaluation->e360_id);

        if ($respuesta->failed()) {
            return $this->errorDeApi($respuesta);
        }

        $remota = $respuesta->get('evaluacion');
        $formularios = $this->api->forms($evaluation->e360_id);

        return response()->json([
            'titulo' => $remota->titulo ?? '',
            'descripcion' => $remota->descripcion ?? '',
            'year' => (int) ($remota->year ?? date('Y')),
            'periodo' => (int) ($remota->periodo ?? 1),
            'group_id' => (int) ($remota->group_id ?? ($remota->grupo->id ?? 0)),
            'template_id' => (int) ($remota->template_id ?? ($remota->template->id ?? 0)),
            'formularios' => $formularios->ok
                ? array_map(static fn ($f) => (int) ($f->id_tipo_formulario ?? $f->id),
                    $formularios->collect('formularios'))
                : [],
            'estado' => $evaluation->status?->label(),
            'editable' => (bool) $evaluation->status?->allowsDefinitionEditing(),
            // Cuando es true, solo viajan título y descripción.
            'solo_textos' => (bool) $evaluation->status?->allowsOnlyTextEditing(),
        ]);
    }

    /**
     * Guarda los cambios del paso 1 sobre un proceso que ya existe.
     */
    public function updateDefinition(Request $request, int $id): JsonResponse
    {
        $evaluation = $this->localFor($id);

        if (! $evaluation->status?->allowsDefinitionEditing()) {
            throw ValidationException::withMessages([
                'estado' => sprintf(
                    'No se puede modificar la definición de una evaluación %s.',
                    $evaluation->status?->label() ?? 'en este estado',
                ),
            ]);
        }

        $soloTextos = $evaluation->status->allowsOnlyTextEditing();

        $reglas = [
            'titulo' => ['required', 'string', 'max:255'],
            'descripcion' => ['required', 'string', 'max:255'],
        ];

        if (! $soloTextos) {
            $reglas += [
                'year' => ['required', 'integer', Rule::in([(int) date('Y'), (int) date('Y') - 1])],
                'periodo' => ['required', 'integer', 'min:1', 'max:12'],
                'group_id' => ['required', 'integer'],
                'template_id' => ['required', 'integer'],
                'formularios' => ['required', 'array', 'min:1'],
                'formularios.*' => ['integer'],
            ];
        }

        $datos = $request->validate($reglas);

        // El recorte también se hace del lado del servidor: que la pantalla
        // deshabilite un campo no impide que llegue por la API.
        $respuesta = $this->api->update($evaluation->e360_id, $datos, $soloTextos);

        if ($respuesta->failed()) {
            return $this->errorDeApi($respuesta);
        }

        return response()->json([
            'message' => $soloTextos
                ? 'Se actualizaron el título y la descripción.'
                : 'Definición del proceso actualizada.',
        ]);
    }

    private function errorDeApi(\App\Support\E360\E360Response $respuesta): JsonResponse
    {
        return response()->json(
            ['message' => $respuesta->message ?? 'No se pudo consultar la evaluación.'],
            $respuesta->errorKind === 'connection' ? 503 : 502,
        );
    }

    // -- Paso 4 · Depurar participantes -------------------------------

    public function participants(Request $request, int $id): JsonResponse
    {
        $evaluation = $this->localFor($id);

        // Ojo con calificar las columnas: más abajo se une `users`, que tiene
        // `branch_office_id`, `job_position_id` y `supervisor_id` con los
        // mismos nombres. Sin el prefijo, MySQL las declara ambiguas y la
        // consulta muere. Y el prefijo correcto es `evaluation_users`: ahí
        // viven los valores **congelados** al armar el padrón, que son los que
        // la pantalla muestra. Filtrar por los de `users` daría los vigentes y
        // devolvería filas que no coinciden con lo que se ve en la tabla.
        $query = EvaluationUser::query()
            ->where('evaluation_users.evaluation_id', $evaluation->id)
            ->with(['user:id,name,lastname,avatar_path', 'jobPosition:id,name', 'branchOffice:id,name', 'supervisor:id,name,lastname']);

        if ($buscar = $request->string('search')->trim()->toString()) {
            $query->whereHas('user', function ($q) use ($buscar) {
                $q->where('name', 'like', "%{$buscar}%")
                    ->orWhere('lastname', 'like', "%{$buscar}%")
                    ->orWhere('external_code', 'like', "%{$buscar}%");
            });
        }

        foreach (['branch_office_id', 'job_position_id', 'supervisor_id'] as $filtro) {
            if ($request->filled($filtro)) {
                $query->where("evaluation_users.{$filtro}", $request->input($filtro));
            }
        }

        if ($request->has('participate') && $request->input('participate') !== '') {
            $query->where('evaluation_users.participate', $request->boolean('participate'));
        }

        // Orden por columna, como en la intranet. La lista blanca evita que
        // llegue un nombre de columna cualquiera desde la URL.
        $columnas = [
            'nombre' => 'users.name',
            'cargo' => 'job_positions.name',
            'sucursal' => 'branch_offices.name',
            'supervisor' => 'supervisores.name',
            'participa' => 'evaluation_users.participate',
        ];

        $orden = $columnas[$request->string('sort')->toString()] ?? 'users.name';
        $sentido = $request->string('direction')->lower()->toString() === 'desc' ? 'desc' : 'asc';

        $pagina = $query
            ->join('users', 'users.id', '=', 'evaluation_users.user_id')
            ->leftJoin('job_positions', 'job_positions.id', '=', 'evaluation_users.job_position_id')
            ->leftJoin('branch_offices', 'branch_offices.id', '=', 'evaluation_users.branch_office_id')
            ->leftJoin('users as supervisores', 'supervisores.id', '=', 'evaluation_users.supervisor_id')
            ->orderBy($orden, $sentido)
            // Desempate estable: sin esto, dos filas con el mismo cargo pueden
            // cambiar de orden entre páginas y una persona aparecer dos veces.
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
                'foto' => $f->user?->avatarUrl(),
                'participate' => $f->participate,
                'cargo' => $f->jobPosition ? ['id' => $f->jobPosition->id, 'nombre' => $f->jobPosition->name] : null,
                'sucursal' => $f->branchOffice ? ['id' => $f->branchOffice->id, 'nombre' => $f->branchOffice->name] : null,
                'supervisor' => $f->supervisor ? ['id' => $f->supervisor->id, 'nombre' => $f->supervisor->fullName()] : null,
                'supervisados' => $conteos[$f->user_id] ?? 0,
            ]),
            'meta' => [
                'current_page' => $pagina->currentPage(),
                'last_page' => $pagina->lastPage(),
                // Ojo: `total` es lo que devuelve el **filtro**, no el padrón.
                // Sin `total_padron` la pantalla mezclaba los dos números y
                // decía cosas como «22 de 1 participan».
                'total' => $pagina->total(),
                'total_padron' => EvaluationUser::where('evaluation_id', $evaluation->id)->count(),
                'participando' => $this->submission->countParticipating($evaluation),
            ],
            'cambios_pendientes' => $this->changes->count($evaluation),
        ]);
    }

    /**
     * Supervisores que figuran en el padrón, para el filtro del listado.
     *
     * Antes esta lista viajaba **entera dentro de cada página** del listado:
     * cargaba las 7.078 filas del padrón con su supervisor para quedarse con
     * 527 nombres, 167 ms y 56 MB por petición, y llenaba un desplegable de
     * 527 opciones que nadie puede recorrer con la vista. Ahora se busca por
     * lo que se escribe y solo viajan las coincidencias.
     *
     * El `whereIn` con subconsulta se resuelve contra el índice de
     * `supervisor_id`: no se instancia ni una fila del padrón.
     */
    public function rosterSupervisorOptions(Request $request, int $id): JsonResponse
    {
        $evaluation = $this->localFor($id);

        return response()->json([
            'data' => PersonSuggestions::para(
                User::whereIn('id', EvaluationUser::query()
                    ->where('evaluation_id', $evaluation->id)
                    ->whereNotNull('supervisor_id')
                    ->select('supervisor_id')),
                $request->string('search')->trim()->toString(),
            ),
        ]);
    }

    public function setParticipation(Request $request, int $id): JsonResponse
    {
        $request->validate([
            'user_id' => ['required', 'integer'],
            'participate' => ['required', 'boolean'],
            'with_supervisees' => ['sometimes', 'boolean'],
        ]);

        $evaluation = $this->editableFor($id);

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
            // El nuevo total sale de acá y no de sumar los afectados: en una
            // cascada algunos ya podían estar en ese estado, así que contarlos
            // desde el cliente daría un número equivocado. Con esto la pantalla
            // se actualiza sola, sin recargar el listado.
            'participando' => $this->submission->countParticipating($evaluation),
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

        $evaluation = $this->editableFor($id);

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

        return response()->json([
            'data' => PersonSuggestions::para(
                User::whereIn('id', EvaluationUser::query()
                    ->where('evaluation_id', $evaluation->id)
                    ->participating()
                    ->when(
                        $request->filled('exclude'),
                        fn ($q) => $q->where('user_id', '!=', $request->integer('exclude')),
                    )
                    ->select('user_id')),
                $request->string('search')->trim()->toString(),
            ),
        ]);
    }

    // -- Paso 5 · Grupos por supervisor -------------------------------

    public function preview(int $id): JsonResponse
    {
        $evaluation = $this->localFor($id);

        // La pantalla necesita saber si esto es el alta del proceso o una
        // corrección de uno ya creado: cambia el texto del botón y, sobre
        // todo, si hay algo que enviar.
        return response()->json($this->groups->build($evaluation) + [
            'es_alta' => $evaluation->status === EvaluationStatus::CREATING,
            'estado' => $evaluation->status?->label(),
            // Sin esto el bloqueo salía por carambola («no hay cambios») en vez
            // de por el motivo real, y un proceso terminado con cambios
            // pendientes habría dejado pulsar Enviar para fallar con un 422.
            'permite_editar' => (bool) $evaluation->status?->allowsRosterEditing(),
            'cambios_pendientes' => $this->changes->count($evaluation),
        ]);
    }

    public function excludeOrphans(Request $request, int $id): JsonResponse
    {
        $request->validate([
            'user_ids' => ['required', 'array', 'min:1'],
            'user_ids.*' => ['integer'],
        ]);

        $evaluation = $this->editableFor($id);
        $total = $this->groups->excludeOrphans($evaluation, $request->input('user_ids'));

        return response()->json([
            'message' => sprintf('%d participantes quedaron fuera del proceso.', $total),
        ]);
    }

    // -- Paso 6 · Enviar ----------------------------------------------

    public function submit(int $id): JsonResponse
    {
        $evaluation = $this->editableFor($id);

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
        $evaluation = $this->editableFor($id);
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
    /**
     * Igual que `localFor`, pero además exige que el padrón se pueda tocar.
     *
     * Sin esto se podía cambiar la participación de una evaluación ya
     * finalizada: guardaba sin protestar, sobre tareas ya respondidas y
     * resultados ya calculados. La intranet lo bloquea en `selectParticipants`.
     */
    private function editableFor(int $e360Id): Evaluation
    {
        $evaluation = $this->localFor($e360Id);

        if (! $evaluation->status?->allowsRosterEditing()) {
            throw ValidationException::withMessages([
                'estado' => sprintf(
                    'No se puede modificar el padrón de una evaluación %s.',
                    $evaluation->status?->label() ?? 'en este estado',
                ),
            ]);
        }

        return $evaluation;
    }

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
