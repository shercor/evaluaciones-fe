<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\Role;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

/**
 * Una empresa recién instalada: dos cuentas y nada más.
 *
 * Es lo que hace falta para poder entrar y empezar a cargar la nómina de
 * verdad, y ni una fila más. Ni sucursales, ni cargos, ni personas de
 * ejemplo: la importación crea las sucursales y los cargos que no existan, así
 * que sembrarlos de antemano solo ensuciaría la comparación —una sucursal
 * «Casa Matriz» que nadie importó, un cargo que no está en la planilla—.
 *
 * Contra `DatabaseSeeder`, que siembra la empresa de prueba con su organigrama
 * de cuatro niveles, y contra `LargeCompanySeeder`, que siembra 7.245 personas
 * para medir bajo carga. Este es el otro extremo: el punto de partida de un
 * cliente nuevo.
 *
 * Uso:
 *
 *   docker compose exec php php artisan db:seed --class=EmptyCompanySeeder
 */
class EmptyCompanySeeder extends Seeder
{
    private const PASSWORD = 'password';

    public function run(): void
    {
        // Personal de Idea Uno, externo a la empresa. No es evaluable y el
        // padrón de las evaluaciones lo excluye.
        $this->crear([
            'external_code' => 'SU-001',
            'name' => 'Soporte',
            'lastname' => 'Idea Uno',
            'email' => 'super@evaluacion.test',
            'role' => Role::SUPER_ADMIN,
        ]);

        // La administradora del cliente. Es la cuenta con la que se entra a
        // importar la nómina; después conviene cambiarle el correo desde el
        // directorio por el de la persona real.
        $this->crear([
            'external_code' => 'ADM-001',
            'name' => 'Administración',
            'lastname' => 'Cliente',
            'email' => 'admin@cliente.test',
            'role' => Role::ADMIN,
        ]);

        $this->command->newLine();
        $this->command->info('Empresa vacía. Cuentas para entrar — contraseña: '.self::PASSWORD);
        $this->command->table(
            ['Correo', 'Rol'],
            [
                ['super@evaluacion.test', 'Super administrador'],
                ['admin@cliente.test', 'Administrador'],
            ],
        );
        $this->command->line('  El directorio está vacío: la nómina entra por Directorio → Importar nómina.');
    }

    private function crear(array $atributos): void
    {
        $rol = $atributos['role'];
        unset($atributos['role']);

        User::updateOrCreate(
            ['external_code' => $atributos['external_code']],
            $atributos + [
                'role' => $rol->value,
                'password' => Hash::make(self::PASSWORD),
                'active' => true,
                // Cuenta de arranque: entra directo, sin definir contraseña.
                'must_set_password' => false,
                'email_verified_at' => now(),
            ],
        );
    }
}
