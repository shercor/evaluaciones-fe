<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Evaluation;
use App\Models\EvaluationUser;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Arma y mantiene el padrón de una evaluación.
 *
 * Porta `updatePersonalEvaluationUsers()` de la intranet, con sus reglas
 * intactas pero sin sus dos problemas: allá recorría los usuarios uno por uno
 * consultando el supervisor de cada uno —una consulta por persona— y necesitaba
 * subir el límite de tiempo a 300 segundos y la memoria a 512 MB para
 * sobrevivir a una nómina grande.
 */
class ParticipantRoster
{
    /** Cuántas filas por `INSERT`. Más alto revienta el paquete de MySQL. */
    private const LOTE = 500;

    /**
     * Copia al padrón las personas de las sucursales elegidas.
     *
     * Reglas portadas de la intranet:
     *
     *  - Se excluyen los super administradores y las cuentas inactivas.
     *  - Si el supervisor de alguien no entra al padrón, se le deja supervisor
     *    nulo: apuntar a alguien que no participa rompería los grupos.
     *  - A quien ya estaba y ya no corresponde se lo marca `participate = false`
     *    en vez de borrarlo, para no perder su historial de cambios.
     *
     * @param  array<int, int|null>  $branchOfficeIds  null representa «Sin Sucursal»
     * @return array{creados:int, actualizados:int, excluidos:int}
     */
    public function rebuild(Evaluation $evaluation, array $branchOfficeIds): array
    {
        $incluyeSinSucursal = in_array(null, $branchOfficeIds, true);
        $sucursales = array_values(array_filter($branchOfficeIds, static fn ($id) => $id !== null));

        // Una sola consulta para toda la nómina que corresponde.
        $elegibles = User::query()
            ->evaluable()
            ->where(function ($q) use ($sucursales, $incluyeSinSucursal) {
                if ($sucursales !== []) {
                    $q->whereIn('branch_office_id', $sucursales);
                }
                if ($incluyeSinSucursal) {
                    $q->orWhereNull('branch_office_id');
                }
                // Sin sucursales y sin «sin sucursal» no califica nadie.
                if ($sucursales === [] && ! $incluyeSinSucursal) {
                    $q->whereRaw('1 = 0');
                }
            })
            ->get(['id', 'branch_office_id', 'job_position_id', 'supervisor_id']);

        $idsElegibles = $elegibles->pluck('id')->all();

        // Los supervisores válidos son los que quedan dentro del padrón. En la
        // intranet esto se comprobaba consultando la tabla `users` por cada
        // persona, y solo miraba si el supervisor estaba activo —no si
        // participaba—, con lo que podían quedar apuntando fuera del proceso.
        $idsElegiblesIndex = array_flip($idsElegibles);

        $creados = 0;
        $actualizados = 0;

        DB::transaction(function () use (
            $evaluation, $elegibles, $idsElegiblesIndex, &$creados, &$actualizados
        ) {
            $existentes = EvaluationUser::where('evaluation_id', $evaluation->id)
                ->pluck('user_id')
                ->flip();

            $ahora = now();
            $filas = [];

            foreach ($elegibles as $persona) {
                $supervisorId = $persona->supervisor_id;

                if ($supervisorId !== null && ! isset($idsElegiblesIndex[$supervisorId])) {
                    $supervisorId = null;
                }

                $filas[] = [
                    'evaluation_id' => $evaluation->id,
                    'user_id' => $persona->id,
                    'participate' => true,
                    'branch_office_id' => $persona->branch_office_id,
                    'job_position_id' => $persona->job_position_id,
                    'supervisor_id' => $supervisorId,
                    'created_at' => $ahora,
                    'updated_at' => $ahora,
                ];

                $existentes->has($persona->id) ? $actualizados++ : $creados++;
            }

            // Por lotes y no fila por fila. Con un padrón de 7.000 personas,
            // una consulta por cabeza son 7.000 viajes a la base: acá se
            // midió en 3 segundos con la base en la misma máquina, y con la
            // base en otro servidor cada viaje suma latencia de red.
            foreach (array_chunk($filas, self::LOTE) as $lote) {
                EvaluationUser::upsert(
                    $lote,
                    ['evaluation_id', 'user_id'],
                    ['participate', 'branch_office_id', 'job_position_id', 'supervisor_id', 'updated_at'],
                );
            }

            // Quien ya no corresponde queda excluido, no borrado.
            $sobrantes = $existentes->keys()->diff(array_keys($idsElegiblesIndex));

            if ($sobrantes->isNotEmpty()) {
                EvaluationUser::where('evaluation_id', $evaluation->id)
                    ->whereIn('user_id', $sobrantes->all())
                    ->update(['participate' => false, 'supervisor_id' => null]);
            }
        });

        $excluidos = EvaluationUser::where('evaluation_id', $evaluation->id)
            ->where('participate', false)
            ->count();

        return compact('creados', 'actualizados', 'excluidos');
    }

    /**
     * Guarda qué sucursales se eligieron.
     *
     * @param  array<int, int|null>  $branchOfficeIds
     */
    public function setBranchOffices(Evaluation $evaluation, array $branchOfficeIds): void
    {
        DB::transaction(function () use ($evaluation, $branchOfficeIds) {
            DB::table('evaluation_branch_offices')
                ->where('evaluation_id', $evaluation->id)
                ->delete();

            $filas = [];
            foreach (array_unique($branchOfficeIds, SORT_REGULAR) as $id) {
                $filas[] = ['evaluation_id' => $evaluation->id, 'branch_office_id' => $id];
            }

            if ($filas !== []) {
                DB::table('evaluation_branch_offices')->insert($filas);
            }
        });
    }

    /**
     * @return array<int, int|null>  ids elegidos; null significa «Sin Sucursal»
     */
    public function branchOfficeIds(Evaluation $evaluation): array
    {
        return DB::table('evaluation_branch_offices')
            ->where('evaluation_id', $evaluation->id)
            ->pluck('branch_office_id')
            ->map(static fn ($id) => $id === null ? null : (int) $id)
            ->all();
    }

    /**
     * Sucursales que se pueden elegir, con su dotación.
     *
     * Las que no tienen a nadie no se ofrecen: elegirlas no aportaría a nadie
     * al padrón. Se agrega siempre la opción «Sin Sucursal» si hay personas en
     * esa condición.
     *
     * @return array<int, array{id:int|null, name:string, staff_count:int}>
     */
    public function availableBranchOffices(): array
    {
        $conteos = User::query()
            ->evaluable()
            ->selectRaw('branch_office_id, COUNT(*) as total')
            ->groupBy('branch_office_id')
            ->pluck('total', 'branch_office_id');

        $opciones = [];

        $sucursales = \App\Models\BranchOffice::active()->orderBy('name')->get(['id', 'name']);

        foreach ($sucursales as $sucursal) {
            $total = (int) ($conteos[$sucursal->id] ?? 0);

            if ($total === 0) {
                continue;
            }

            $opciones[] = [
                'id' => $sucursal->id,
                'name' => $sucursal->name,
                'staff_count' => $total,
            ];
        }

        // En la intranet esto era una pseudo-sucursal con id 0, que obligaba a
        // tratarla aparte en cada consulta. Acá es simplemente la ausencia.
        $sinSucursal = (int) ($conteos[''] ?? $conteos[null] ?? 0);

        if ($sinSucursal > 0) {
            $opciones[] = [
                'id' => null,
                'name' => 'Sin sucursal asignada',
                'staff_count' => $sinSucursal,
            ];
        }

        return $opciones;
    }
}
