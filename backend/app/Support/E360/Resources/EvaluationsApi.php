<?php

declare(strict_types=1);

namespace App\Support\E360\Resources;

use App\Support\E360\E360Client;
use App\Support\E360\E360Response;

/**
 * Evaluaciones y sus transiciones de estado.
 *
 * Establece el patrón para el resto de los recursos: métodos con nombre y
 * argumentos tipados, sin que el llamador arme rutas a mano.
 */
class EvaluationsApi
{
    public function __construct(private readonly E360Client $client) {}

    /**
     * @param  array<string, mixed>  $filters  nombre, year, periodo, estado, page
     */
    public function list(array $filters = []): E360Response
    {
        return $this->client->tenant('GET', '/api/evaluaciones', query: array_filter(
            $filters,
            static fn ($value) => $value !== null && $value !== '',
        ));
    }

    public function show(int $id): E360Response
    {
        return $this->client->tenant('GET', "/api/evaluaciones/{$id}");
    }

    /**
     * Crea una evaluación.
     *
     * El contrato de la API está a medio traducir: espera `titulo`,
     * `descripcion` y `periodo` en español, pero `year`, `group_id`,
     * `template_id` y `formularios` en inglés — su `prepareForValidation()`
     * mapea los tres primeros y valida el resto tal cual. Mandar `name` en vez
     * de `titulo` devuelve «The name field is required», que no ayuda nada.
     *
     * Este método recibe nombres coherentes y arma el payload mezclado, para
     * que el resto del proyecto no tenga que saberlo.
     *
     * @param  array{titulo:string, descripcion:string, year:int, periodo:int,
     *               group_id:int, template_id:int, formularios:array<int,int>}  $datos
     */
    public function create(array $datos): E360Response
    {
        return $this->client->tenant('POST', '/api/evaluaciones', body: [
            'titulo' => $datos['titulo'],
            'descripcion' => $datos['descripcion'],
            'periodo' => $datos['periodo'],
            'year' => $datos['year'],
            'group_id' => $datos['group_id'],
            'template_id' => $datos['template_id'],
            'formularios' => $datos['formularios'],
        ]);
    }

    /**
     * Con la evaluación ya en proceso solo se admite un cambio parcial: la
     * intranet quitaba año, grupo, período, plantilla y formularios del payload
     * y mandaba PATCH en vez de PUT.
     */
    public function update(int $id, array $data, bool $inProcess = false): E360Response
    {
        if ($inProcess) {
            unset(
                $data['year'],
                $data['group_id'],
                $data['fecha_creacion'],
                $data['periodo'],
                $data['template_id'],
                $data['formularios'],
            );
        }

        return $this->client->tenant($inProcess ? 'PATCH' : 'PUT', "/api/evaluaciones/{$id}", body: $data);
    }

    public function delete(int $id): E360Response
    {
        return $this->client->tenant('DELETE', "/api/evaluaciones/{$id}");
    }

    public function restore(int $id): E360Response
    {
        return $this->client->tenant('POST', "/api/evaluaciones/{$id}/restaurar");
    }

    public function open(int $id): E360Response
    {
        return $this->client->tenant('POST', "/api/evaluaciones/{$id}/abrir");
    }

    public function close(int $id): E360Response
    {
        return $this->client->tenant('POST', "/api/evaluaciones/{$id}/cerrar");
    }

    public function publish(int $id): E360Response
    {
        return $this->client->tenant('POST', "/api/evaluaciones/{$id}/publicar");
    }

    /**
     * Los seis estados con su etiqueta y su color. De aquí salen los colores
     * de los distintivos del listado, en vez de tenerlos escritos a mano.
     */
    public function statuses(): E360Response
    {
        return $this->client->tenant('GET', '/api/evaluaciones/estados');
    }

    public function ongoing(): E360Response
    {
        return $this->client->tenant('GET', '/api/evaluaciones/en-curso');
    }

    public function forms(int $id): E360Response
    {
        return $this->client->tenant('GET', "/api/evaluaciones/{$id}/formularios");
    }

    public function questionsPreview(int $id): E360Response
    {
        return $this->client->tenant('GET', "/api/evaluaciones/{$id}/previsualizacion_preguntas");
    }
}
