<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

/**
 * Coincidencias de personas para el buscador con sugerencias del frontend.
 *
 * Devuelve exactamente la forma que espera `<app-buscador-personas>`. Cada
 * pantalla arma el conjunto donde buscar —el padrón de un proceso, quienes
 * supervisan a alguien, la nómina activa— y esta clase se ocupa de lo que es
 * igual en todas: cómo se compara el término y cuánto viaja.
 */
class PersonSuggestions
{
    /**
     * Cuántas se devuelven como mucho.
     *
     * Es la razón de ser de todo esto: la respuesta no crece con la nómina. Si
     * hay más coincidencias, se afina escribiendo. El frontend usa el mismo
     * número para avisar que la lista está recortada.
     */
    public const TOPE = 15;

    /**
     * @param  Builder<User>  $consulta  dónde buscar
     * @return array<int, array{id: int, nombre: string, codigo: string|null}>
     */
    public static function para(Builder $consulta, string $buscar, int $tope = self::TOPE): array
    {
        return $consulta
            ->when($buscar !== '', fn (Builder $q) => $q->where(fn (Builder $w) => $w
                ->where('name', 'like', "%{$buscar}%")
                ->orWhere('lastname', 'like', "%{$buscar}%")
                ->orWhere('external_code', 'like', "%{$buscar}%")
                // La gente escribe el nombre completo: sin esto, «ana pérez»
                // no encuentra a nadie, porque ninguna columna contiene esa
                // cadena entera.
                ->orWhereRaw("CONCAT(name, ' ', COALESCE(lastname, '')) LIKE ?", ["%{$buscar}%"])))
            ->orderBy('name')
            ->orderBy('lastname')
            ->limit($tope)
            ->get(['id', 'name', 'lastname', 'external_code'])
            ->map(fn (User $u) => [
                'id' => $u->id,
                'nombre' => $u->fullName(),
                // Va el código porque **hay homónimos**: en el padrón de 7.092
                // personas hay 527 supervisores con solo 434 nombres distintos.
                // Cuatro «Rodrigo Fuentes» seguidos no permiten elegir.
                'codigo' => $u->external_code,
            ])
            ->values()
            ->all();
    }
}
