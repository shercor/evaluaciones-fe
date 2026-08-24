<?php

declare(strict_types=1);

namespace App\Support\E360\Resources;

use App\Support\E360\E360Client;
use App\Support\E360\E360Response;

/**
 * Tareas de evaluación: lo que cada persona tiene que responder.
 *
 * Todos los métodos reciben el id de la persona porque la API lo exige en la
 * ruta. **Ese id siempre sale de la sesión**, nunca de un parámetro que mande
 * el navegador: si viniera de afuera, cualquiera podría responder —o leer— las
 * evaluaciones de otro.
 */
class TasksApi
{
    public function __construct(private readonly E360Client $client) {}

    /** Tareas de una persona dentro de una evaluación. */
    public function forParticipant(int $evaluationId, int $userId): E360Response
    {
        return $this->client->tenant(
            'GET',
            "/api/evaluaciones/{$evaluationId}/participante/{$userId}/tareas",
        );
    }

    /** Preguntas de una tarea concreta. */
    public function questions(int $taskId, int $userId): E360Response
    {
        return $this->client->tenant('GET', "/api/tareas/{$taskId}/participante/{$userId}/preguntas");
    }

    /**
     * Guarda las respuestas de una tarea.
     *
     * @param  array<int, array{pregunta_id:int, respuesta:mixed}>  $answers
     */
    public function saveAnswers(int $taskId, int $userId, array $answers): E360Response
    {
        return $this->client->tenant(
            'POST',
            "/api/tareas/{$taskId}/participante/{$userId}/respuestas",
            body: ['respuestas' => $answers],
        );
    }

    /** La evaluación que está abierta ahora mismo, si hay alguna. */
    public function ongoingEvaluation(): E360Response
    {
        return $this->client->tenant('GET', '/api/evaluaciones/en-curso');
    }

    /** ¿Participa esta persona en esa evaluación? */
    public function participation(int $evaluationId, int $userId): E360Response
    {
        return $this->client->tenant('GET', "/api/evaluaciones/{$evaluationId}/participantes/{$userId}");
    }
}
