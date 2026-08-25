<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\Role;
use App\Models\BranchOffice;
use App\Models\JobPosition;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * Empresa grande, para poner la aplicación bajo carga real.
 *
 * Unas 7.000 personas en 120 sucursales, con un organigrama de seis niveles.
 * No es un volcado de datos al azar: cada rareza está puesta a propósito para
 * ejercitar una parte distinta del sistema —ver `casosLimite()` más abajo—.
 *
 *   Gerente General
 *   └── 6 gerencias de división
 *       └── 24 jefaturas zonales
 *           └── 120 jefaturas de tienda (una por sucursal)
 *               └── ~350 supervisores
 *                   └── ~6.500 personas de piso
 *
 * Es **aditivo y repetible**: todo lo suyo lleva el prefijo `X-` en el código
 * interno y el dominio `@corp.test` en el correo, así que se borra solo antes
 * de sembrar y no toca las cuentas de `UserSeeder`.
 *
 * Cómo usarlo:
 *
 *     php artisan db:seed --class=LargeCompanySeeder
 *
 * Y para dejarlo como estaba:
 *
 *     php artisan db:seed --class=LargeCompanySeeder --no-interaction  # resiembra
 *     # o a mano:
 *     DELETE FROM users WHERE email LIKE '%@corp.test';
 *     DELETE FROM branch_offices WHERE external_code LIKE 'X-%';
 */
class LargeCompanySeeder extends Seeder
{
    /** Cuánta gente de piso hay por sucursal, como mínimo y como máximo. */
    private const PISO_MIN = 35;
    private const PISO_MAX = 75;

    private const SUCURSALES = 120;

    /** Tamaño de cada `INSERT`. Más alto revienta el paquete de MySQL. */
    private const LOTE = 500;

    private string $clave;

    /** @var array<string, int> */
    private array $cargos = [];

    /** @var array<int, array<string, mixed>> */
    private array $pendientes = [];

    private int $insertados = 0;

    public function run(): void
    {
        // Bcrypt tarda ~165 ms. Hacerlo por persona serían casi veinte minutos
        // de seed; todas comparten la misma contraseña de desarrollo, así que
        // se calcula una vez.
        $this->clave = Hash::make('password');

        $this->limpiar();

        $sucursales = $this->sembrarSucursales();
        $this->cargos = $this->sembrarCargos();

        $this->command->info('Sembrando el organigrama…');

        $gg = $this->persona('Rodrigo', 'Guzmán', 'X-GG', 'GG', $sucursales['X-CASA'], null);

        $gerencias = $this->sembrarGerencias($gg, $sucursales['X-CASA']);
        $zonales = $this->sembrarZonales($gerencias, $sucursales['X-CASA']);
        $this->sembrarSucursalesConGente($sucursales, $zonales);
        $this->casosLimite($sucursales, $gg);

        $this->volcar();
        $this->resumen();
    }

    // -- Estructura ----------------------------------------------------

    /** @return array<string, int> código → id */
    private function sembrarSucursales(): array
    {
        $ciudades = [
            'Arica', 'Iquique', 'Antofagasta', 'Calama', 'Copiapó', 'La Serena',
            'Ovalle', 'Valparaíso', 'Viña del Mar', 'Quillota', 'San Antonio',
            'Santiago', 'Puente Alto', 'Maipú', 'La Florida', 'Ñuñoa',
            'Rancagua', 'San Fernando', 'Curicó', 'Talca', 'Linares',
            'Chillán', 'Concepción', 'Talcahuano', 'Los Ángeles', 'Temuco',
            'Villarrica', 'Valdivia', 'Osorno', 'Puerto Montt', 'Castro',
            'Coyhaique', 'Punta Arenas',
        ];
        $formatos = ['Centro', 'Mall', 'Express', 'Norte', 'Sur', 'Plaza', 'Costanera'];

        $filas = [['external_code' => 'X-CASA', 'name' => 'Casa Matriz Corporativa', 'active' => true]];

        for ($i = 0; $i < self::SUCURSALES; $i++) {
            $ciudad = $ciudades[$i % count($ciudades)];
            $formato = $formatos[intdiv($i, count($ciudades)) % count($formatos)];

            $filas[] = [
                'external_code' => sprintf('X-SUC-%03d', $i + 1),
                'name' => "{$ciudad} {$formato}",
                'active' => true,
            ];
        }

        // Tres sin gente: el selector de sucursales no debe ofrecerlas.
        foreach (['Bodega Central', 'Sucursal en apertura', 'Punto de retiro'] as $n => $nombre) {
            $filas[] = [
                'external_code' => sprintf('X-VACIA-%d', $n + 1),
                'name' => $nombre,
                'active' => true,
            ];
        }

        $ahora = now();
        foreach (array_chunk($filas, self::LOTE) as $lote) {
            DB::table('branch_offices')->insert(array_map(
                fn ($f) => $f + ['created_at' => $ahora, 'updated_at' => $ahora],
                $lote,
            ));
        }

        return BranchOffice::where('external_code', 'like', 'X-%')
            ->pluck('id', 'external_code')->all();
    }

