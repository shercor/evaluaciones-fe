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
