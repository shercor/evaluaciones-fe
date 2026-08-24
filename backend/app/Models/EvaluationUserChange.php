<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * El estado anterior de un participante, guardado antes de modificarlo.
 *
 * Solo existe mientras haya cambios sin aplicar: al confirmar el envío se
 * borran, y al deshacer se restauran y se borran.
 */
class EvaluationUserChange extends Model
{
    protected $fillable = [
        'evaluation_id', 'user_id', 'participate',
        'branch_office_id', 'job_position_id', 'supervisor_id',
    ];

    protected function casts(): array
    {
        return ['participate' => 'boolean'];
    }

    public function evaluation(): BelongsTo
    {
        return $this->belongsTo(Evaluation::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
