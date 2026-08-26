<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Una planilla subida que espera a que le digan qué columna es cuál.
 */
class ImportDraft extends Model
{
    protected $fillable = [
        'user_id', 'filename', 'stored_path', 'headers', 'samples', 'rows_total',
    ];

    protected $casts = [
        'headers' => 'array',
        'samples' => 'array',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
