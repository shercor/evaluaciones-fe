<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Enums\Role;
use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use App\Models\User;
use App\Notifications\DirectoryInvitation;
use App\Services\AvatarStorage;
use App\Services\PersonSuggestions;
use App\Services\SupervisionChain;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/**
 * Administración de personas del directorio.
 *
 * Solo `super_admin` y `admin` llegan acá — lo aplica el middleware `role` en
 * las rutas, no este controlador.
 */
class UserController extends Controller
{
    public function __construct(private readonly SupervisionChain $chain) {}

    /**
     * Listado con filtros y paginación, todo resuelto en el servidor.
     */
    public function index(Request $request): JsonResponse
    {
        $query = User::query()->with(['branchOffice', 'jobPosition', 'supervisor']);

        if ($buscar = $request->string('search')->trim()->toString()) {
            $query->where(function ($q) use ($buscar) {
                $q->where('name', 'like', "%{$buscar}%")
                    ->orWhere('lastname', 'like', "%{$buscar}%")
                    ->orWhere('email', 'like', "%{$buscar}%")
                    ->orWhere('external_code', 'like', "%{$buscar}%");
            });
        }

        foreach (['branch_office_id', 'job_position_id', 'role', 'supervisor_id'] as $filtro) {
            if ($request->filled($filtro)) {
                $query->where($filtro, $request->input($filtro));
            }
        }

        // `active` es booleano: `filled()` no sirve porque "0" cuenta como vacío.
        if ($request->has('active') && $request->input('active') !== '') {
            $query->where('active', $request->boolean('active'));
        }

        $orden = $request->string('sort')->toString() ?: 'name';
        $direccion = $request->string('direction')->toString() === 'desc' ? 'desc' : 'asc';

        if (! in_array($orden, ['name', 'lastname', 'email', 'external_code', 'created_at'], true)) {
            $orden = 'name';
        }

        $pagina = $query->orderBy($orden, $direccion)->paginate(
            perPage: min($request->integer('per_page', 20), 100),
        );

        // Los supervisados de toda la página en una sola consulta recursiva,
        // en vez de una por fila como hacía la intranet.
        $conteos = $this->chain->countSuperviseesFor(
            $pagina->getCollection()->pluck('id')->all(),
        );

        return response()->json([
            'data' => UserResource::collection($pagina->getCollection())->resolve(),
            'supervisees_count' => $conteos,
            'meta' => [
                'current_page' => $pagina->currentPage(),
                'last_page' => $pagina->lastPage(),
                'per_page' => $pagina->perPage(),
                'total' => $pagina->total(),
            ],
        ]);
    }

    /**
     * Quiénes supervisan a alguien, para el filtro del listado.
     *
     * Solo aparece gente que tiene al menos un supervisado: ofrecer la nómina
     * entera daría opciones que no devuelven ninguna fila.
     */
    public function supervisorOptions(Request $request): JsonResponse
    {
        return response()->json([
            'data' => PersonSuggestions::para(
                User::whereIn('id', User::query()->whereNotNull('supervisor_id')->select('supervisor_id')),
                $request->string('search')->trim()->toString(),
            ),
        ]);
    }

    /**
     * Candidatos a supervisor, para el buscador del formulario.
     *
     * Antes el formulario ofrecía **solo a las personas de la página actual**
     * del listado —lo decía el propio texto de ayuda—, así que asignar a
     * alguien de la página 40 era imposible.
     *
     * Quedan fuera la propia persona y toda su cadena de supervisados: elegir a
     * un subordinado crearía un ciclo, y aunque `update()` lo rechaza después,
     * es mejor no ofrecer una opción que se va a negar.
     */
    public function supervisorCandidates(Request $request): JsonResponse
    {
        $consulta = User::query()->active();

        if ($excluir = $request->integer('exclude')) {
            $consulta->whereNotIn('id', [$excluir, ...$this->chain->allSuperviseeIds($excluir)]);
        }

        return response()->json([
            'data' => PersonSuggestions::para(
                $consulta,
                $request->string('search')->trim()->toString(),
            ),
        ]);
    }

