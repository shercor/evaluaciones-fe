<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Una persona dentro del padrón de una evaluación.
 *
 * La sucursal, el cargo y el supervisor son una **copia congelada** del
 * organigrama al armar el proceso, no un reflejo de `users`.
 */
class EvaluationUser extends Model
{
    protected $fillable = [
        'evaluation_id', 'user_id', 'participate', 'tasks_completed',
        'branch_office_id', 'job_position_id', 'supervisor_id',
    ];

    protected function casts(): array
    {
        return [
            'participate' => 'boolean',
            'tasks_completed' => 'boolean',
        ];
    }

    public function evaluation(): BelongsTo
    {
        return $this->belongsTo(Evaluation::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function supervisor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'supervisor_id');
    }

    public function branchOffice(): BelongsTo
    {
        return $this->belongsTo(BranchOffice::class);
    }

    public function jobPosition(): BelongsTo
    {
        return $this->belongsTo(JobPosition::class);
    }

    public function scopeParticipating(Builder $query): Builder
    {
        return $query->where('participate', true);
    }
}
