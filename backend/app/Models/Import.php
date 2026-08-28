<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Una carga desde planilla: la nómina, las sucursales o los cargos.
 *
 * `destino` dice cuál de las tres, y es lo que separa el historial del
 * directorio —que muestra solo las de nómina— de las cargas de catálogo.
 */
class Import extends Model
{
    public const PENDING = 'pending';

    public const DONE = 'done';

    public const FAILED = 'failed';

    protected $fillable = [
        'user_id', 'filename', 'destino', 'status',
        'rows_total', 'rows_created', 'rows_updated', 'rows_failed',
        'rows_skipped', 'rows_deactivated', 'rows_reactivated', 'error',
        'mapping',
    ];

    protected $casts = [
        'mapping' => 'array',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function rows(): HasMany
    {
        return $this->hasMany(ImportRow::class);
    }

    /** Las bajas de esta carga, para poder revisarlas y revertirlas. */
    public function deactivatedRows(): HasMany
    {
        return $this->hasMany(ImportRow::class)->where('outcome', ImportRow::DEACTIVATED);
    }

    /** Filas que generaron una contraseña para entregar en mano. */
    public function rowsWithPassword(): HasMany
    {
        return $this->hasMany(ImportRow::class)->whereNotNull('temporary_password');
    }
}
