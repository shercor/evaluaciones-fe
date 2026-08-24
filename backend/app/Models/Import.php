<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Una carga de nómina.
 */
class Import extends Model
{
    public const PENDING = 'pending';
    public const DONE = 'done';
    public const FAILED = 'failed';

    protected $fillable = [
        'user_id', 'filename', 'status',
        'rows_total', 'rows_created', 'rows_updated', 'rows_failed', 'error',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function rows(): HasMany
    {
        return $this->hasMany(ImportRow::class);
    }

    /** Filas que generaron una contraseña para entregar en mano. */
    public function rowsWithPassword(): HasMany
    {
        return $this->hasMany(ImportRow::class)->whereNotNull('temporary_password');
    }
}
