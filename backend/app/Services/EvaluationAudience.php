<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

/**
 * A quiénes les toca cada aviso de un proceso, y a cuántos no se les puede
 * mandar.
 *
 * Los tres avisos —apertura, recordatorio y resultados— apuntan a subconjuntos
 * del mismo padrón, y la consulta es idéntica salvo por el filtro. Estaba
 * copiada dentro del job de apertura; con tres jobs copiándola, arreglar el
 * `join` habría que hacerlo tres veces.
 *
 * Todo sale de la base local. Es una decisión con consecuencias que conviene
 * tener presentes: ver la nota sobre `tasks_completed` en [pendientesConCorreo].
 */
class EvaluationAudience
{
    /**
     * Todo el padrón que participa y tiene dónde recibir el correo.
     *
     * Es la audiencia de la apertura y la de los resultados: en un proceso de
     * 360 grados a cada participante lo evalúan otros, así que todos tienen
     * resultados que mirar, hayan respondido o no.
     */
    public static function conCorreo(int $evaluationId): Builder
    {
        return self::conCasilla(self::padron($evaluationId));
    }

    /**
     * Los que además todavía no terminaron sus tareas.
     *
     * `tasks_completed` es una **caché**: se refresca cuando la persona abre
     * su lista de tareas, no cuando responde en Evaluación 360. En el camino
     * normal alcanza, porque al guardar la última respuesta el portal vuelve a
     * la lista y la refresca. Los dos casos en que miente son:
     *
     * - quien nunca entró al portal figura como pendiente aunque no tenga
     *   ninguna tarea asignada;
     * - quien respondió por fuera del portal —`dev:responder`, por ejemplo—
     *   sigue figurando como pendiente.
     *
     * Por eso el texto del recordatorio no acusa a nadie: dice que *figuran*
     * tareas sin responder y que si ya terminó puede ignorarlo. La alternativa
     * —preguntarle a Evaluación 360 por cada persona— son cientos de
     * peticiones remotas por envío.
     */
    public static function pendientesConCorreo(int $evaluationId): Builder
    {
        return self::conCasilla(self::pendientes($evaluationId));
    }

    /** Del padrón entero, cuántos participan y no tienen casilla. */
    public static function sinCorreo(int $evaluationId): int
    {
        return self::sinCasilla(self::padron($evaluationId))->count();
    }

    /** De los que quedan pendientes, cuántos no tienen casilla. */
    public static function pendientesSinCorreo(int $evaluationId): int
    {
        return self::sinCasilla(self::pendientes($evaluationId))->count();
    }

    // -----------------------------------------------------------------

    /**
     * El padrón que participa.
     *
     * Va como `join` y no como `whereHas` porque el resultado se recorre con
     * `chunkById` sobre `users.id`: hace falta que las dos tablas estén en la
     * misma consulta.
     */
    private static function padron(int $evaluationId): Builder
    {
        return User::query()
            ->join('evaluation_users', 'evaluation_users.user_id', '=', 'users.id')
            ->where('evaluation_users.evaluation_id', $evaluationId)
            ->where('evaluation_users.participate', true)
            ->select('users.*');
    }

    private static function pendientes(int $evaluationId): Builder
    {
        return self::padron($evaluationId)
            ->where('evaluation_users.tasks_completed', false);
    }

    /**
     * Qué cuenta como «tiene correo» lo decide el modelo, no esta clase: la
     * casilla inventada por el importador parece un correo y no lo es.
     */
    private static function conCasilla(Builder $consulta): Builder
    {
        return $consulta->withMailbox();
    }

    private static function sinCasilla(Builder $consulta): Builder
    {
        return $consulta->withoutMailbox();
    }
}
