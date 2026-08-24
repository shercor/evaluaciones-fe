<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Evaluation;
use App\Models\EvaluationUser;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Edición del padrón: quién participa y bajo quién.
 *
 * Porta `updateUserParticipation()` y `editParticipantInfo()` de la intranet.
 *
 * Dos diferencias de fondo:
 *
 * 1. **La cascada de supervisados es una consulta recursiva**, no una función
 *    PHP que se llama a sí misma lanzando una consulta por nivel. La versión
 *    original podía quedarse dando vueltas si los datos tenían un ciclo.
 * 2. La jerarquía que se recorre es la del **padrón**, no la del directorio.
 *    Son distintas a propósito: el padrón congela el organigrama al armar el
 *    proceso y puede editarse sin tocar el directorio real.
 */
class ParticipationEditor
{
    /** Tope de niveles, por si los datos traen un ciclo. */
    private const MAX_DEPTH = 50;

    public function __construct(private readonly ParticipantChanges $changes) {}

    /**
     * Cambia si una persona participa.
     *
     * @param  bool  $withSupervisees  arrastrar a toda su cadena de supervisados
     * @return array<int, int>  ids de las personas afectadas
     */
    public function setParticipation(
        Evaluation $evaluation,
        int $userId,
        bool $participate,
        bool $withSupervisees,
    ): array {
        $fila = EvaluationUser::where('evaluation_id', $evaluation->id)
            ->where('user_id', $userId)
            ->first();

        if (! $fila) {
            throw ValidationException::withMessages([
                'user_id' => 'Esa persona no está en el padrón de esta evaluación.',
            ]);
        }

        $afectados = [$userId];

        if ($withSupervisees) {
            $afectados = array_merge($afectados, $this->superviseeIds($evaluation, $userId));
        }

        $afectados = array_values(array_unique($afectados));

        DB::transaction(function () use ($evaluation, $afectados, $participate) {
            $filas = EvaluationUser::where('evaluation_id', $evaluation->id)
                ->whereIn('user_id', $afectados)
                ->get();

            // La bitácora se escribe **antes** de modificar: guarda el estado
            // previo, que es lo que permite deshacer.
            $this->changes->rememberMany($evaluation, $filas);

            EvaluationUser::where('evaluation_id', $evaluation->id)
                ->whereIn('user_id', $afectados)
                ->update(['participate' => $participate]);
        });

        return $afectados;
    }

    /**
     * Cambia cargo, sucursal o supervisor de un participante.
     */
    public function updateDetails(
        Evaluation $evaluation,
        int $userId,
        ?int $branchOfficeId,
        ?int $jobPositionId,
        ?int $supervisorId,
    ): EvaluationUser {
        $fila = EvaluationUser::where('evaluation_id', $evaluation->id)
            ->where('user_id', $userId)
            ->first();

        if (! $fila) {
            throw ValidationException::withMessages([
                'user_id' => 'Esa persona no está en el padrón de esta evaluación.',
            ]);
        }

        if ($supervisorId !== null) {
            if ($supervisorId === $userId) {
                throw ValidationException::withMessages([
                    'supervisor_id' => 'Una persona no puede ser su propio supervisor.',
                ]);
            }

            $supervisorEnPadron = EvaluationUser::where('evaluation_id', $evaluation->id)
                ->where('user_id', $supervisorId)
                ->exists();

            if (! $supervisorEnPadron) {
                throw ValidationException::withMessages([
                    'supervisor_id' => 'El supervisor elegido no está en el padrón de esta evaluación.',
                ]);
            }

            if ($this->wouldCreateCycle($evaluation, $userId, $supervisorId)) {
                throw ValidationException::withMessages([
                    'supervisor_id' => 'No se puede asignar ese supervisor: ya depende de esta persona, '
                        .'así que la asignación crearía un ciclo en el organigrama.',
                ]);
            }
        }

        DB::transaction(function () use ($evaluation, $fila, $branchOfficeId, $jobPositionId, $supervisorId) {
            $this->changes->remember($evaluation, $fila);

            $fila->update([
                'branch_office_id' => $branchOfficeId,
                'job_position_id' => $jobPositionId,
                'supervisor_id' => $supervisorId,
            ]);
        });

        return $fila->fresh(['user', 'branchOffice', 'jobPosition', 'supervisor']);
    }

    /**
     * Toda la cadena de supervisados dentro del padrón, en una sola consulta.
     *
     * @return array<int, int>
     */
    public function superviseeIds(Evaluation $evaluation, int $userId): array
    {
        $filas = DB::select(
            <<<'SQL'
            WITH RECURSIVE cadena (user_id, nivel) AS (
                SELECT user_id, 1
                FROM evaluation_users
                WHERE evaluation_id = ? AND supervisor_id = ?
                UNION
                SELECT eu.user_id, c.nivel + 1
                FROM evaluation_users eu
                INNER JOIN cadena c ON eu.supervisor_id = c.user_id
                WHERE eu.evaluation_id = ? AND c.nivel < ?
            )
            SELECT DISTINCT user_id FROM cadena
            SQL,
            [$evaluation->id, $userId, $evaluation->id, self::MAX_DEPTH],
        );

        return array_map(static fn ($f) => (int) $f->user_id, $filas);
    }

    /**
     * Conteo de supervisados para varias personas de una vez.
     *
     * @param  array<int, int>  $userIds
     * @return array<int, int>
     */
    public function superviseeCounts(Evaluation $evaluation, array $userIds): array
    {
        if ($userIds === []) {
            return [];
        }

        $marcadores = implode(',', array_fill(0, count($userIds), '?'));

        $filas = DB::select(
            <<<SQL
            WITH RECURSIVE cadena (raiz, user_id, nivel) AS (
                SELECT user_id, user_id, 0
                FROM evaluation_users
                WHERE evaluation_id = ? AND user_id IN ({$marcadores})
                UNION
                SELECT c.raiz, eu.user_id, c.nivel + 1
                FROM evaluation_users eu
                INNER JOIN cadena c ON eu.supervisor_id = c.user_id
                WHERE eu.evaluation_id = ? AND c.nivel < ?
            )
            SELECT raiz, COUNT(DISTINCT user_id) - 1 AS total
            FROM cadena
            GROUP BY raiz
            SQL,
            [$evaluation->id, ...$userIds, $evaluation->id, self::MAX_DEPTH],
        );

        $conteos = [];
        foreach ($filas as $fila) {
            $conteos[(int) $fila->raiz] = (int) $fila->total;
        }

        return $conteos;
    }

    /**
     * ¿El supervisor propuesto ya depende de esta persona?
     */
    private function wouldCreateCycle(Evaluation $evaluation, int $userId, int $supervisorId): bool
    {
        $visitados = [];
        $actual = $supervisorId;
        $nivel = 0;

        while ($actual !== null && $nivel++ < self::MAX_DEPTH) {
            if ($actual === $userId) {
                return true;
            }

            if (isset($visitados[$actual])) {
                return false;
            }
            $visitados[$actual] = true;

            $actual = EvaluationUser::where('evaluation_id', $evaluation->id)
                ->where('user_id', $actual)
                ->value('supervisor_id');

            $actual = $actual === null ? null : (int) $actual;
        }

        return false;
    }
}