    /** @return array<string, int> código → id */
    private function sembrarCargos(): array
    {
        $ahora = now();
        $cargos = [
            'GG' => 'Gerente General',
            'GDIV' => 'Gerente de División',
            'JZON' => 'Jefe Zonal',
            'JTIE' => 'Jefe de Tienda',
            'SUPV' => 'Supervisor de Sala',
            'VEND' => 'Vendedor',
            'CAJA' => 'Cajero',
            'REPO' => 'Reponedor',
            'ADMI' => 'Administrativo',
            // Un cargo largo de verdad: sirve para ver si algo se desborda.
            'CESPE' => 'Coordinador de Experiencia de Clientes y Postventa Regional',
        ];

        $existentes = JobPosition::whereIn('external_code', array_keys($cargos))
            ->pluck('external_code')->all();

        $nuevos = [];
        foreach ($cargos as $codigo => $nombre) {
            if (! in_array($codigo, $existentes, true)) {
                $nuevos[] = [
                    'external_code' => $codigo, 'name' => $nombre, 'active' => true,
                    'created_at' => $ahora, 'updated_at' => $ahora,
                ];
            }
        }

        if ($nuevos !== []) {
            DB::table('job_positions')->insert($nuevos);
        }

        return JobPosition::pluck('id', 'external_code')->all();
    }

    /** @return array<int, int> ids de las gerencias */
    private function sembrarGerencias(int $jefe, int $casa): array
    {
        $divisiones = ['Comercial', 'Operaciones', 'Personas', 'Finanzas', 'Logística', 'Tecnología'];
        $ids = [];

        foreach ($divisiones as $i => $division) {
            $ids[] = $this->persona(
                $this->nombre($i * 7), $this->apellido($i * 11),
                sprintf('X-GD-%02d', $i + 1), 'GDIV', $casa, $jefe,
            );
        }

        return $ids;
    }

    /** @return array<int, int> ids de las jefaturas zonales */
    private function sembrarZonales(array $gerencias, int $casa): array
    {
        $ids = [];

        for ($i = 0; $i < 24; $i++) {
            $ids[] = $this->persona(
                $this->nombre($i * 13), $this->apellido($i * 17),
                sprintf('X-JZ-%02d', $i + 1), 'JZON', $casa,
                $gerencias[$i % count($gerencias)],
            );
        }

        return $ids;
    }

    private function sembrarSucursalesConGente(array $sucursales, array $zonales): void
    {
        $cargosPiso = ['VEND', 'VEND', 'VEND', 'CAJA', 'REPO', 'ADMI'];
        $semilla = 0;

        for ($s = 0; $s < self::SUCURSALES; $s++) {
            $sucursal = $sucursales[sprintf('X-SUC-%03d', $s + 1)];

            $jefe = $this->persona(
                $this->nombre($semilla++), $this->apellido($semilla++),
                sprintf('X-JT-%03d', $s + 1), 'JTIE', $sucursal,
                $zonales[$s % count($zonales)],
            );

            // Entre dos y cuatro supervisores por tienda, repartiéndose la gente.
            $cuantosSup = 2 + ($s % 3);
            $supervisores = [];

            for ($v = 0; $v < $cuantosSup; $v++) {
                $supervisores[] = $this->persona(
                    $this->nombre($semilla++), $this->apellido($semilla++),
                    sprintf('X-SV-%03d-%d', $s + 1, $v + 1), 'SUPV', $sucursal, $jefe,
                );
            }

            $piso = self::PISO_MIN + ($s * 7) % (self::PISO_MAX - self::PISO_MIN + 1);

            for ($p = 0; $p < $piso; $p++) {
                $this->persona(
                    $this->nombre($semilla++), $this->apellido($semilla++),
                    sprintf('X-PI-%03d-%03d', $s + 1, $p + 1),
                    $cargosPiso[$p % count($cargosPiso)],
                    $sucursal,
                    $supervisores[$p % count($supervisores)],
                    // Una de cada sesenta está inactiva: no debe entrar al padrón.
                    activa: ($p % 60) !== 0,
                );
            }
        }
    }

