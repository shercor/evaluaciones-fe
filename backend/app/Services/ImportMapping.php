<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Validation\ValidationException;

/**
 * Homologa una planilla ajena con las columnas del sistema.
 *
 * El camino normal de importación exige una planilla con los encabezados que
 * el sistema espera. Este es el otro camino: la planilla llega como la tenía
 * Recursos Humanos —«RUT», «Nombre Completo», «Local», «Jefe Directo»— y es
 * una persona la que dice qué columna es cuál.
 *
 * Todo lo que se homologa es **texto**. Ninguna columna del directorio guarda
 * números ni fechas, así que traducir es renombrar claves, no convertir tipos:
 * el lector ya dejó cada celda como cadena. Eso es lo que hace que esto sea
 * seguro de hacer sobre un archivo desconocido.
 *
 * Lo que este servicio **no** hace es importar, ni saber qué se está
 * importando. Eso lo pone el `ImportSchema` que recibe: la nómina, las
 * sucursales o los cargos. Su salida es exactamente la forma que espera el
 * importador de ese destino, así que el motor de importación —con su fila a
 * fila, su idempotencia por código y sus avisos— es el mismo que el del camino
 * con formato propio. Un segundo importador en paralelo sería la manera más
 * rápida de que los dos se comporten distinto.
 */
final class ImportMapping
{
    public function __construct(private readonly ImportSchema $esquema) {}

    /**
     * Las columnas del sistema, para que la pantalla sepa qué pedir.
     *
     * @return array<int, array{clave: string, etiqueta: string, obligatoria: bool, ayuda: string}>
     */
    public function columnasDelSistema(): array
    {
        $columnas = [];

        foreach ($this->esquema->definiciones() as $clave => $definicion) {
            $columnas[] = ['clave' => $clave] + $definicion;
        }

        return $columnas;
    }

    /**
     * Propone una homologación mirando los nombres de los encabezados.
     *
     * Primero busca coincidencias exactas, y recién después parciales: si una
     * planilla tiene «supervisor» y «codigo_supervisor», la exacta gana y no
     * se las lleva la primera que pase. Un encabezado ya usado no se ofrece
     * dos veces.
     *
     * @param  array<int, array{clave: string, etiqueta: string}>  $encabezados
     * @return array<string, string|null> columna del sistema => columna del archivo
     */
    public function sugerir(array $encabezados): array
    {
        $sinonimos = $this->esquema->sinonimos();
        $disponibles = array_column($encabezados, 'clave');
        $mapa = array_fill_keys(array_keys($this->esquema->definiciones()), null);

        foreach ([true, false] as $exacta) {
            foreach ($mapa as $columna => $elegida) {
                if ($elegida !== null) {
                    continue;
                }

                // Los sinónimos se recorren **en su orden**, y el orden es la
                // prioridad: para «código del supervisor» primero se busca
                // `codigo_supervisor` y recién después `supervisor`. Al revés
                // —recorriendo las columnas del archivo por fuera— gana la que
                // aparezca antes en la planilla, y una que tenga las dos deja
                // el código del jefe sin conectar y la jerarquía sin armar.
                foreach ($sinonimos[$columna] ?? [] as $sinonimo) {
                    foreach ($disponibles as $indice => $clave) {
                        if ($exacta ? $clave === $sinonimo : str_contains($clave, $sinonimo)) {
                            if (! $exacta && $this->esNombreExactoDeOtro($clave, $columna)) {
                                continue;
                            }

                            $mapa[$columna] = $clave;
                            unset($disponibles[$indice]);

                            // Sale del recorrido de encabezados y del de
                            // sinónimos, y sigue con el campo siguiente.
                            continue 3;
                        }
                    }
                }
            }
        }

        return $mapa;
    }

