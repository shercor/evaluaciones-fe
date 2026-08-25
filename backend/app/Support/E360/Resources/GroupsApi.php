<?php

declare(strict_types=1);

namespace App\Support\E360\Resources;

use App\Support\E360\E360Client;
use App\Support\E360\E360Response;

/**
 * Grupos de evaluación y períodos.
 */
class GroupsApi
{
    public function __construct(private readonly E360Client $client) {}

    /** Listado simple, para poblar selectores. */
    public function list(): E360Response
    {
        return $this->client->tenant('GET', '/api/grupos/list');
    }

    /** Listado paginado, para administrarlos. */
    public function paginated(array $query = []): E360Response
    {
        return $this->client->tenant('GET', '/api/grupos', $query);
    }

    public function show(int $id): E360Response
    {
        return $this->client->tenant('GET', "/api/grupos/{$id}");
    }

    /**
     * Crea un grupo.
     *
     * Igual que al crear evaluaciones, la API espera los nombres en español y
     * los mapea por dentro: mandar `name` devuelve «The name field is
     * required», que no ayuda a nadie. Se encapsula acá.
     */
    public function create(string $nombre, ?string $descripcion): E360Response
    {
        return $this->client->tenant('POST', '/api/grupos', body: [
            'nombre' => $nombre,
            'descripcion' => $descripcion,
        ]);
    }

    public function update(int $id, string $nombre, ?string $descripcion): E360Response
    {
        return $this->client->tenant('PUT', "/api/grupos/{$id}", body: [
            'nombre' => $nombre,
            'descripcion' => $descripcion,
        ]);
    }

    /** Desactiva el grupo; no lo borra. */
    public function deactivate(int $id): E360Response
    {
        return $this->client->tenant('DELETE', "/api/grupos/{$id}");
    }

    public function restore(int $id): E360Response
    {
        return $this->client->tenant('POST', "/api/grupos/{$id}/restore");
    }

    /**
     * Período sugerido para un año y un grupo.
     *
     * La API responde `{periodo: null}` cuando todavía no hay ninguno usado.
     */
    public function period(int $year, int $groupId): E360Response
    {
        return $this->client->tenant('GET', '/api/periodo', [
            'year' => $year,
            'grupo' => $groupId,
        ]);
    }
}
