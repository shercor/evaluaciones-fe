<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Support\E360\Resources\GroupsApi;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Grupos de evaluación.
 *
 * Un grupo define a qué conjunto de personas apunta un proceso —«General»,
 * «Jefaturas», «Terreno»— y de él depende la numeración de períodos: el
 * período 2 de un grupo es independiente del período 2 de otro.
 *
 * Viven enteramente en Evaluación 360; este controlador solo los expone.
 */
class GroupController extends Controller
{
    public function __construct(private readonly GroupsApi $api) {}

    public function index(Request $request): JsonResponse
    {
        $respuesta = $this->api->paginated(['page' => $request->integer('page', 1)]);

        if ($respuesta->failed()) {
            return $this->errorDeApi($respuesta->message, $respuesta->errorKind);
        }

        return response()->json([
            'data' => array_map(static fn ($g) => [
                'id' => $g->id,
                'nombre' => $g->nombre ?? null,
                'descripcion' => $g->descripcion ?? null,
                'activo' => (bool) ($g->activo ?? true),
            ], $respuesta->collect('grupos')),
            'meta' => $respuesta->meta,
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $datos = $this->validar($request);

        $respuesta = $this->api->create($datos['nombre'], $datos['descripcion'] ?? null);

        if ($respuesta->failed()) {
            // La API valida que el nombre no se repita; su mensaje ya lo dice.
            return response()->json(['message' => $respuesta->message], 422);
        }

        return response()->json(['message' => 'Grupo creado.'], 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $datos = $this->validar($request);

        $respuesta = $this->api->update($id, $datos['nombre'], $datos['descripcion'] ?? null);

        if ($respuesta->failed()) {
            return response()->json(['message' => $respuesta->message], 422);
        }

        return response()->json(['message' => 'Grupo actualizado.']);
    }

    /**
     * Desactiva o reactiva.
     *
     * No se borra ninguno: las evaluaciones pasadas lo referencian, y perderlo
     * dejaría huecos en el historial.
     */
    public function toggleActive(Request $request, int $id): JsonResponse
    {
        $activar = $request->boolean('activar');

        $respuesta = $activar ? $this->api->restore($id) : $this->api->deactivate($id);

        if ($respuesta->failed()) {
            return response()->json(['message' => $respuesta->message], 422);
        }

        return response()->json([
            'message' => $activar ? 'Grupo activado.' : 'Grupo desactivado.',
        ]);
    }

    // -----------------------------------------------------------------

    private function validar(Request $request): array
    {
        return $request->validate([
            'nombre' => ['required', 'string', 'max:255'],
            'descripcion' => ['nullable', 'string', 'max:255'],
        ], [
            'nombre.required' => 'El nombre del grupo es obligatorio.',
        ]);
    }

    private function errorDeApi(?string $mensaje, ?string $tipo): JsonResponse
    {
        return response()->json([
            'message' => $mensaje ?? 'Error al consultar Evaluación 360.',
        ], $tipo === 'connection' ? 503 : 502);
    }
}
