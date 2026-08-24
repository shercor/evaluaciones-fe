<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\EvaluationStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Espejo local de una evaluación de Evaluación 360.
 *
 * No contiene el título ni las fechas reales: eso se pide a la API cada vez.
 * Guarda el puntero, la selección de sucursales y una caché del estado.
 *
 * @property EvaluationStatus|null $status
 */
class Evaluation extends Model
{
    protected $fillable = ['e360_id', 'name', 'status', 'status_synced_at'];

    protected function casts(): array
    {
        return [
            'status' => EvaluationStatus::class,
            'status_synced_at' => 'datetime',
        ];
    }

    /**
     * Sucursales elegidas para el proceso.
     *
     * Una fila con `branch_office_id` nulo representa «Sin Sucursal»: las
     * personas que no tienen ninguna asignada también participan.
     */
    public function branchOffices(): BelongsToMany
    {
        return $this->belongsToMany(
            BranchOffice::class,
            'evaluation_branch_offices',
            'evaluation_id',
            'branch_office_id',
        );
    }

    /** El padrón: quiénes fueron considerados para este proceso. */
    public function roster(): HasMany
    {
        return $this->hasMany(EvaluationUser::class);
    }

    /** Cambios sin aplicar sobre el padrón. */
    public function pendingChanges(): HasMany
    {
        return $this->hasMany(EvaluationUserChange::class);
    }

    /**
     * ¿Hay cambios en los participantes que todavía no se enviaron?
     *
     * Mientras los haya, abrir y cerrar quedan deshabilitados: el proceso en
     * la API y el padrón local estarían diciendo cosas distintas.
     */
    public function hasPendingChanges(): bool
    {
        return $this->pendingChanges()->exists();
    }

    /** ¿Se incluyó a las personas sin sucursal? */
    public function includesWithoutBranch(): bool
    {
        return $this->branchOffices()
            ->newPivotQuery()
            ->whereNull('branch_office_id')
            ->exists();
    }

    /**
     * Encuentra o crea el espejo de una evaluación de la API.
     *
     * Se llama al listar y al operar: así una evaluación creada por fuera de
     * este portal —o antes de que existiera— igual tiene su fila local.
     */
    public static function forE360(int $e360Id, ?string $name = null): self
    {
        return static::firstOrCreate(
            ['e360_id' => $e360Id],
            ['name' => $name],
        );
    }

    /**
     * Guarda el estado que devolvió la API.
     *
     * Es una caché, no la verdad: nadie decide nada leyendo esta columna sin
     * haber consultado antes. En la intranet esta copia se actualizaba a mano
     * tras cada transición y se desincronizaba cuando alguna fallaba a medias.
     */
    public function syncStatus(?string $status): void
    {
        $nuevo = EvaluationStatus::tryFromLabel($status);

        if ($nuevo === null) {
            return;
        }

        $this->forceFill([
            'status' => $nuevo->value,
            'status_synced_at' => now(),
        ])->save();
    }
}
