<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Evaluation;
use App\Models\EvaluationUser;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Agrupa a los participantes bajo su supervisor.
 *
 * Es la comprobación previa al envío: una evaluación 360 necesita que cada
 * persona tenga a quién evaluar y quién la evalúe. Si el padrón queda en
 * personas sueltas sin jefe ni equipo, el proceso no tiene sentido y no se
 * deja avanzar.
 *
 * Porta `getSupervisorGroups()` de la intranet, que además de agrupar hacía un
 * `array_filter` anidado por cada supervisor —cuadrático sobre el padrón—.
 * Acá se indexa una vez.
 */
class SupervisorGroups
{
    public function __construct(private readonly ParticipantChanges $changes) {}

    /**
     * @return array{
     *     grupos: array<int, array{supervisor: array, integrantes: array<int, array>}>,
     *     huerfanos: array<int, array>,
     *     total_participantes: int
     * }
     */
    public function build(Evaluation $evaluation): array
    {
        // Dos lecturas del mismo padrón, y la distinción importa:
        //
        //  - `$padron` son quienes participan: los que forman los equipos.
        //  - `$todos` incluye además a los excluidos, porque un jefe apartado
        //    del proceso **sigue siendo el jefe de su equipo**. El envío ya lo
        //    contempla: lo manda como `activo: false` para que la API pueda
        //    colgar de él la estructura. Si acá se lo ignorara, su equipo
        //    entero aparecería como suelto y la pantalla propondría echar a
        //    gente que está perfectamente bien.
        $todos = EvaluationUser::query()
            ->where('evaluation_id', $evaluation->id)
            ->with(['user:id,name,lastname', 'jobPosition:id,name', 'branchOffice:id,name'])
            ->get();

        $padron = $todos->where('participate', true);
        $porUsuario = $todos->keyBy('user_id');

        $porSupervisor = $padron
            ->filter(fn (EvaluationUser $p) => $p->supervisor_id !== null
                && $porUsuario->has($p->supervisor_id))
            ->groupBy('supervisor_id');

        $grupos = [];

        foreach ($porSupervisor as $supervisorId => $integrantes) {
            $supervisor = $porUsuario->get($supervisorId);

            $grupos[] = [
                'supervisor' => $this->presentar($supervisor)
                    // La pantalla lo atenúa: encabeza el equipo, pero a él
                    // nadie lo evalúa.
                    + ['participa' => (bool) $supervisor->participate],
                'integrantes' => $integrantes
                    ->map($this->presentar(...))
                    ->sortBy('nombre')
                    ->values()
                    ->all(),
            ];
        }

        usort($grupos, static fn ($a, $b) => strcmp($a['supervisor']['nombre'], $b['supervisor']['nombre']));

        return [
            'grupos' => $grupos,
            'huerfanos' => $this->huerfanos($padron, $porUsuario, $porSupervisor)->values()->all(),
            'total_participantes' => $padron->count(),
            // Jefes que encabezan un equipo sin participar ellos mismos.
            'jefes_excluidos' => collect($grupos)
                ->reject(fn ($g) => $g['supervisor']['participa'])
                ->count(),
        ];
    }

    /**
     * Quiénes quedarían sin evaluar a nadie y sin que nadie los evalúe.
     *
     * La regla de la intranet pregunta si alguien figura como tu jefe, no si
     * ese jefe participa. Con un jefe apartado del proceso eso deja pasar a
     * gente que en los hechos queda tan suelta como un huérfano: su jefe no
     * la evalúa porque no participa, no tiene equipo que la evalúe hacia
     * arriba, y no tiene pares con quienes evaluarse.
     *
     * Acá hace falta **al menos una relación real**:
     *
     *  - que su jefe participe (la evalúa hacia abajo),
     *  - o que tenga gente a cargo (la evalúan hacia arriba),
     *  - o que tenga pares bajo el mismo jefe (se evalúan entre sí).
     *
     * La autoevaluación no cuenta: un 360 donde alguien solo se evalúa a sí
     * mismo no mide nada.
     */
    private function huerfanos(
        Collection $padron,
        Collection $porUsuario,
        Collection $porSupervisor,
    ): Collection {
        return $padron
            ->filter(function (EvaluationUser $p) use ($porUsuario, $porSupervisor) {
                $jefe = $p->supervisor_id !== null ? $porUsuario->get($p->supervisor_id) : null;

                $loEvaluaSuJefe = $jefe !== null && (bool) $jefe->participate;
                $tieneEquipo = $porSupervisor->has($p->user_id);
                // Más de uno bajo el mismo jefe: hay con quién evaluarse.
                $tienePares = $p->supervisor_id !== null
                    && ($porSupervisor->get($p->supervisor_id)?->count() ?? 0) > 1;

                return ! $loEvaluaSuJefe && ! $tieneEquipo && ! $tienePares;
            })
            ->map($this->presentar(...))
            ->sortBy('nombre');
    }

    /**
     * Excluye del proceso a los participantes sueltos.
     *
     * Pasa por la bitácora como cualquier otro cambio: dejarlo afuera abría un
     * agujero por el que se colaban exclusiones imposibles de deshacer.
     *
     * @param  array<int, int>  $userIds
     */
    public function excludeOrphans(Evaluation $evaluation, array $userIds): int
    {
        if ($userIds === []) {
            return 0;
        }

        return DB::transaction(function () use ($evaluation, $userIds) {
            $filas = EvaluationUser::where('evaluation_id', $evaluation->id)
                ->whereIn('user_id', $userIds)
                ->get();

            $this->changes->rememberMany($evaluation, $filas);

            return EvaluationUser::where('evaluation_id', $evaluation->id)
                ->whereIn('user_id', $userIds)
                ->update(['participate' => false]);
        });
    }

    private function presentar(EvaluationUser $fila): array
    {
        return [
            'user_id' => $fila->user_id,
            'nombre' => $fila->user?->fullName() ?? 'Sin nombre',
            'iniciales' => $fila->user?->initials() ?? '?',
            'cargo' => $fila->jobPosition?->name,
            'sucursal' => $fila->branchOffice?->name,
        ];
    }
}
