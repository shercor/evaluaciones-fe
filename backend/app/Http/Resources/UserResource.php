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
            'must_set_password' => $this->must_set_password,
            'avatar_url' => $this->avatar_path ? asset('storage/'.$this->avatar_path) : null,
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
