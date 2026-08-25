<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Support\E360\E360Response;
use App\Support\E360\Resources\EvaluationsApi;
use App\Support\E360\Resources\TemplatesApi;
use Illuminate\Http\JsonResponse;

/**
 * Previsualización de los formularios: qué se le va a preguntar a la gente.
 *
 * Dos entradas, mismo formato de salida:
 *
 *  - **Por plantilla**, antes de crear la evaluación, para elegir con
 *    conocimiento de causa.
 *  - **Por evaluación**, después, para revisar qué quedó configurado.
 *
 * Las dos devuelven la misma forma a propósito, así el frontend usa un solo
 * componente. En la intranet también compartían el elemento de vista, pero
 * cada una llegaba por un controlador distinto.
 */
class FormsPreviewController extends Controller
{
    public function __construct(
        private readonly EvaluationsApi $evaluations,
        private readonly TemplatesApi $templates,
    ) {}

    public function forEvaluation(int $id): JsonResponse
    {
        return $this->present($this->evaluations->questionsPreview($id));
    }

    public function forTemplate(int $id): JsonResponse
    {
        return $this->present($this->templates->questionsPreview($id));
    }

    // -----------------------------------------------------------------

    private function present(E360Response $respuesta): JsonResponse
    {
        if ($respuesta->failed()) {
            return response()->json([
                'message' => $respuesta->message ?? 'No se pudo cargar la previsualización.',
            ], $respuesta->errorKind === 'connection' ? 503 : 502);
        }

        // La API nombra las cosas distinto acá que en el resto: el formulario
        // se identifica por `tipo`, las categorías vienen en
        // `categorias_formulario` bajo la clave `categoria`, y el enunciado de
        // cada pregunta es `texto`, no `nombre`.
        $formularios = [];

        foreach ($respuesta->collect('formularios_evaluacion') as $formulario) {
            $categorias = [];
            $totalPreguntas = 0;

            foreach ($formulario->categorias_formulario ?? [] as $categoria) {
                $preguntas = array_map(static fn ($p) => [
                    'texto' => $p->texto ?? null,
                    'tipo' => $p->tipo ?? null,
                ], $categoria->preguntas ?? []);

                $totalPreguntas += count($preguntas);

                $categorias[] = [
                    'nombre' => $categoria->categoria ?? null,
                    'descripcion' => $categoria->descripcion ?? null,
                    // Una categoría puede quedar fuera del promedio, o
                    // mostrarse solo si se cumple una condición. Vale la pena
                    // verlo al revisar qué se va a preguntar.
                    'en_promedio' => (bool) ($categoria->incluida_en_promedio ?? true),
                    'condicional' => (bool) ($categoria->es_condicional ?? false),
                    'condicion' => $categoria->condicion ?? null,
                    'preguntas' => $preguntas,
                ];
            }

            $formularios[] = [
                'nombre' => $formulario->tipo ?? 'Formulario',
                'total_preguntas' => $totalPreguntas,
                'categorias' => $categorias,
            ];
        }

        return response()->json(['formularios' => $formularios]);
    }
}
