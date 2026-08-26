<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Notifications\ResultsPublished;
use App\Services\EvaluationAudience;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Notifications\Notification;

/**
 * El aviso de resultados publicados, para todo el padrón.
 *
 * Va a todos y no solo a quienes respondieron: en un proceso de 360 grados a
 * cada participante lo evalúan otros, así que todos tienen un informe que
 * mirar aunque ellos no hayan completado sus tareas.
 */
class NotifyResultsPublished extends NotifyRoster
{
    public static function audiencia(int $evaluationId): Builder
    {
        return EvaluationAudience::conCorreo($evaluationId);
    }

    public static function sinCorreo(int $evaluationId): int
    {
        return EvaluationAudience::sinCorreo($evaluationId);
    }

    protected function aviso(): Notification
    {
        return new ResultsPublished($this->e360Id, $this->nombre);
    }
}
