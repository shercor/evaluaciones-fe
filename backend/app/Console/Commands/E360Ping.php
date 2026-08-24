<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Support\E360\E360Client;
use App\Support\E360\Resources\EvaluationsApi;
use App\Support\E360\Resources\TenantsApi;
use Illuminate\Console\Command;

/**
 * Diagnóstico de la conexión con Evaluación 360.
 *
 * Comprueba las tres capas por separado, para que un fallo diga cuál es:
 * configuración, plano central y plano tenant.
 */
class E360Ping extends Command
{
    protected $signature = 'e360:ping';

    protected $description = 'Verifica la conexión con la API de Evaluación 360';

    public function handle(E360Client $client, TenantsApi $tenants, EvaluationsApi $evaluations): int
    {
        $this->newLine();
        $this->line('<options=bold>Evaluación 360 — diagnóstico de conexión</>');
        $this->newLine();

        // --- 1. Configuración -------------------------------------------
        $missing = $client->missingConfiguration();

        if ($missing !== []) {
            $this->error('Configuración incompleta. Faltan estas variables en el .env:');
            foreach ($missing as $key) {
                $this->line("    · {$key}");
            }
            $this->newLine();
            $this->line('Copiá los valores desde la intranet — la equivalencia está en config/e360.php.');

            return self::FAILURE;
        }

        $this->components->twoColumnDetail('URL base', $client->baseUrl());
        $this->components->twoColumnDetail('Cabecera host', $client->tenantHost());
        $this->components->info('Configuración completa.');

        // --- 2. Plano central -------------------------------------------
        $codename = (string) config('e360.tenant_codename');
        $response = $tenants->show($codename);

        if ($response->failed()) {
            $this->components->error("Plano central: {$response->message}");

            if ($response->errorKind === 'connection') {
                $this->line('  La API no respondió. Revisá que esté levantada y que E360_BASE_URL');
                $this->line('  sea alcanzable desde dentro del contenedor (no desde tu máquina).');
            } else {
                $this->line('  Respondió, pero rechazó la petición. Suele ser E360_CENTRAL_TOKEN.');
            }

            return self::FAILURE;
        }

        $this->components->info("Plano central: la empresa «{$codename}» existe.");

        // --- 3. Plano tenant --------------------------------------------
        $response = $evaluations->statuses();

        if ($response->failed()) {
            $this->components->error("Plano tenant: {$response->message}");
            $this->line('  El plano central respondió, así que la API está arriba y el');
            $this->line('  codename es correcto. Lo más probable es E360_TENANT_TOKEN.');

            return self::FAILURE;
        }

        $statuses = $response->collect('estados_evaluacion');
        $this->components->info('Plano tenant: autenticado correctamente.');
        $this->newLine();

        $this->line('  Estados que declara la API:');
        foreach ($statuses as $status) {
            $this->line(sprintf('    %-18s %s', $status->valor ?? '?', $status->color ?? ''));
        }

        $this->newLine();
        $this->components->info('Conexión verificada de punta a punta.');

        return self::SUCCESS;
    }
}
