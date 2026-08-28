<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * La persona tal como la ve Angular.
 *
 * Expone solo lo que el SPA necesita para decidir qué mostrar. Nada de
 * contraseñas ni de credenciales de Evaluación 360.
 *
 * @mixin User
 */
class UserResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'lastname' => $this->lastname,
            'full_name' => $this->fullName(),
            'initials' => $this->initials(),
            'email' => $this->email,
            'role' => $this->role->value,
            'role_label' => $this->role->label(),
            'is_administrative' => $this->role->isAdministrative(),
            'active' => $this->active,
            // Por qué está inactiva. Desde que la nómina sincroniza bajas, una
            // persona puede quedar inactiva sin que nadie haya pulsado nada, y
            // «Inactiva» a secas manda a buscar en el historial de cargas.
            'deactivation_reason' => $this->deactivationReason(),
            'deactivated_at' => $this->deactivated_at?->toIso8601String(),
            'must_set_password' => $this->must_set_password,
            'avatar_url' => $this->avatarUrl(),
            'branch_office' => $this->whenLoaded('branchOffice', fn () => [
                'id' => $this->branchOffice->id,
                'name' => $this->branchOffice->name,
            ]),
            'job_position' => $this->whenLoaded('jobPosition', fn () => [
                'id' => $this->jobPosition->id,
                'name' => $this->jobPosition->name,
            ]),
            'supervisor' => $this->whenLoaded('supervisor', fn () => $this->supervisor ? [
                'id' => $this->supervisor->id,
                'full_name' => $this->supervisor->fullName(),
            ] : null),
        ];
    }
}