    /**
     * ¿Este encabezado es, palabra por palabra, el nombre de otro campo?
     *
     * Frena a la segunda vuelta, la de coincidencias parciales. Sin esto, en
     * una planilla que trae «codigo_supervisor» pero no «codigo», el campo
     * «código interno» —que se resuelve antes— se lleva la columna del jefe
     * porque la contiene, y quedan las dos mal.
     */
    private function esNombreExactoDeOtro(string $clave, string $columnaActual): bool
    {
        foreach ($this->esquema->sinonimos() as $columna => $sinonimos) {
            if ($columna !== $columnaActual && in_array($clave, $sinonimos, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Revisa la homologación elegida antes de dejarla pasar.
     *
     * Tres cosas se rechazan acá, y las tres son errores de verdad y no
     * escrúpulos: que falte una columna obligatoria, que se apunte a un
     * encabezado que el archivo no tiene, y que dos campos del sistema se
     * conecten al mismo. Este último es siempre un descuido —nadie quiere el
     * mismo dato en «nombre» y en «apellido»— y si pasara sin aviso se
     * descubriría cuando el directorio ya tiene 800 personas mal cargadas.
     *
     * @param  array<string, string|null>  $mapa
     * @param  array<int, array{clave: string, etiqueta: string}>  $encabezados
     * @return array<string, string> la homologación limpia, sin las vacías
     *
     * @throws ValidationException
     */
    public function validar(array $mapa, array $encabezados): array
    {
        $definiciones = $this->esquema->definiciones();
        $delArchivo = array_column($encabezados, 'etiqueta', 'clave');
        $limpio = [];
        $errores = [];

        foreach ($mapa as $columna => $origen) {
            if (! isset($definiciones[$columna])) {
                $errores['mapping'][] = "«{$columna}» no es una columna del sistema.";

                continue;
            }

            if (blank($origen)) {
                continue;
            }

            if (! isset($delArchivo[$origen])) {
                $errores['mapping'][] = "La columna «{$definiciones[$columna]['etiqueta']}» apunta a "
                    .'un encabezado que ya no está en el archivo.';

                continue;
            }

            $limpio[$columna] = $origen;
        }

        foreach ($definiciones as $columna => $definicion) {
            if ($definicion['obligatoria'] && ! isset($limpio[$columna])) {
                $errores['mapping'][] = "Falta conectar «{$definicion['etiqueta']}», que es obligatoria.";
            }
        }

        foreach ($this->repetidos($limpio) as $origen => $columnas) {
            $nombres = array_map(
                fn (string $c) => '«'.$definiciones[$c]['etiqueta'].'»',
                $columnas,
            );

            $errores['mapping'][] = 'La columna «'.$delArchivo[$origen].'» del archivo está conectada a '
                .implode(' y ', $nombres).'. Cada columna del archivo puede alimentar solo un campo.';
        }

        if ($errores !== []) {
            throw ValidationException::withMessages($errores);
        }

        return $limpio;
    }

    /**
     * @param  array<string, string>  $mapa
     * @return array<string, array<int, string>> columna del archivo => columnas del sistema
     */
    private function repetidos(array $mapa): array
    {
        $porOrigen = [];

        foreach ($mapa as $columna => $origen) {
            $porOrigen[$origen][] = $columna;
        }

        return array_filter($porOrigen, fn (array $columnas) => count($columnas) > 1);
    }

    /**
     * Traduce las filas del archivo a las columnas del sistema.
     *
     * Lo que no se homologó viaja vacío, no ausente: el importador espera
     * todas las claves y una columna que falte sería un aviso distinto —«falta
     * el dato»— del que corresponde, que es «esta planilla no lo trae».
     *
     * @param  array<int, array<string, string>>  $filas
     * @param  array<string, string>  $mapa
     * @return array<int, array<string, string>>
     */
    public function aplicar(array $filas, array $mapa): array
    {
        $columnas = array_keys($this->esquema->definiciones());
        $traducidas = [];

        foreach ($filas as $fila) {
            $nueva = [];

            foreach ($columnas as $columna) {
                $origen = $mapa[$columna] ?? null;
                $nueva[$columna] = $origen === null ? '' : (string) ($fila[$origen] ?? '');
            }

            $traducidas[] = $nueva;
        }

        return $traducidas;
    }

    /**
     * Ensaya la importación sin tocar nada.
     *
     * Lo contesta el esquema, que es quien sabe qué se está cargando. Sigue
     * pasando por acá porque para la pantalla es un solo paso: «mostrame el
     * resumen de esta homologación».
     *
     * @param  array<int, array<string, string>>  $filasMapeadas
     * @param  array<string, mixed>  $opciones
     * @return array<string, mixed>
     */
    public function ensayar(array $filasMapeadas, array $opciones = []): array
    {
        return $this->esquema->ensayar($filasMapeadas, $opciones);
    }
}
