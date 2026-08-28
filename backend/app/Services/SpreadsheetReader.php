<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Http\UploadedFile;
use Maatwebsite\Excel\Concerns\Import;
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
     * La huella que deja un UTF-8 codificado dos veces.
     *
     * `Ã` o `Â` seguidas de un carácter de la mitad alta de Latin-1. En
     * castellano esa combinación no existe: «Ã¡» no es una palabra de ningún
     * idioma, es siempre una «á» que pasó dos veces por la misma conversión.
     */
    private const DOBLE_CODIFICACION = '/[\x{00C2}\x{00C3}][\x{0080}-\x{00BF}]/u';

    /**
     * @return array<int, array<string, mixed>>
     *
     * @throws \RuntimeException si el archivo está vacío o no tiene encabezado
     */
    public function read(UploadedFile $file): array
    {
        return $this->asociar(
            $this->celdas($file->getRealPath(), mb_strtolower($file->getClientOriginalExtension())),
        );
    }

    /**
     * Lee la planilla **sin dar por sentado ningún encabezado**.
     *
     * Es lo que necesita la homologación: acá el archivo viene con las
     * columnas que se le ocurrieron a quien lo armó, así que se devuelven tal
     * como están y quien decide qué es cada una es la persona, después.
     *
     * @return array{
     *     headers: array<int, array{clave: string, etiqueta: string}>,
     *     rows: array<int, array<string, string>>
     * }
     *
     * @throws \RuntimeException si el archivo está vacío o no tiene encabezado
     */
    public function readRaw(string $ruta, string $extension): array
    {
        $filas = $this->celdas($ruta, mb_strtolower($extension));
        $encabezado = $this->encabezados(array_shift($filas));

        $resultado = [];

        foreach ($filas as $fila) {
            if ($this->estaVacia($fila)) {
                continue;
            }

            $asociativa = [];
            foreach ($encabezado as $indice => $columna) {
                $asociativa[$columna['clave']] = $this->comoTexto($fila[$indice] ?? null);
            }

            $resultado[] = $asociativa;
        }

        return ['headers' => $encabezado, 'rows' => $resultado];
    }

    /**
     * @return array<int, array<int, mixed>>
     */
    private function celdas(string $ruta, string $extension): array
    {
        $filas = in_array($extension, ['csv', 'txt'], true)
            ? $this->leerCsv($ruta)
            : $this->leerExcel($ruta);

        if (count($filas) < 2) {
            throw new \RuntimeException(
                'La planilla está vacía o no tiene filas debajo del encabezado.',
            );
        }

        return $filas;
    }

    /**
     * Nombra las columnas del archivo para poder referirse a ellas.
     *
     * La clave es el encabezado normalizado; la etiqueta, el texto tal como
     * se escribió, que es lo que la persona reconoce al mirar su planilla.
     *
     * Dos casos que se dan de verdad y hay que resolver acá: **encabezados
     * repetidos** —dos columnas «Nombre»— y **encabezados vacíos**, que en
     * Excel abundan porque alguien escribió algo en una celda suelta a la
     * derecha. A los repetidos se les agrega un número; a los vacíos se les
     * pone la letra de su columna, que es como la persona la ve en Excel.
     *
     * @return array<int, array{clave: string, etiqueta: string}>
     */
    private function encabezados(array $fila): array
    {
        $encabezados = [];
        $usadas = [];

        foreach ($fila as $indice => $celda) {
            $etiqueta = trim((string) $this->comoTexto($celda));
            $clave = $this->normalizarEncabezado($etiqueta);

            if ($clave === '') {
                $clave = 'columna_'.$this->letraDeColumna($indice);
                $etiqueta = 'Columna '.$this->letraDeColumna($indice).' (sin nombre)';
            }

            $base = $clave;
            $vuelta = 2;
            while (isset($usadas[$clave])) {
                $clave = $base.'_'.$vuelta;
                $etiqueta .= ' ('.$vuelta.')';
                $vuelta++;
            }

            $usadas[$clave] = true;
            $encabezados[$indice] = ['clave' => $clave, 'etiqueta' => $etiqueta];
        }

        return $encabezados;
    }

    /** A, B, … Z, AA, AB: la referencia que muestra Excel. */
    private function letraDeColumna(int $indice): string
    {
        $letra = '';

        for ($n = $indice; $n >= 0; $n = intdiv($n, 26) - 1) {
            $letra = chr(65 + $n % 26).$letra;
        }

        return $letra;
    }

    /**
     * Toda celda se convierte a texto, y a propósito.
     *
     * Una planilla trae números, fechas y booleanos, y todo lo que el sistema
     * guarda de una persona son cadenas. Sin esto, un código de ficha que
     * Excel guardó como número llega como `123.0` y deja de coincidir con el
     * `123` que ya está en el directorio: la misma persona se duplica.
     */
    private function comoTexto(mixed $valor): string
    {
        if ($valor === null || $valor === '') {
            return '';
        }

        if ($valor instanceof \DateTimeInterface) {
            return $valor->format('Y-m-d');
        }

        if (is_bool($valor)) {
            return $valor ? '1' : '0';
        }

        // Un entero que viaja como decimal —`123.0`— vuelve a ser entero. Los
        // decimales de verdad conservan sus dígitos.
        if (is_float($valor)) {
            return floor($valor) === $valor && abs($valor) < 1e15
                ? number_format($valor, 0, '.', '')
                : rtrim(rtrim(number_format($valor, 10, '.', ''), '0'), '.');
        }

        return trim($this->aUtf8((string) $valor));
    }

    /**
     * Deja el texto en UTF-8 de verdad, venga como venga.
     *
     * Dos averías distintas, las dos habituales en una planilla que pasó por
     * Excel, y las dos silenciosas: entran, se guardan y se descubren meses
     * después mirando el directorio.
     *
     * **Windows-1252.** Excel en español guarda CSV en la codificación del
     * sistema, no en UTF-8. Ahí una `á` es el byte `E1`, que no es UTF-8
     * válido: la base lo rechaza o lo reemplaza, y de paso `iconv` no puede
     * normalizar los encabezados.
     *
     * **UTF-8 doblemente codificado.** El caso que apareció de verdad. Alguien
     * abre en Excel un CSV que ya era UTF-8, Excel lo interpreta como ANSI y
     * al guardarlo escribe cada byte como si fuera un carácter: los dos bytes
     * de `á` —`C3 A1`— se convierten en cuatro, y «Sánchez» queda escrito
     * «SÃ¡nchez». El texto resultante es UTF-8 perfectamente válido, así que
     * nada se queja: solo se ve mal.
     *
     * El deshacer del segundo caso es deliberadamente cauto. Solo se aplica si
     * el texto trae una de las secuencias que delatan la avería —`Ã¡`, `Ã©`,
     * `Ã±`, `Â°`…— **y** además el resultado de deshacerla vuelve a ser UTF-8
     * válido. Con esas dos condiciones juntas, un apellido que de verdad lleve
     * una `Ã` no se toca.
     */
    private function aUtf8(string $texto): string
    {
        if ($texto === '') {
            return $texto;
        }

        // Caso 1: no es UTF-8. Windows-1252 y no ISO-8859-1 porque es lo que
        // escribe Excel, y porque incluye las comillas y los guiones largos
        // que ISO no tiene y que en una planilla aparecen todo el tiempo.
        if (! mb_check_encoding($texto, 'UTF-8')) {
            return mb_convert_encoding($texto, 'UTF-8', 'Windows-1252');
        }

        // Caso 2: es UTF-8 válido, pero lo es dos veces.
        if (preg_match(self::DOBLE_CODIFICACION, $texto) !== 1) {
            return $texto;
        }

        $deshecho = @mb_convert_encoding($texto, 'Windows-1252', 'UTF-8');

        return $deshecho !== false && $deshecho !== '' && mb_check_encoding($deshecho, 'UTF-8')
            ? $deshecho
            : $texto;
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

        // Sobre el contenido entero y antes de partirlo en celdas: los
        // separadores son ASCII, así que convertir acá es equivalente a
        // hacerlo celda por celda y además alcanza a los encabezados, que se
        // normalizan con `iconv` y con bytes inválidos no sobreviven.
        $contenido = $this->aUtf8($contenido);

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
    private function leerExcel(string $ruta): array
    {
        // `toArray` exige un objeto que implemente la interfaz marcadora del
        // paquete, aunque no se use ninguna de sus capacidades: acá solo se
        // quieren las celdas crudas.
        $hojas = Excel::toArray(new class implements Import {}, $ruta);

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
                $asociativa[$columna] = $this->comoTexto($fila[$indice] ?? null);
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
