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

    /**
     * El dominio de las casillas inventadas por el importador para quien no
     * tiene correo. No existe: nada que se mande ahí llega a ninguna parte.
     */
    public const INTERNAL_MAIL_DOMAIN = '@interno.local';

    /**
     * Por qué una persona quedó inactiva.
     *
     * La baja del directorio es `active = false`, no un borrado: una persona
     * borrada se lleva por delante su historial de evaluaciones, la jefatura
     * de quienes le reportan y las respuestas que dio. Estos motivos son lo
     * que permite contestar «¿y por qué está inactiva?» sin adivinar, y sobre
     * todo distinguir la baja que decidió una persona de la que decidió una
     * importación.
     */
    public const BAJA_AUSENTE = 'ausente_en_origen';

    public const BAJA_INACTIVA_EN_ORIGEN = 'inactiva_en_origen';

    public const BAJA_MANUAL = 'manual';

    /** @var array<string, string> */
    public const MOTIVOS_DE_BAJA = [
        self::BAJA_AUSENTE => 'No vino en la última nómina importada',
        self::BAJA_INACTIVA_EN_ORIGEN => 'La nómina la trae marcada como inactiva',
        self::BAJA_MANUAL => 'Dada de baja a mano desde el directorio',
    ];

    protected $fillable = [
        'external_code',
        'name',
        'lastname',
        'email',
        'password',
        'role',
        'active',
        'deactivated_at',
        'deactivated_reason',
        'deactivated_import_id',
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
            'deactivated_at' => 'datetime',
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

    /**
     * A quiénes puede dar de baja una sincronización de nómina.
     *
     * Tres exclusiones, y ninguna es un escrúpulo:
     *
     *  - **Los administradores.** No vienen en la nómina de Recursos Humanos
     *    —o vienen, pero su cuenta se creó a mano— y desactivarlos deja el
     *    sistema sin nadie que pueda volver a entrar a arreglarlo. Es la
     *    forma más rápida de que una importación distraída cierre la puerta
     *    con la llave adentro.
     *  - **Quien no tiene código interno.** Si nunca entró por una planilla,
     *    ninguna planilla puede decidir que se fue.
     *  - **Quien ya está inactivo.** No hay nada que dar de baja, y volver a
     *    escribirle la fecha borraría cuándo se fue de verdad.
     *
     * Quien ejecuta la importación se excluye aparte, en el importador: acá
     * no se sabe quién es.
     */
    public function scopeDeactivatableByPayroll(Builder $query): Builder
    {
        return $query->where('active', true)
            ->whereNotNull('external_code')
            ->where('external_code', '!=', '')
            ->whereNotIn('role', [Role::ADMIN->value, Role::SUPER_ADMIN->value]);
    }

    /**
     * Quienes tienen una casilla real, a la que se le puede escribir.
     *
     * `email` es NOT NULL y único, así que a quien no tiene correo el
     * importador le inventa uno interno —`sin-correo.{codigo}@interno.local`—
     * solo para no romper esa unicidad. Nunca hay que mandarle nada ahí: es
     * un dominio que no existe.
     *
     * Antes esta regla vivía suelta dentro de `resendInvitation()` y los
     * avisos por correo no la conocían: contaban esas casillas inventadas como
     * destinatarios válidos y les despachaban correo.
     */
    public function scopeWithMailbox(Builder $query): Builder
    {
        return $query->whereNotNull($this->getTable().'.email')
            ->where($this->getTable().'.email', '!=', '')
            ->where($this->getTable().'.email', 'not like', '%'.self::INTERNAL_MAIL_DOMAIN);
    }

    /** Los del caso contrario: participan, pero no hay dónde escribirles. */
    public function scopeWithoutMailbox(Builder $query): Builder
    {
        return $query->where(fn (Builder $q) => $q
            ->whereNull($this->getTable().'.email')
            ->orWhere($this->getTable().'.email', '=', '')
            ->orWhere($this->getTable().'.email', 'like', '%'.self::INTERNAL_MAIL_DOMAIN));
    }

    // -- Ayudas -------------------------------------------------------

    /** La versión de [scopeWithMailbox] para una fila ya cargada. */
    public function hasMailbox(): bool
    {
        return ! blank($this->email)
            && ! str_ends_with($this->email, self::INTERNAL_MAIL_DOMAIN);
    }

    /**
     * Da de baja sin borrar.
     *
     * Vive en el modelo y no en el importador porque la baja se decide desde
     * dos lugares —la sincronización de la nómina y el botón del directorio—
     * y las tres columnas tienen que quedar coherentes en los dos casos. Con
     * la regla escrita dos veces, el día que se agregue una cuarta columna
     * una de las dos se va a olvidar.
     */
    public function deactivate(string $reason, ?int $importId = null): void
    {
        $this->forceFill([
            'active' => false,
            'deactivated_at' => now(),
            'deactivated_reason' => $reason,
            'deactivated_import_id' => $importId,
        ])->save();
    }

    /**
     * Reincorpora a quien había quedado de baja.
     *
     * Limpia el motivo y la fecha a propósito: quien vuelve a la nómina vuelve
     * sin historia de baja encima, y dejar `deactivated_at` puesto en alguien
     * activo es la clase de dato contradictorio que después nadie sabe leer.
     */
    public function reactivate(): void
    {
        $this->forceFill([
            'active' => true,
            'deactivated_at' => null,
            'deactivated_reason' => null,
            'deactivated_import_id' => null,
        ])->save();
    }

    /** Cómo se le explica a una persona por qué esta otra está inactiva. */
    public function deactivationReason(): ?string
    {
        if ($this->active) {
            return null;
        }

        return self::MOTIVOS_DE_BAJA[$this->deactivated_reason] ?? null;
    }

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
     * Dirección pública de la foto de perfil, o `null` si no tiene.
     *
     * Sale del modelo y no de cada consulta porque la foto se muestra en seis
     * pantallas, y con la regla escrita en un solo lugar no puede pasar que
     * una arme la ruta distinto que otra.
     */
    public function avatarUrl(): ?string
    {
        return $this->avatar_path ? asset('storage/'.$this->avatar_path) : null;
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
