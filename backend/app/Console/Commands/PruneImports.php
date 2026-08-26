<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\ImportHousekeeping;
use Illuminate\Console\Command;

/**
 * Limpieza periódica de las importaciones. La corre el programador de tareas.
 */
class PruneImports extends Command
{
    protected $signature = 'importaciones:limpiar
                            {--horas-borradores=24 : Vida de una planilla subida sin importar}
                            {--dias-contrasenas=90 : Cuánto se guardan las contraseñas temporales}';

    protected $description = 'Borra planillas abandonadas y olvida contraseñas temporales viejas';

    public function handle(ImportHousekeeping $limpieza): int
    {
        $horas = (int) $this->option('horas-borradores');

        $borradores = $limpieza->borrarBorradores($horas);
        $huerfanos = $limpieza->barrerArchivosHuerfanos($horas);
        $contrasenas = $limpieza->olvidarContrasenas((int) $this->option('dias-contrasenas'));

        $this->components->info(
            "Planillas abandonadas: {$borradores} · archivos sin dueño: {$huerfanos} · contraseñas olvidadas: {$contrasenas}",
        );

        return self::SUCCESS;
    }
}
