<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * El resultado de una fila de la planilla.
 *
 * `line` es la línea del archivo, contando el encabezado. La excepción es la
 * baja por ausencia, que no sale de ninguna línea —sale justamente de que no
 * hay ninguna— y va con `line = 0`.
 */
class ImportRow extends Model
{
    public const CREATED = 'created';

    public const UPDATED = 'updated';

    public const FAILED = 'failed';

    /**
     * La persona quedó inactiva. Dos caminos llegan acá: la planilla la trae
     * marcada como inactiva, o no la trae en absoluto y se pidió sincronizar.
     * En el segundo caso la fila no sale de ninguna línea del archivo y `line`
     * va en cero.
     */
    public const DEACTIVATED = 'deactivated';

    /**
     * No había nada que hacer. Es el caso de una nómina completa que arrastra
     * a los egresados de los últimos diez años: vienen marcados inactivos y
     * nunca estuvieron en el directorio, así que no se crean para darlos de
     * baja acto seguido. No es un error y no se cuenta como rechazo.
     */
    public const SKIPPED = 'skipped';

    protected $fillable = ['import_id', 'line', 'outcome', 'payload', 'error', 'temporary_password'];

    protected function casts(): array
    {
        return ['payload' => 'array'];
    }

    public function import(): BelongsTo
    {
        return $this->belongsTo(Import::class);
    }
}
