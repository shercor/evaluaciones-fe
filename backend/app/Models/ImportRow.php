<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * El resultado de una fila de la planilla.
 */
class ImportRow extends Model
{
    public const CREATED = 'created';
    public const UPDATED = 'updated';
    public const FAILED = 'failed';

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
