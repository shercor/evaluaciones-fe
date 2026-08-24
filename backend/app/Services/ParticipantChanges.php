<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\EvaluationStatus;
use App\Models\Evaluation;
use App\Models\EvaluationUser;
use App\Models\EvaluationUserChange;
use Illuminate\Support\Facades\DB;

/**
 * Bitácora para deshacer cambios en el padrón.
 *
 * Con la evaluación ya creada, tocar a un participante desincroniza el padrón
 * local respecto de lo que la API tiene registrado. Hasta que se reenvíe, el
 * proceso queda marcado y **abrir y cerrar quedan deshabilitados**: dejarlos
 * disponibles permitiría abrir un proceso con un padrón que la API no conoce.
 *
 * Se guarda **solo el primer cambio** de cada persona. Lo que interesa para
 * deshacer es el estado original, no la secuencia de pasos intermedios.
 */
class ParticipantChanges
{
    /**
     * ¿Hay que llevar bitácora en este estado?
     *
     * Mientras la evaluación se está creando no hace falta: todavía no se le
     * envió nada a la API, así que no hay nada que desincronizar.
     */
    public function shouldTrack(Evaluation $evaluation): bool
    {
        return $evaluation->status !== null
            && $evaluation->status !== EvaluationStatus::CREATING;
    }

    /**
     * Guarda el estado actual de una persona, si todavía no estaba guardado.
     */
    public function remember(Evaluation $evaluation, EvaluationUser $fila): void
    {
        if (! $this->shouldTrack($evaluation)) {
            return;
        }

        EvaluationUserChange::firstOrCreate(
            ['evaluation_id' => $evaluation->id, 'user_id' => $fila->user_id],
            [
                'participate' => $fila->participate,
                'branch_office_id' => $fila->branch_office_id,
                'job_position_id' => $fila->job_position_id,
                'supervisor_id' => $fila->supervisor_id,
            ],
        );
    }

    /**
     * Guarda varias personas de una vez, para la cascada de supervisados.
     *
     * @param  iterable<EvaluationUser>  $filas
     */
    public function rememberMany(Evaluation $evaluation, iterable $filas): void
    {
        if (! $this->shouldTrack($evaluation)) {
            return;
        }

        foreach ($filas as $fila) {
            $this->remember($evaluation, $fila);
        }
    }

    /**
     * Devuelve el padrón al estado anterior a los cambios y limpia la bitácora.
     *
     * Todo dentro de una transacción: una restauración a medias dejaría el
     * padrón peor de como estaba.
     */
    public function undo(Evaluation $evaluation): int
    {
        return DB::transaction(function () use ($evaluation) {
            $cambios = EvaluationUserChange::where('evaluation_id', $evaluation->id)->get();

            foreach ($cambios as $cambio) {
                EvaluationUser::where('evaluation_id', $evaluation->id)
                    ->where('user_id', $cambio->user_id)
                    ->update([
                        'participate' => $cambio->participate,
                        'branch_office_id' => $cambio->branch_office_id,
                        'job_position_id' => $cambio->job_position_id,
                        'supervisor_id' => $cambio->supervisor_id,
                    ]);
            }

            $total = $cambios->count();

            EvaluationUserChange::where('evaluation_id', $evaluation->id)->delete();

            return $total;
        });
    }

    /**
     * Descarta la bitácora sin restaurar nada.
     *
     * Se llama al confirmar el envío: los cambios ya viajaron a la API, así
     * que dejan de estar pendientes.
     */
    public function clear(Evaluation $evaluation): void
    {
        EvaluationUserChange::where('evaluation_id', $evaluation->id)->delete();
    }

    public function count(Evaluation $evaluation): int
    {
        return EvaluationUserChange::where('evaluation_id', $evaluation->id)->count();
    }
}
