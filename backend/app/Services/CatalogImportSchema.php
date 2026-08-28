<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Import;

/**
 * Un catálogo —sucursales o cargos— cargado desde una planilla.
 *
 * Los dos tienen la misma forma, código y nombre, así que comparten esquema y
 * el tipo llega por constructor. Lo que decide qué se hace con cada fila está
 * en `CatalogImportService`; acá está lo que necesita la pantalla para
 * mostrarlo antes.
 */
final class CatalogImportSchema implements ImportSchema
{
    /** Cuántas filas de ejemplo se muestran en el resumen. */
    private const FILAS_DE_MUESTRA = 8;

    /** Cuántos problemas se detallan antes de resumirlos en un número. */
    private const PROBLEMAS_DETALLADOS = 50;

    /** @param  string  $tipo  «sucursales» o «cargos» */
    public function __construct(
        private readonly CatalogImportService $servicio,
        private readonly string $tipo,
    ) {}

    public function definiciones(): array
    {
        return CatalogImportService::COLUMN_DEFINITIONS;
    }

    public function sinonimos(): array
    {
        return $this->servicio->sinonimos($this->tipo);
    }

    public function importar(array $filasMapeadas, Import $import, array $opciones = []): Import
    {
        return $this->servicio->import($this->tipo, $filasMapeadas, $import);
    }

    /**
     * Sin género en los participios a propósito: «creadas» vale para las
     * sucursales y «creados» para los cargos, y escribir las dos versiones de
     * cada mensaje es una fuente de erratas para nada.
     */
    public function mensajeFinal(Import $import): string
    {
        $palabras = CatalogImportService::palabras($this->tipo);
        $partes = [];

        if ($import->rows_created > 0) {
            $partes[] = "se crearon {$import->rows_created}";
        }
        if ($import->rows_updated > 0) {
            $partes[] = "se actualizaron {$import->rows_updated}";
        }
        if ($import->rows_failed > 0) {
            $partes[] = "se rechazaron {$import->rows_failed} filas";
        }

        return $partes === []
            ? 'La planilla no tenía filas para procesar.'
            : ucfirst($palabras['plural']).': '.implode(', ', $partes).'.';
    }

    /**
     * Qué va a pasar con la planilla, sin escribir nada.
     *
     * Los números salen del **mismo plan** que después ejecuta la importación,
     * así que el resumen no puede prometer una cosa y hacer otra.
     */
    public function ensayar(array $filasMapeadas, array $opciones = []): array
    {
        $plan = $this->servicio->planificar($this->tipo, $filasMapeadas);

        $muestra = [];
        $problemas = [];
        $conProblemas = 0;
        $seCrearan = 0;
        $actualizadas = [];
        $vecesCodigo = [];
        $vecesNombre = [];
        $comoSeEscribe = [];

        foreach ($plan as $paso) {
            $fila = $paso['fila'];

            if ($paso['accion'] === 'rechazar') {
                $conProblemas++;

                if (count($problemas) < self::PROBLEMAS_DETALLADOS) {
                    $problemas[] = [
                        'linea' => $paso['linea'],
                        'codigo' => $fila['codigo'],
                        'nombre' => $fila['nombre'],
                        'motivos' => $paso['motivos'],
                    ];
                }

                continue;
            }

            if ($paso['accion'] === 'crear') {
                $seCrearan++;
            } elseif (str_starts_with((string) $paso['ref'], 'db:')) {
                // Por referencia y no por fila: dos filas que tocan la misma
                // sucursal son una sola sucursal actualizada.
                $actualizadas[$paso['ref']] = true;
            }

            if ($fila['codigo'] !== '') {
                $vecesCodigo[$fila['codigo']] = ($vecesCodigo[$fila['codigo']] ?? 0) + 1;
            }

            $clave = CatalogResolver::clave($fila['nombre']);
            $vecesNombre[$clave] = ($vecesNombre[$clave] ?? 0) + 1;
            $comoSeEscribe[$clave] ??= $fila['nombre'];

            if (count($muestra) < self::FILAS_DE_MUESTRA) {
                $muestra[] = ['linea' => $paso['linea']] + $fila;
            }
        }

        $nombresRepetidos = [];

        foreach ($vecesNombre as $clave => $veces) {
            if ($veces > 1) {
                $nombresRepetidos[$comoSeEscribe[$clave]] = $veces;
            }
        }

        return [
            'filas_totales' => count($filasMapeadas),
            'filas_validas' => count($filasMapeadas) - $conProblemas,
            'filas_con_problemas' => $conProblemas,
            'se_crearan' => $seCrearan,
            'se_actualizaran' => count($actualizadas),
            'codigos_repetidos' => array_filter($vecesCodigo, fn (int $v) => $v > 1),
            // El mismo nombre dos veces no rompe nada —la segunda fila no
            // cambia nada— pero es la señal de que la planilla trae dos
            // filas para lo mismo, y casi siempre una de las dos está mal.
            'nombres_repetidos' => $nombresRepetidos,
            'muestra' => $muestra,
            'problemas' => $problemas,
            'problemas_omitidos' => max(0, $conProblemas - count($problemas)),
        ];
    }
}
