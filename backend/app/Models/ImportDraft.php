<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Una planilla subida que espera a que le digan qué columna es cuál.
 *
 * `destino` es para qué se subió —«nomina», «sucursales» o «cargos»—: se
 * elige al subirla y no se vuelve a preguntar.
 */
class ImportDraft extends Model
{
    protected $fillable = [
        'user_id', 'filename', 'destino', 'stored_path', 'headers', 'samples', 'rows_total',
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
