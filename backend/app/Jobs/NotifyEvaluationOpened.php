<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Notifications\EvaluationOpened;
use App\Services\EvaluationAudience;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Notifications\Notification;

/**
 * El aviso de apertura, para todo el padrón.
 *
 * A quien no tiene correo se lo omite: el administrador ya fue advertido de
 * cuántos son al abrir el proceso.
 */
class NotifyEvaluationOpened extends NotifyRoster
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
        return new EvaluationOpened($this->e360Id, $this->nombre);
    }
}
