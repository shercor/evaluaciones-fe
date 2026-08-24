<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\Role;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

/**
 * Una persona del directorio.
 *
 * Igual que en la intranet, persona y credencial son la misma fila: quien
 * responde una evaluación es quien inicia sesión.
 *
 * @property Role $role
 */
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable;

    protected $fillable = [
        'external_code',
        'name',
        'lastname',
        'email',
        'password',
        'role',
        'active',
        'must_set_password',
        'avatar_path',
        'branch_office_id',
        'job_position_id',
        'supervisor_id',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'last_login_at' => 'datetime',
            'password' => 'hashed',
            'role' => Role::class,
            'active' => 'boolean',
            'must_set_password' => 'boolean',
        ];
    }

    // -- Organigrama --------------------------------------------------

    public function supervisor(): BelongsTo
    {
        return $this->belongsTo(self::class, 'supervisor_id');
    }

    /**
     * Supervisados directos. La cadena completa se recorre aparte, con
     * detección de ciclos: acá no se encadena la relación a propósito.
     */
    public function supervisees(): HasMany
    {
        return $this->hasMany(self::class, 'supervisor_id');
    }

    public function branchOffice(): BelongsTo
    {
        return $this->belongsTo(BranchOffice::class);
    }

    public function jobPosition(): BelongsTo
    {
        return $this->belongsTo(JobPosition::class);
    }

    // -- Consultas ----------------------------------------------------

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('active', true);
    }

    /**
     * Quienes pueden ser participantes de una evaluación.
     *
     * Replica el filtro del padrón de la intranet, que excluye a los super
     * administradores y a los inactivos.
     */
    public function scopeEvaluable(Builder $query): Builder
    {
        return $query->where('active', true)
            ->where('role', '!=', Role::SUPER_ADMIN->value);
    }

    // -- Ayudas -------------------------------------------------------

    public function fullName(): string
    {
        return trim($this->name.' '.($this->lastname ?? ''));
    }

    public function isAdministrative(): bool
    {
        return $this->role->isAdministrative();
    }

    public function isSuperAdmin(): bool
    {
        return $this->role === Role::SUPER_ADMIN;
    }

    /**
     * Iniciales para el avatar cuando no hay foto cargada.
     */
    public function initials(): string
    {
        $first = mb_substr($this->name ?? '', 0, 1);
        $last = mb_substr($this->lastname ?? '', 0, 1);

        return mb_strtoupper($first.$last) ?: '?';
    }
}
