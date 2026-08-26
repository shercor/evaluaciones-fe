<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Notifications\EvaluationReminder;
use App\Services\EvaluationAudience;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Notifications\Notification;

/**
 * El recordatorio, solo para los que todavía no terminaron.
 *
 * Mandarlo al padrón entero sería la forma más rápida de que la gente aprenda
 * a ignorar los correos del sistema: quien ya respondió no tiene nada que
 * hacer con un recordatorio.
 *
 * Quién está pendiente sale de la base local y puede estar desactualizado;
 * el porqué y los dos casos en que miente están en
 * [EvaluationAudience::pendientesConCorreo].
 */
class NotifyEvaluationReminder extends NotifyRoster
{
    public static function audiencia(int $evaluationId): Builder
    {
        return EvaluationAudience::pendientesConCorreo($evaluationId);
    }

    public static function sinCorreo(int $evaluationId): int
    {
        return EvaluationAudience::pendientesSinCorreo($evaluationId);
    }

    protected function aviso(): Notification
    {
        return new EvaluationReminder($this->e360Id, $this->nombre);
    }
}
