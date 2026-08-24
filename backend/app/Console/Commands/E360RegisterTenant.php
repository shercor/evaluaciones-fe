<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Support\E360\E360Client;
use App\Support\E360\Resources\TenantsApi;
use Illuminate\Console\Command;

/**
 * Da de alta la empresa en Evaluación 360.
 *
 * Es la **única** operación de este proyecto que escribe en esa API, así que
 * pide confirmación explícita antes de hacerlo. Todo lo demás —incluido
 * `e360:ping`— solo lee.
 *
 * Del lado de la API, registrar un tenant además lo siembra: crea su base y
 * sus plantillas de evaluación. Por eso el alta es suficiente para empezar a
 * trabajar, y por eso conviene no repetirla contra un tenant que ya existe.
 */
class E360RegisterTenant extends Command
{
    protected $signature = 'e360:register-tenant
                            {--force : Registra sin pedir confirmación}';

    protected $description = 'Registra la empresa como tenant en la API de Evaluación 360';

    public function handle(E360Client $client, TenantsApi $tenants): int
    {
        $this->newLine();
        $this->line('<options=bold>Evaluación 360 — alta de tenant</>');
        $this->newLine();

        // --- 1. Configuración -------------------------------------------
        $faltantes = $client->missingConfiguration();

        if ($faltantes !== []) {
            $this->components->error('Configuración incompleta. Faltan en el .env:');
            foreach ($faltantes as $clave) {
                $this->line("    · {$clave}");
            }

            return self::FAILURE;
        }

        $codename = (string) config('e360.tenant_codename');
        $token = (string) config('e360.tokens.tenant');

        $this->components->twoColumnDetail('URL base', $client->baseUrl());
        $this->components->twoColumnDetail('Tenant a registrar', $codename);
        $this->components->twoColumnDetail('Cabecera host que se usará', $client->tenantHost());
        $this->components->twoColumnDetail('Token del tenant', str_repeat('•', 8).' ('.mb_strlen($token).' caracteres)');
        $this->newLine();

        // El backend valida esto, pero avisarlo acá ahorra un viaje.
        if (mb_strlen($token) < 10) {
            $this->components->error('El token del tenant debe tener al menos 10 caracteres.');

            return self::FAILURE;
        }

        if (! preg_match('/^[a-z0-9][a-z0-9_-]*[a-z0-9]$/', $codename)) {
            $this->components->error(
                'El codename solo admite minúsculas, números, guiones y guiones bajos, '.
                'y no puede empezar ni terminar con guión.',
            );

            return self::FAILURE;
        }

        // --- 2. ¿Ya existe? ---------------------------------------------
        $existente = $tenants->show($codename);

        if ($existente->ok) {
            $this->components->warn("El tenant «{$codename}» ya existe en Evaluación 360.");
            $this->line('  No se vuelve a registrar: hacerlo recrearía su base y perdería sus datos.');
            $this->line('  Para verificar la conexión, usá: <options=bold>php artisan e360:ping</>');

            return self::SUCCESS;
        }

        if ($existente->errorKind === 'connection') {
            $this->components->error($existente->message);
            $this->line('  La API no respondió. Revisá que el stack de Evaluación 360 esté');
            $this->line('  levantado y que E360_BASE_URL sea alcanzable desde este contenedor,');
            $this->line('  no desde tu máquina.');

            return self::FAILURE;
        }

        // Cualquier otro error que no sea «no encontrado» es un problema real:
        // seguir adelante a ciegas podría escribir donde no corresponde.
        if ($existente->httpStatus !== 404) {
            $this->components->error("No se pudo consultar el tenant: {$existente->message}");

            // El motivo cambia según quién falló, y decir el equivocado manda
            // a revisar el archivo que no era.
            match (true) {
                in_array($existente->httpStatus, [401, 403], true) => $this->line(
                    '  Evaluación 360 rechazó las credenciales: revisá E360_CENTRAL_TOKEN.',
                ),
                $existente->httpStatus >= 500 => tap($this, function () {
                    $this->line('  El error viene de Evaluación 360, no de este proyecto: su API');
                    $this->line('  respondió pero falló por dentro. Revisá sus contenedores y sus logs.');
                }),
                default => $this->line('  Revisá E360_BASE_URL y que la API esté respondiendo.'),
            };

            return self::FAILURE;
        }

        // --- 3. Confirmación --------------------------------------------
        $this->components->info("El tenant «{$codename}» no existe todavía.");
        $this->newLine();
        $this->line('  Al registrarlo, la API va a:');
        $this->line('    · crear su base de datos aislada;');
        $this->line('    · sembrarla con las plantillas de evaluación base.');
        $this->newLine();
        $this->line('  <options=bold>Es la única escritura que este proyecto hace sobre Evaluación 360.</>');
        $this->newLine();

        if (! $this->option('force') && ! $this->confirm("¿Registrar «{$codename}»?", false)) {
            $this->components->warn('Cancelado. No se escribió nada.');

            return self::SUCCESS;
        }

        // --- 4. Alta ------------------------------------------------------
        $respuesta = $tenants->register($codename, $token);

        if ($respuesta->failed()) {
            $this->components->error("No se pudo registrar: {$respuesta->message}");

            return self::FAILURE;
        }

        $this->components->info("Tenant «{$codename}» registrado y sembrado.");
        $this->newLine();
        $this->line('  Verificá la conexión completa con: <options=bold>php artisan e360:ping</>');
        $this->newLine();

        return self::SUCCESS;
    }
}
