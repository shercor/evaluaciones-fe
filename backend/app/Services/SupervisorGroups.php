<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Evaluation;
use App\Models\EvaluationUser;
use Illuminate\Support\Collection;

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
    /**
     * @return array{
     *     grupos: array<int, array{supervisor: array, integrantes: array<int, array>}>,
     *     huerfanos: array<int, array>,
     *     total_participantes: int
     * }
     */
    public function build(Evaluation $evaluation): array
    {
        $padron = EvaluationUser::query()
            ->where('evaluation_id', $evaluation->id)
            ->participating()
            ->with(['user:id,name,lastname', 'jobPosition:id,name', 'branchOffice:id,name'])
            ->get();

        $porUsuario = $padron->keyBy('user_id');

        // Un supervisor cuenta como tal solo si él mismo participa: si quedó
        // fuera del padrón, su gente no tiene a quién evaluar hacia arriba.
        $porSupervisor = $padron
            ->filter(fn (EvaluationUser $p) => $p->supervisor_id !== null
                && $porUsuario->has($p->supervisor_id))
            ->groupBy('supervisor_id');

        $grupos = [];

        foreach ($porSupervisor as $supervisorId => $integrantes) {
            $supervisor = $porUsuario->get($supervisorId);

            $grupos[] = [
                'supervisor' => $this->presentar($supervisor),
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
        ];
    }

    /**
     * Quiénes quedarían sin evaluar a nadie y sin ser evaluados.
     *
     * Son los que no tienen supervisor dentro del padrón **y** tampoco tienen
     * gente a cargo dentro del padrón: quedan sueltos.
     */
    private function huerfanos(
        Collection $padron,
        Collection $porUsuario,
        Collection $porSupervisor,
    ): Collection {
        return $padron
            ->filter(function (EvaluationUser $p) use ($porUsuario, $porSupervisor) {
                $tieneJefe = $p->supervisor_id !== null && $porUsuario->has($p->supervisor_id);
                $tieneEquipo = $porSupervisor->has($p->user_id);

                return ! $tieneJefe && ! $tieneEquipo;
            })
            ->map($this->presentar(...))
            ->sortBy('nombre');
    }

    /**
     * Excluye del proceso a los participantes sueltos.
     *
     * @param  array<int, int>  $userIds
     */
    public function excludeOrphans(Evaluation $evaluation, array $userIds): int
    {
        if ($userIds === []) {
            return 0;
        }

        return EvaluationUser::where('evaluation_id', $evaluation->id)
            ->whereIn('user_id', $userIds)
            ->update(['participate' => false]);
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
