<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Http\UploadedFile;
use Maatwebsite\Excel\Facades\Excel;

/**
 * Lee una planilla de nómina y devuelve filas asociativas.
 *
 * Acepta CSV y XLSX.
 *
 * El CSV se lee con las funciones nativas de PHP y **con el separador
 * detectado**, no con el lector del paquete: su autodetección se equivocaba y
 * partía las filas por los espacios. Además, Excel en español exporta CSV con
 * punto y coma, así que dar por sentada la coma habría roto la mitad de los
 * archivos que llegan de Recursos Humanos.
 *
 * Los encabezados se normalizan —minúsculas, sin tildes, con guiones bajos—
 * para que «Código Supervisor», «codigo supervisor» y «CODIGO_SUPERVISOR»
 * sean la misma columna: nadie escribe el encabezado dos veces igual.
 */
class SpreadsheetReader
{
    /** Candidatos a separador, en orden de probabilidad. */
    private const DELIMITERS = [',', ';', "\t", '|'];

    /**
     * @return array<int, array<string, mixed>>
     *
     * @throws \RuntimeException si el archivo está vacío o no tiene encabezado
     */
    public function read(UploadedFile $file): array
    {
        $extension = mb_strtolower($file->getClientOriginalExtension());

        $filas = in_array($extension, ['csv', 'txt'], true)
            ? $this->leerCsv($file->getRealPath())
            : $this->leerExcel($file);

        if (count($filas) < 2) {
            throw new \RuntimeException(
                'La planilla está vacía o no tiene filas debajo del encabezado.',
            );
        }

        return $this->asociar($filas);
    }

    // -----------------------------------------------------------------

    /**
     * @return array<int, array<int, string|null>>
     */
    private function leerCsv(string $ruta): array
    {
        $contenido = file_get_contents($ruta);

        if ($contenido === false) {
            throw new \RuntimeException('No se pudo leer el archivo.');
        }

        // Excel antepone un BOM: si no se saca, el primer encabezado queda
        // con basura invisible adelante y esa columna nunca coincide.
        $contenido = preg_replace('/^\xEF\xBB\xBF/', '', $contenido) ?? $contenido;

        $manejador = fopen('php://temp', 'r+');
        fwrite($manejador, $contenido);
        rewind($manejador);

        $delimitador = $this->detectarDelimitador($contenido);

        $filas = [];
        while (($fila = fgetcsv($manejador, 0, $delimitador, '"', '\\')) !== false) {
            $filas[] = $fila;
        }

        fclose($manejador);

        return $filas;
    }

    /**
     * El separador es el que produce más columnas en la línea del encabezado.
     */
    private function detectarDelimitador(string $contenido): string
    {
        $primeraLinea = strtok($contenido, "\r\n") ?: '';

        $mejor = ',';
        $maximo = 0;

        foreach (self::DELIMITERS as $candidato) {
            $columnas = count(str_getcsv($primeraLinea, $candidato, '"', '\\'));

            if ($columnas > $maximo) {
                $maximo = $columnas;
                $mejor = $candidato;
            }
        }

        return $mejor;
    }

    /**
     * @return array<int, array<int, mixed>>
     */
    private function leerExcel(UploadedFile $file): array
    {
        // `toArray` exige un objeto que implemente la interfaz marcadora del
        // paquete, aunque no se use ninguna de sus capacidades: acá solo se
        // quieren las celdas crudas.
        $hojas = Excel::toArray(new class implements \Maatwebsite\Excel\Concerns\Import {}, $file);

        return $hojas[0] ?? [];
    }

    /**
     * Convierte filas indexadas en filas con nombre de columna.
     *
     * @param  array<int, array<int, mixed>>  $filas
     * @return array<int, array<string, mixed>>
     */
    private function asociar(array $filas): array
    {
        $encabezado = array_map($this->normalizarEncabezado(...), array_shift($filas));

        $resultado = [];

        foreach ($filas as $fila) {
            // Filas totalmente vacías: las planillas arrastran cientos al
            // final y no son un error que valga la pena reportar.
            if ($this->estaVacia($fila)) {
                continue;
            }

            $asociativa = [];
            foreach ($encabezado as $indice => $columna) {
                if ($columna === '') {
                    continue;
                }
                $asociativa[$columna] = $fila[$indice] ?? null;
            }

            $resultado[] = $asociativa;
        }

        return $resultado;
    }

    private function normalizarEncabezado(mixed $valor): string
    {
        $texto = (string) $valor;
        $texto = (string) iconv('UTF-8', 'ASCII//TRANSLIT', $texto);
        $texto = mb_strtolower(trim($texto));
        $texto = (string) preg_replace('/[^a-z0-9]+/', '_', $texto);

        return trim($texto, '_');
    }

    private function estaVacia(array $fila): bool
    {
        foreach ($fila as $celda) {
            if (! blank($celda)) {
                return false;
            }
        }

        return true;
    }
}