    public function show(User $user): JsonResponse
    {
        $user->load(['branchOffice', 'jobPosition', 'supervisor']);

        return response()->json([
            'data' => (new UserResource($user))->resolve(),
            'supervisees_count' => $this->chain->countSupervisees($user->id),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $datos = $this->validar($request);

        $tieneCorreo = ! blank($datos['email']);

        $user = User::create($datos + [
            'active' => true,
            'must_set_password' => true,
            'password' => null,
        ]);

        if ($tieneCorreo) {
            $user->notify(new DirectoryInvitation);
        }

        return response()->json([
            'data' => (new UserResource($user->load(['branchOffice', 'jobPosition', 'supervisor'])))->resolve(),
            'message' => $tieneCorreo
                ? 'Persona creada. Le enviamos la invitación para definir su contraseña.'
                : 'Persona creada. Como no tiene correo, generale una contraseña temporal desde el listado.',
        ], 201);
    }

    public function update(Request $request, User $user): JsonResponse
    {
        $datos = $this->validar($request, $user);

        if ($this->chain->wouldCreateCycle($user->id, $datos['supervisor_id'] ?? null)) {
            throw ValidationException::withMessages([
                'supervisor_id' => 'Ese supervisor ya depende de esta persona: la asignación crearía un ciclo en el organigrama.',
            ]);
        }

        $user->update($datos);

        return response()->json([
            'data' => (new UserResource($user->load(['branchOffice', 'jobPosition', 'supervisor'])))->resolve(),
            'message' => 'Datos actualizados.',
        ]);
    }

    /**
     * Activa o desactiva. No se borra a nadie: las evaluaciones pasadas
     * referencian a estas personas y borrarlas dejaría huecos en el historial.
     */
    public function toggleActive(Request $request, User $user): JsonResponse
    {
        if ($user->id === $request->user()->id) {
            throw ValidationException::withMessages([
                'active' => 'No podés desactivar tu propia cuenta.',
            ]);
        }

        $user->update(['active' => ! $user->active]);

        return response()->json([
            'data' => (new UserResource($user))->resolve(),
            'message' => $user->active ? 'Cuenta activada.' : 'Cuenta desactivada.',
        ]);
    }

    /**
     * Genera una contraseña temporal y la devuelve **una sola vez**.
     *
     * Es el camino para quien no tiene correo: el administrador la ve, la
     * entrega en mano, y la persona la cambia al primer ingreso.
     */
    public function resetPassword(User $user): JsonResponse
    {
        $temporal = $this->contrasenaTemporal();

        $user->forceFill([
            'password' => Hash::make($temporal),
            'must_set_password' => true,
        ])->save();

        return response()->json([
            'temporary_password' => $temporal,
            'message' => 'Contraseña temporal generada. Anotala ahora: no se vuelve a mostrar.',
        ]);
    }

    /**
     * Reenvía la invitación por correo.
     */
    public function resendInvitation(User $user): JsonResponse
    {
        if (! $user->hasMailbox()) {
            throw ValidationException::withMessages([
                'email' => 'Esta persona no tiene correo. Generale una contraseña temporal.',
            ]);
        }

        $user->notify(new DirectoryInvitation);

        return response()->json(['message' => 'Invitación reenviada.']);
    }

    /**
     * Cambia la foto de perfil.
     *
     * Llega la imagen tal como salió de la cámara y se guarda recortada,
     * enderezada y en WebP; el detalle está en `AvatarStorage`.
     */
    public function uploadAvatar(Request $request, User $user, AvatarStorage $fotos): JsonResponse
    {
        $request->validate([
            // `mimetypes` mira el contenido del archivo, no su extensión: un
            // `.jpg` que por dentro es otra cosa no pasa.
            'foto' => ['required', 'file', 'mimetypes:image/jpeg,image/png,image/webp', 'max:8192'],
        ], [
            'foto.required' => 'Elegí una imagen.',
            'foto.mimetypes' => 'La foto tiene que ser JPG, PNG o WebP. Las de iPhone vienen en HEIC y hay que exportarlas antes.',
            'foto.max' => 'La foto no puede pesar más de 8 MB.',
        ]);

        try {
            $fotos->guardar($user, $request->file('foto'));
        } catch (RuntimeException $e) {
            throw ValidationException::withMessages(['foto' => $e->getMessage()]);
        }

        return response()->json([
            'data' => (new UserResource($user->load(['branchOffice', 'jobPosition', 'supervisor'])))->resolve(),
            'message' => 'Foto actualizada.',
        ]);
    }

    /**
     * Quita la foto. La persona vuelve a mostrarse con sus iniciales.
     */
    public function deleteAvatar(User $user, AvatarStorage $fotos): JsonResponse
    {
        $fotos->borrar($user);

        return response()->json([
            'data' => (new UserResource($user->load(['branchOffice', 'jobPosition', 'supervisor'])))->resolve(),
            'message' => 'Foto quitada.',
        ]);
    }

    // -----------------------------------------------------------------

    private function validar(Request $request, ?User $user = null): array
    {
        return $request->validate([
            'external_code' => [
                'nullable', 'string', 'max:255',
                Rule::unique('users', 'external_code')->ignore($user),
            ],
            'name' => ['required', 'string', 'max:255'],
            'lastname' => ['nullable', 'string', 'max:255'],
            'email' => [
                'nullable', 'email', 'max:255',
                Rule::unique('users', 'email')->ignore($user),
            ],
            // El super administrador no se asigna desde acá: es personal de
            // Idea Uno y se crea aparte, no por administración del cliente.
            'role' => ['required', Rule::in([Role::ADMIN->value, Role::COLLABORATOR->value])],
            'branch_office_id' => ['nullable', 'exists:branch_offices,id'],
            'job_position_id' => ['nullable', 'exists:job_positions,id'],
            'supervisor_id' => ['nullable', 'exists:users,id'],
        ]);
    }

    private function contrasenaTemporal(): string
    {
        $alfabeto = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
        $clave = '';

        for ($i = 0; $i < 10; $i++) {
            $clave .= $alfabeto[random_int(0, strlen($alfabeto) - 1)];
        }

        return $clave;
    }
}
