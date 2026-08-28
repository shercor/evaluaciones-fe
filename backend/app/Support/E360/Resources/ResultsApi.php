<?php

declare(strict_types=1);

namespace App\Support\E360\Resources;

use App\Support\E360\E360Client;
use App\Support\E360\E360Response;

/**
 * Resultados, tableros y monitoreo.
 *
 * Casi todos estos endpoints comparten la misma envoltura —`evaluacion`,
 * `nota_maxima`, `grupo`, `titulo`, `resultado`— y solo cambia la forma de
 * `resultado`. Eso permite presentarlos con un solo formato hacia el frontend.
 */
class ResultsApi
{
    public function __construct(private readonly E360Client $client) {}

    // -- Tablero general -----------------------------------------------

    public function average(int $evaluationId): E360Response
    {
        return $this->client->tenant('GET', "/api/evaluaciones/{$evaluationId}/dashboard/promedios");
    }

    public function participation(int $evaluationId): E360Response
    {
        return $this->client->tenant('GET', "/api/evaluaciones/{$evaluationId}/dashboard/nivel-participacion");
    }

    /** Promedio según desde qué perspectiva se evaluó. */
    public function bySource(int $evaluationId): E360Response
    {
        return $this->client->tenant('GET', "/api/evaluaciones/{$evaluationId}/dashboard/fuente-evaluacion");
    }

    /** Promedio cruzando categoría y tipo de evaluador. */
    public function byCategoryAndSource(int $evaluationId): E360Response
    {
        return $this->client->tenant('GET', "/api/evaluaciones/{$evaluationId}/dashboard/categoria-evaluador");
    }

    /** Respuestas abiertas de un tipo de formulario. */
    public function openAnswers(int $evaluationId, int $formTypeId): E360Response
    {
        return $this->client->tenant('GET', "/api/evaluaciones/{$evaluationId}/dashboard/respuestas/{$formTypeId}");
    }

    /** Resultados individuales de todos, paginados. */
    public function individualResults(int $evaluationId, array $query = []): E360Response
    {
        return $this->client->tenant('GET', "/api/evaluaciones/{$evaluationId}/dashboard/resultados-individuales", $query);
    }

    // -- Tablero de una persona ----------------------------------------

    public function personAverage(int $evaluationId, int $userId): E360Response
    {
        return $this->client->tenant('GET', "/api/evaluaciones/{$evaluationId}/dashboard/{$userId}/promedios");
    }

    public function personCategories(int $evaluationId, int $userId): E360Response
    {
        return $this->client->tenant('GET', "/api/evaluaciones/{$evaluationId}/dashboard/{$userId}/categorias");
    }

    public function commentsReceived(int $evaluationId, int $userId): E360Response
    {
        return $this->client->tenant('GET', "/api/evaluaciones/{$evaluationId}/dashboard/{$userId}/comentarios-recibidos");
    }

    public function commentsSent(int $evaluationId, int $userId): E360Response
    {
        return $this->client->tenant('GET', "/api/evaluaciones/{$evaluationId}/dashboard/{$userId}/comentarios-enviados");
    }

    /** Detalle completo de una participación, pregunta por pregunta. */
    public function participantDetail(int $evaluationId, int $userId): E360Response
    {
        return $this->client->tenant('GET', "/api/evaluaciones/{$evaluationId}/participantes/{$userId}/detalle");
    }

    // -- Detalle por categoría y pregunta -------------------------------

    public function detailsByCategory(int $evaluationId): E360Response
    {
        return $this->client->tenant('GET', "/api/evaluaciones/{$evaluationId}/detalle/categorias");
    }

    public function questionDetails(int $evaluationId, int $questionId, array $query = []): E360Response
    {
        return $this->client->tenant('GET', "/api/evaluaciones/{$evaluationId}/preguntas/{$questionId}/detalles", $query);
    }

    // -- Monitoreo ------------------------------------------------------

    public function monitorMetrics(int $evaluationId): E360Response
    {
        return $this->client->tenant('GET', "/api/evaluaciones/{$evaluationId}/monitoreo/metricas");
    }

    public function monitorParticipants(int $evaluationId, array $query = []): E360Response
    {
        return $this->client->tenant('GET', "/api/evaluaciones/{$evaluationId}/monitoreo/participantes", $query);
    }
}
