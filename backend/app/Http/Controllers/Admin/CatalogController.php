<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\BranchOffice;
use App\Models\JobPosition;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Sucursales y cargos.
 *
 * Son dos catálogos con la misma forma —código, nombre, activo— así que
 * comparten controlador en vez de duplicarlo. El tipo llega por la ruta.
 */
class CatalogController extends Controller
{
    /** @var array<string, class-string<Model>> */
    private const TIPOS = [
        'sucursales' => BranchOffice::class,
        'cargos' => JobPosition::class,
    ];

    public function index(Request $request, string $tipo): JsonResponse
    {
        $modelo = $this->modelo($tipo);

        $query = $modelo::query()->withCount('users');

        if ($buscar = $request->string('search')->trim()->toString()) {
            $query->where('name', 'like', "%{$buscar}%");
        }

        if ($request->has('active') && $request->input('active') !== '') {
            $query->where('active', $request->boolean('active'));
        }

        return response()->json([
            'data' => $query->orderBy('name')->get()->map(fn ($item) => [
                'id' => $item->id,
                'external_code' => $item->external_code,
                'name' => $item->name,
                'active' => $item->active,
                'users_count' => $item->users_count,
            ]),
        ]);
    }

    public function store(Request $request, string $tipo): JsonResponse
    {
        $modelo = $this->modelo($tipo);
        $datos = $this->validar($request, $tipo);

        $item = $modelo::create($datos + ['active' => true]);

        return response()->json([
            'data' => $item,
            'message' => 'Creado correctamente.',
        ], 201);
    }

    public function update(Request $request, string $tipo, int $id): JsonResponse
    {
        $modelo = $this->modelo($tipo);
        $item = $modelo::findOrFail($id);

        $item->update($this->validar($request, $tipo, $id));

        return response()->json([
            'data' => $item,
            'message' => 'Actualizado correctamente.',
        ]);
    }

    /**
     * Activa o desactiva. Igual que con las personas, no se borra: hay
     * evaluaciones pasadas que apuntan acá.
     */
    public function toggleActive(string $tipo, int $id): JsonResponse
    {
        $modelo = $this->modelo($tipo);
        $item = $modelo::findOrFail($id);

        $item->update(['active' => ! $item->active]);

        return response()->json([
            'data' => $item,
            'message' => $item->active ? 'Activado.' : 'Desactivado.',
        ]);
    }

    // -----------------------------------------------------------------

    /** @return class-string<Model> */
    private function modelo(string $tipo): string
    {
        return self::TIPOS[$tipo] ?? abort(404, 'Catálogo desconocido.');
    }

    private function validar(Request $request, string $tipo, ?int $id = null): array
    {
        $tabla = (new (self::TIPOS[$tipo]))->getTable();

        return $request->validate([
            'name' => [
                'required', 'string', 'max:255',
                Rule::unique($tabla, 'name')->ignore($id),
            ],
            'external_code' => [
                'nullable', 'string', 'max:255',
                Rule::unique($tabla, 'external_code')->ignore($id),
            ],
        ], [
            'name.unique' => 'Ya existe otro con ese nombre.',
            'external_code.unique' => 'Ese código ya está en uso.',
        ]);
    }
}
