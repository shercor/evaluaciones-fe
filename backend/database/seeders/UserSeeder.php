<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\Role;
use App\Models\BranchOffice;
use App\Models\JobPosition;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

/**
 * Cuentas de prueba.
 *
 * Todas quedan activas y con la contraseña «password», sin cambio obligatorio:
 * son para desarrollo, no para producción.
 *
 * El organigrama tiene cuatro niveles a propósito. La cascada de supervisados
 * y la detección de ciclos son de lo más delicado que hay que portar, y con un
 * árbol plano no se pueden probar.
 *
 *   Rodrigo (Gerente General)
 *   ├── Patricia (Jefa de RRHH, administradora)
 *   │   └── Tomás
 *   ├── Marcela (Jefa de Tienda, Norte)
 *   │   └── Camila
 *   │       ├── Javiera
 *   │       └── Diego
 *   └── Andrés (Jefe de Tienda, Sur)
 *       └── Felipe
 *           └── Valentina
 */
class UserSeeder extends Seeder
{
    private const PASSWORD = 'password';

    public function run(): void
    {
        $branches = BranchOffice::pluck('id', 'external_code');
        $positions = JobPosition::pluck('id', 'external_code');

        // Super administrador: personal de Idea Uno, externo a la empresa.
        // No tiene sucursal ni cargo, y el padrón de evaluaciones lo excluye.
        $this->upsert([
            'external_code' => 'SU-001',
            'name' => 'Soporte',
            'lastname' => 'Idea Uno',
            'email' => 'super@evaluacion.test',
            'role' => Role::SUPER_ADMIN,
        ]);

        // Diez personas de la empresa, con su organigrama.
        $people = [
            ['RUT-001', 'Rodrigo', 'Fuentes', 'GG', 'CASA', null, Role::COLLABORATOR],
            ['RUT-002', 'Patricia', 'Soto', 'JRRHH', 'CASA', 'RUT-001', Role::ADMIN],
            ['RUT-003', 'Marcela', 'Rivas', 'JTIEN', 'SUC-NORTE', 'RUT-001', Role::COLLABORATOR],
            ['RUT-004', 'Andrés', 'Lagos', 'JTIEN', 'SUC-SUR', 'RUT-001', Role::COLLABORATOR],
            ['RUT-005', 'Camila', 'Núñez', 'SUPER', 'SUC-NORTE', 'RUT-003', Role::COLLABORATOR],
            ['RUT-006', 'Felipe', 'Cortés', 'SUPER', 'SUC-SUR', 'RUT-004', Role::COLLABORATOR],
            ['RUT-007', 'Javiera', 'Muñoz', 'VEND', 'SUC-NORTE', 'RUT-005', Role::COLLABORATOR],
            ['RUT-008', 'Diego', 'Araya', 'VEND', 'SUC-NORTE', 'RUT-005', Role::COLLABORATOR],
            ['RUT-009', 'Valentina', 'Rojas', 'VEND', 'SUC-SUR', 'RUT-006', Role::COLLABORATOR],
            ['RUT-010', 'Tomás', 'Vergara', 'ADMIN', 'CASA', 'RUT-002', Role::COLLABORATOR],
        ];

        // Primera pasada sin supervisor: no se puede apuntar a una fila que
        // todavía no existe.
        foreach ($people as [$code, $name, $lastname, $position, $branch, , $role]) {
            $this->upsert([
                'external_code' => $code,
                'name' => $name,
                'lastname' => $lastname,
                'email' => $this->email($name, $lastname),
                'role' => $role,
                'branch_office_id' => $branches[$branch] ?? null,
                'job_position_id' => $positions[$position] ?? null,
            ]);
        }

        // Segunda pasada: ya existen todas, se arma la jerarquía.
        $ids = User::pluck('id', 'external_code');

        foreach ($people as [$code, , , , , $supervisorCode]) {
            if ($supervisorCode === null) {
                continue;
            }

            User::where('external_code', $code)->update([
                'supervisor_id' => $ids[$supervisorCode] ?? null,
            ]);
        }
    }

    private function upsert(array $attributes): void
    {
        $role = $attributes['role'];
        unset($attributes['role']);

        User::updateOrCreate(
            ['external_code' => $attributes['external_code']],
            $attributes + [
                'role' => $role->value,
                'password' => Hash::make(self::PASSWORD),
                'active' => true,
                // Cuentas de prueba: entran directo, sin definir contraseña.
                'must_set_password' => false,
                'email_verified_at' => now(),
            ],
        );
    }

    /**
     * Correo de prueba, sin tildes ni eñes para que sea una dirección válida.
     */
    private function email(string $name, string $lastname): string
    {
        $slug = fn (string $v) => mb_strtolower(
            (string) preg_replace('/[^A-Za-z]/', '', iconv('UTF-8', 'ASCII//TRANSLIT', $v))
        );

        return $slug($name).'.'.$slug($lastname).'@empresa.test';
    }
}
