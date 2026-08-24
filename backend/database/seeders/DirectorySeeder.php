<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\BranchOffice;
use App\Models\JobPosition;
use Illuminate\Database\Seeder;

/**
 * Sucursales y cargos de prueba.
 *
 * Datos mínimos para poder armar un organigrama con sentido antes de que
 * exista la importación desde planilla (hito 3).
 */
class DirectorySeeder extends Seeder
{
    public function run(): void
    {
        foreach ([
            ['external_code' => 'CASA', 'name' => 'Casa Matriz'],
            ['external_code' => 'SUC-NORTE', 'name' => 'Sucursal Norte'],
            ['external_code' => 'SUC-SUR', 'name' => 'Sucursal Sur'],
        ] as $branch) {
            BranchOffice::updateOrCreate(
                ['external_code' => $branch['external_code']],
                $branch + ['active' => true],
            );
        }

        foreach ([
            ['external_code' => 'GG', 'name' => 'Gerente General'],
            ['external_code' => 'JRRHH', 'name' => 'Jefe de Recursos Humanos'],
            ['external_code' => 'JTIEN', 'name' => 'Jefe de Tienda'],
            ['external_code' => 'SUPER', 'name' => 'Supervisor'],
            ['external_code' => 'VEND', 'name' => 'Vendedor'],
            ['external_code' => 'ADMIN', 'name' => 'Administrativo'],
        ] as $position) {
            JobPosition::updateOrCreate(
                ['external_code' => $position['external_code']],
                $position + ['active' => true],
            );
        }
    }
}
