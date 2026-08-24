<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            DirectorySeeder::class,
            UserSeeder::class,
        ]);

        $this->command->newLine();
        $this->command->info('Cuentas de prueba — contraseña: password');
        $this->command->table(
            ['Correo', 'Rol'],
            [
                ['super@evaluacion.test', 'Super administrador'],
                ['patricia.soto@empresa.test', 'Administradora'],
                ['rodrigo.fuentes@empresa.test', 'Colaborador (Gerente General)'],
                ['…otros 8 colaboradores', 'ver database/seeders/UserSeeder.php'],
            ],
        );
    }
}
