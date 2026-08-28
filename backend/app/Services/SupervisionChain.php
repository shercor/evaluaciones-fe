<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Recorridos sobre el organigrama.
 *
 * La jerarquía es `users.supervisor_id` apuntando a la misma tabla. Un ciclo
 * ahí —alguien que termina siendo su propio jefe— cuelga cualquier recorrido
 * recursivo, así que todo lo de acá está escrito para tolerarlos.
 *
 * En la intranet esto vivía repartido en `wouldCreateCycle()` y
 * `getAllSubordinates()`. La segunda lanzaba una consulta por nodo, se llamaba
 * dentro del bucle de la página de participantes, y no se protegía de ciclos:
 * con datos malos entraba en recursión infinita. Acá cada recorrido es **una
 * sola consulta recursiva** con tope de profundidad.
 */
class SupervisionChain
{
    /**
     * Tope de niveles. Ninguna empresa real tiene una cadena de mando más
     * larga; si se alcanza, es que hay un ciclo.
     */
    private const MAX_DEPTH = 50;

    /**
     * ¿Asignar este supervisor crearía un ciclo?
     *
     * Sube por la cadena del supervisor propuesto buscando a la persona. Si
     * aparece, es que la persona ya es jefe suyo —directa o indirectamente— y
     * el movimiento la convertiría en su propio superior.
     */
    public function wouldCreateCycle(int $userId, ?int $newSupervisorId): bool
    {
        if ($newSupervisorId === null) {
            return false;
        }

        // Ser supervisor de uno mismo es el ciclo más corto posible.
        if ($userId === $newSupervisorId) {
            return true;
        }

        $visited = [];
        $current = $newSupervisorId;
        $depth = 0;

        while ($current !== null && $depth++ < self::MAX_DEPTH) {
            if ($current === $userId) {
                return true;
            }

            // Ya hay un ciclo en los datos, aguas arriba. No es el que se está
            // por crear, pero hay que cortar igual para no quedar dando vueltas.
            if (isset($visited[$current])) {
                return false;
            }
            $visited[$current] = true;

            $current = User::whereKey($current)->value('supervisor_id');
        }

        return false;
    }

    /**
     * IDs de toda la cadena de supervisados, no solo los directos.
     *
     * Una única consulta recursiva. `DISTINCT` en el paso recursivo evita que
     * un ciclo en los datos multiplique filas sin fin, y el tope de
     * profundidad es el cinturón de seguridad.
     *
     * @return array<int, int>
     */
    public function allSuperviseeIds(int $supervisorId): array
    {
        $rows = DB::select(
            <<<'SQL'
            WITH RECURSIVE cadena (id, nivel) AS (
                SELECT id, 1 FROM users WHERE supervisor_id = ?
                UNION
                SELECT u.id, c.nivel + 1
                FROM users u
                INNER JOIN cadena c ON u.supervisor_id = c.id
                WHERE c.nivel < ?
            )
            SELECT DISTINCT id FROM cadena
            SQL,
            [$supervisorId, self::MAX_DEPTH],
        );

        return array_map(static fn ($row) => (int) $row->id, $rows);
    }

    /**
     * Cuántas personas dependen de alguien, contando toda la cadena.
     */
    public function countSupervisees(int $supervisorId): int
    {
        return count($this->allSuperviseeIds($supervisorId));
    }

    /**
     * Conteo de supervisados para varias personas de una vez.
     *
     * Es lo que necesita el listado del directorio: pedirlo persona por
     * persona sería el mismo N+1 que tenía la intranet.
     *
     * @param  array<int, int>  $supervisorIds
     * @return array<int, int> id de la persona => cantidad de supervisados
     */
    public function countSuperviseesFor(array $supervisorIds): array
    {
        if ($supervisorIds === []) {
            return [];
        }

        $marcadores = implode(',', array_fill(0, count($supervisorIds), '?'));

        $rows = DB::select(
            <<<SQL
            WITH RECURSIVE cadena (raiz, id, nivel) AS (
                SELECT id, id, 0 FROM users WHERE id IN ({$marcadores})
                UNION
                SELECT c.raiz, u.id, c.nivel + 1
                FROM users u
                INNER JOIN cadena c ON u.supervisor_id = c.id
                WHERE c.nivel < ?
            )
            SELECT raiz, COUNT(DISTINCT id) - 1 AS total
            FROM cadena
            GROUP BY raiz
            SQL,
            [...$supervisorIds, self::MAX_DEPTH],
        );

        $conteos = [];
        foreach ($rows as $row) {
            $conteos[(int) $row->raiz] = (int) $row->total;
        }

        return $conteos;
    }
}
