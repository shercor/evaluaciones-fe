<?php

declare(strict_types=1);

namespace App\Support\E360\Resources;

use App\Support\E360\E360Client;
use App\Support\E360\E360Response;

/**
 * Participaciones y sus resultados.
 *
 * El padrón se envía **entero de una vez**: la API lo recibe, responde de
 * inmediato y genera las tareas en segundo plano, dejando la evaluación en
 * estado «preparando».
 */
class ParticipantsApi
{
    public function __construct(private readonly E360Client $client) {}

    /** Alta del padrón, al crear el proceso. */
    public function create(array $payload): E360Response
    {
        return $this->client->tenant('POST', '/api/participantes', body: $payload);
    }

    /** Corrección del padrón de un proceso que ya existe. */
    public function update(array $payload): E360Response
    {
        return $this->client->tenant('PUT', '/api/participantes', body: $payload);
    }

    public function show(int $participationId): E360Response
    {
        return $this->client->tenant('GET', "/api/participantes/{$participationId}");
    }

    public function results(int $participationId): E360Response
    {
        return $this->client->tenant('GET', "/api/participantes/{$participationId}/resultados");
    }

    public function supervisees(int $participationId): E360Response
    {
        return $this->client->tenant('GET', "/api/participantes/{$participationId}/supervisados/");
    }

    public function finishedEvaluations(int $userId): E360Response
    {
        return $this->client->tenant('GET', "/api/participantes/{$userId}/evaluaciones-finalizadas");
    }

    /** Participantes de una evaluación, paginados. */
    public function forEvaluation(int $evaluationId, array $query = []): E360Response
    {
        return $this->client->tenant('GET', "/api/evaluaciones/{$evaluationId}/participantes", $query);
    }
}
