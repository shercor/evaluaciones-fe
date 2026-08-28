<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Import;

/**
 * Qué se está cargando desde una planilla.
 *
 * La homologación —subir un archivo ajeno, decir qué columna es cuál, ver el
 * resumen y confirmar— es la misma para la nómina y para los catálogos. Lo que
 * cambia entre una y otros son cuatro cosas: qué columnas tiene el sistema,
 * con qué nombres suelen aparecer en una planilla de verdad, qué se cuenta en
 * el ensayo y quién escribe al final.
 *
 * Esas cuatro cosas son esta interfaz. Todo lo demás —el lector, los
 * borradores, la sugerencia, las validaciones de la homologación y la pantalla
 * de tres pasos— es uno solo y no sabe qué se está cargando.
 */
interface ImportSchema
{
    /**
     * Las columnas del sistema, en el orden en que se muestran.
     *
     * @return array<string, array{etiqueta: string, obligatoria: bool, ayuda: string}>
     */
    public function definiciones(): array;

    /**
     * Nombres con los que cada columna suele aparecer en una planilla ajena.
     *
     * Solo alimenta la **sugerencia** inicial, que se ve y se corrige. Nada se
     * importa por lo que diga esto.
     *
     * @return array<string, array<int, string>>
     */
    public function sinonimos(): array;

    /**
     * Qué pasaría con estas filas, sin escribir nada.
     *
     * Recibe las mismas opciones que `importar()` a propósito: desde que la
     * nómina puede dar de baja a quien no viene en el archivo, el resultado
     * depende de con qué opciones se confirme, y un resumen calculado con
     * otras que las de la importación es exactamente lo que la pantalla
     * promete que no pasa.
     *
     * @param  array<int, array<string, string>>  $filasMapeadas
     * @param  array<string, mixed>  $opciones
     * @return array<string, mixed>
     */
    public function ensayar(array $filasMapeadas, array $opciones = []): array;

    /**
     * Importa de verdad.
     *
     * @param  array<int, array<string, string>>  $filasMapeadas
     * @param  array<string, mixed>  $opciones  lo que solo entiende un destino
     */
    public function importar(array $filasMapeadas, Import $import, array $opciones = []): Import;

    /** Cómo se le cuenta a una persona que la importación terminó. */
    public function mensajeFinal(Import $import): string;
}