    /**
     * Las rarezas que en producción aparecen igual y rompen cosas.
     *
     * Cada una apunta a una parte concreta del sistema, y por eso están acá
     * juntas y comentadas en vez de escondidas entre los datos normales.
     */
    private function casosLimite(array $sucursales, int $gg): void
    {
        $casa = $sucursales['X-CASA'];

        // 1. Un tramo de mando enorme: 90 personas colgando de una sola
        //    jefatura. Ejercita la cascada de supervisados y el conteo «a
        //    cargo», que en la intranet era una consulta por nivel.
        $jefeCD = $this->persona(
            'Bernardita', 'Achondo', 'X-CD-JEFE', 'JTIE', $casa, $gg,
        );

        for ($i = 0; $i < 90; $i++) {
            $this->persona(
                $this->nombre(900 + $i), $this->apellido(900 + $i),
                sprintf('X-CD-%03d', $i + 1), 'REPO', $casa, $jefeCD,
            );
        }

        // 2. Gente sin sucursal asignada. En la intranet era una
        //    pseudo-sucursal con id 0 que había que tratar aparte.
        for ($i = 0; $i < 40; $i++) {
            $this->persona(
                $this->nombre(1200 + $i), $this->apellido(1200 + $i),
                sprintf('X-SS-%02d', $i + 1), 'ADMI', null, $gg,
            );
        }

        // 3. Personas sin jefatura: quedarían sueltas en la previsualización
        //    y el envío tiene que bloquearse hasta resolverlas.
        for ($i = 0; $i < 12; $i++) {
            $this->persona(
                $this->nombre(1400 + $i), $this->apellido(1400 + $i),
                sprintf('X-SJ-%02d', $i + 1), 'ADMI', $casa, null,
            );
        }

        // 4. Nombres largos y con tildes y ñ, para ver si algo se desborda o
        //    se ordena mal.
        $this->persona(
            'María de los Ángeles', 'Fernández-Undurraga Peñailillo',
            'X-LARGO-1', 'CESPE', $casa, $gg,
        );
        $this->persona(
            'Jean-Christophe', 'Ñancupil Huenchuñir',
            'X-LARGO-2', 'CESPE', $casa, $gg,
        );

        // 5. Una cadena profunda de verdad: diez niveles encadenados. La
        //    consulta recursiva tiene tope 50, y conviene comprobar que un
        //    árbol hondo no la haga fallar ni se vuelva lenta.
        $anterior = $gg;
        for ($i = 0; $i < 10; $i++) {
            $anterior = $this->persona(
                $this->nombre(1600 + $i), $this->apellido(1600 + $i),
                sprintf('X-PROF-%02d', $i + 1), 'ADMI', $casa, $anterior,
            );
        }
    }

    // -- Inserción -----------------------------------------------------

    /**
     * Encola una persona y devuelve el id que va a tener.
     *
     * Se reserva el id antes de insertar porque el organigrama necesita
     * apuntar a la jefatura mientras se arma: esperar al `INSERT` obligaría a
     * una segunda pasada para rellenar los `supervisor_id`.
     */
    private function persona(
        string $nombre,
        string $apellido,
        string $codigo,
        string $cargo,
        ?int $sucursal,
        ?int $jefe,
        bool $activa = true,
    ): int {
        $id = $this->proximoId();

        $this->pendientes[] = [
            'id' => $id,
            'external_code' => $codigo,
            'name' => $nombre,
            'lastname' => $apellido,
            'email' => strtolower($codigo).'@corp.test',
            'password' => $this->clave,
            'role' => Role::COLLABORATOR->value,
            'active' => $activa,
            'must_set_password' => false,
            'branch_office_id' => $sucursal,
            'job_position_id' => $this->cargos[$cargo] ?? null,
            'supervisor_id' => $jefe,
            'created_at' => now(),
            'updated_at' => now(),
        ];

        if (count($this->pendientes) >= self::LOTE) {
            $this->volcar();
        }

        return $id;
    }

    private ?int $siguiente = null;

    private function proximoId(): int
    {
        $this->siguiente ??= ((int) DB::table('users')->max('id')) + 1;

        return $this->siguiente++;
    }

    private function volcar(): void
    {
        if ($this->pendientes === []) {
            return;
        }

        DB::table('users')->insert($this->pendientes);
        $this->insertados += count($this->pendientes);
        $this->pendientes = [];

        $this->command->getOutput()->write(sprintf("\r  %d personas…", $this->insertados));
    }

    // -- Limpieza y nombres --------------------------------------------

    private function limpiar(): void
    {
        $usuarios = DB::table('users')->where('email', 'like', '%@corp.test')->count();

        if ($usuarios === 0) {
            return;
        }

        // `evaluation_users.user_id` está en cascada: borrar a esta gente se
        // lleva por delante el padrón de cualquier evaluación armada sobre
        // ella, sin decir nada. Mejor decirlo y preguntar.
        $padron = DB::table('evaluation_users')
            ->join('users', 'users.id', '=', 'evaluation_users.user_id')
            ->where('users.email', 'like', '%@corp.test')
            ->count();

        $this->command->warn("Hay {$usuarios} personas de una siembra anterior.");

        if ($padron > 0) {
            $this->command->error(
                "Están en el padrón de alguna evaluación: se perderían {$padron} filas."
            );

            if (! $this->command->confirm('¿Borrarlas igual?', false)) {
                throw new \RuntimeException('Siembra cancelada: había un padrón en juego.');
            }
        }

        DB::table('users')->where('email', 'like', '%@corp.test')->delete();
        DB::table('branch_offices')->where('external_code', 'like', 'X-%')->delete();
    }

    private function nombre(int $i): string
    {
        $nombres = [
            'Camila', 'Matías', 'Valentina', 'Benjamín', 'Josefa', 'Vicente',
            'Antonia', 'Martín', 'Isidora', 'Agustín', 'Florencia', 'Joaquín',
            'Emilia', 'Tomás', 'Catalina', 'Sebastián', 'Javiera', 'Diego',
            'Constanza', 'Ignacio', 'Francisca', 'Cristóbal', 'Trinidad',
            'Maximiliano', 'Amanda', 'Bastián', 'Renata', 'Nicolás', 'Fernanda',
            'Rodrigo', 'Paula', 'Álvaro', 'Daniela', 'Felipe', 'Macarena',
        ];

        return $nombres[$i % count($nombres)];
    }

    private function apellido(int $i): string
    {
        $apellidos = [
            'González', 'Muñoz', 'Rojas', 'Díaz', 'Pérez', 'Soto', 'Contreras',
            'Silva', 'Martínez', 'Sepúlveda', 'Morales', 'Rodríguez', 'López',
            'Fuentes', 'Hernández', 'Torres', 'Araya', 'Flores', 'Espinoza',
            'Valenzuela', 'Castillo', 'Tapia', 'Reyes', 'Gutiérrez', 'Castro',
            'Vargas', 'Álvarez', 'Vásquez', 'Sánchez', 'Fernández', 'Ramírez',
            'Carrasco', 'Miranda', 'Orellana', 'Cárdenas', 'Riquelme', 'Bravo',
        ];

        return $apellidos[$i % count($apellidos)];
    }

    private function resumen(): void
    {
        $this->command->newLine(2);

        $sucursales = DB::table('branch_offices')->where('external_code', 'like', 'X-%')->count();
        $activas = DB::table('users')->where('email', 'like', '%@corp.test')->where('active', true)->count();
        $sinSucursal = DB::table('users')->where('email', 'like', '%@corp.test')->whereNull('branch_office_id')->count();
        $sinJefe = DB::table('users')->where('email', 'like', '%@corp.test')->whereNull('supervisor_id')->count();

        $this->command->info('Empresa grande sembrada.');
        $this->command->table(['Qué', 'Cuánto'], [
            ['Personas', $this->insertados],
            ['  activas', $activas],
            ['  inactivas', $this->insertados - $activas],
            ['  sin sucursal', $sinSucursal],
            ['  sin jefatura', $sinJefe],
            ['Sucursales', $sucursales],
            ['  sin personal', 3],
            ['Niveles del organigrama', '6 (y una rama de 10)'],
            ['Tramo de mando mayor', '90 personas directas'],
        ]);
        $this->command->newLine();
        $this->command->comment('Todas con la contraseña «password» y correo <código>@corp.test');
    }
}
