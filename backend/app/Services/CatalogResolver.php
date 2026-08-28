<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\BranchOffice;
use App\Models\JobPosition;

/**
 * Convierte lo que dice la planilla en el id de una sucursal o un cargo.
 *
 * En la planilla son texto; en la base son otra tabla. Entre esas dos cosas
 * hay una regla, y es la única que hay que recordar:
 *
 *   **Si la planilla trae el nombre, se crea lo que falte.
 *   Si trae solo el código, tiene que existir.**
 *
 * Es la diferencia entre un dato que la planilla puede inventar y una clave
 * que apunta a otro lado. Un código suelto que no existe no se puede crear
 * —no habría con qué nombrarlo— y dejarlo pasar significaría o perder el dato
 * o, peor, crear una sucursal llamada «14».
 *
 * Buscar es más permisivo que crear: un valor se busca primero entre los
 * códigos y después entre los nombres, sin importar cuál de los dos campos lo
 * trajo. Así una planilla que pone el código donde va el nombre igual
 * encuentra la fila correcta en vez de duplicarla.
 *
 * **Guarda todo el catálogo en memoria.** Antes se resolvía con un
 * `firstOrCreate` por fila: en una nómina de 7.000 personas eran 14.000
 * consultas para 129 sucursales y 16 cargos.
 */
final class CatalogResolver
{
    private const CATALOGOS = [
        'sucursal' => BranchOffice::class,
        'cargo' => JobPosition::class,
    ];

    /** @var array<string, array{codigos: array<string, int>, nombres: array<string, int>}> */
    private array $indice = [];

    /** @var array<string, array<int, string>> Lo que habría que crear, para el ensayo. */
    private array $pendientes = [];

    /** @var array<string, array<int, string>> Códigos que no están cargados. */
    private array $faltantes = [];

    /**
     * @param  bool  $simular  no escribe nada; solo anota qué se crearía
     */
    public function __construct(private readonly bool $simular = false) {}

    /**
     * @return array{0: int|null, 1: string|null} el id, o el motivo del rechazo
     */
    public function resolver(string $catalogo, string $codigo, string $nombre): array
    {
        $codigo = trim($codigo);
        $nombre = trim($nombre);

        if ($codigo === '' && $nombre === '') {
            return [null, null];
        }

        $this->cargar($catalogo);

        // Buscar es permisivo: cualquiera de los dos valores sirve para
        // encontrar una fila que ya está.
        foreach ([$codigo, $nombre] as $valor) {
            if ($valor === '') {
                continue;
            }

            $id = $this->indice[$catalogo]['codigos'][$valor]
                ?? $this->indice[$catalogo]['nombres'][self::clave($valor)]
                ?? null;

            if ($id !== null) {
                return [$id, null];
            }
        }

        if ($nombre === '') {
            $this->anotarFaltante($catalogo, $codigo);

            return [null, $this->mensajeDeFaltante($catalogo, $codigo)];
        }

        return [$this->crear($catalogo, $codigo, $nombre), null];
    }

    /**
     * Nombres que la planilla va a dar de alta, en orden alfabético.
     *
     * Ordenados y no en el orden del archivo a propósito: la lista se muestra
     * para cazar variantes de escritura, y «Suc. Norte» junto a «Sucursal
     * Norte» salta a la vista; separados por trescientas filas, no.
     *
     * @return array<int, string>
     */
    public function porCrear(string $catalogo): array
    {
        $nombres = array_values($this->pendientes[$catalogo] ?? []);
        sort($nombres);

        return $nombres;
    }

    /**
     * Códigos que la planilla usa y que no están cargados. Bloquean la fila.
     *
     * @return array<int, string>
     */
    public function faltantes(string $catalogo): array
    {
        return array_values($this->faltantes[$catalogo] ?? []);
    }

    // -----------------------------------------------------------------

    private function crear(string $catalogo, string $codigo, string $nombre): ?int
    {
        $this->pendientes[$catalogo][self::clave($codigo.'|'.$nombre)] =
            $codigo === '' ? $nombre : "{$nombre} ({$codigo})";

        if ($this->simular) {
            // Un id que no existe, pero que alcanza para que las filas
            // siguientes con la misma sucursal no la cuenten dos veces.
            $id = -count($this->pendientes[$catalogo]);
            $this->recordar($catalogo, $codigo, $nombre, $id);

            return null;
        }

        /** @var class-string<BranchOffice|JobPosition> $modelo */
        $modelo = self::CATALOGOS[$catalogo];

        $fila = $modelo::create([
            'external_code' => $codigo === '' ? null : $codigo,
            'name' => $nombre,
            'active' => true,
        ]);

        $this->recordar($catalogo, $codigo, $nombre, $fila->id);

        return $fila->id;
    }

    private function anotarFaltante(string $catalogo, string $codigo): void
    {
        $this->faltantes[$catalogo][$codigo] = $codigo;
    }

    private function mensajeDeFaltante(string $catalogo, string $codigo): string
    {
        $donde = $catalogo === 'sucursal' ? 'Sucursales' : 'Cargos';
        $que = $catalogo === 'sucursal' ? 'La sucursal' : 'El cargo';

        return "{$que} con código «{$codigo}» no está en el sistema. Cargalo en "
            ."Directorio → {$donde}, o agregá a la planilla la columna con su nombre.";
    }

    private function cargar(string $catalogo): void
    {
        if (isset($this->indice[$catalogo])) {
            return;
        }

        $this->indice[$catalogo] = ['codigos' => [], 'nombres' => []];

        /** @var class-string<BranchOffice|JobPosition> $modelo */
        $modelo = self::CATALOGOS[$catalogo];

        foreach ($modelo::query()->get(['id', 'external_code', 'name']) as $fila) {
            $this->recordar($catalogo, (string) $fila->external_code, (string) $fila->name, $fila->id);
        }
    }

    private function recordar(string $catalogo, string $codigo, string $nombre, int $id): void
    {
        if ($codigo !== '') {
            $this->indice[$catalogo]['codigos'][$codigo] = $id;
        }

        if ($nombre !== '') {
            $this->indice[$catalogo]['nombres'][self::clave($nombre)] = $id;
        }
    }

    /**
     * Clave con la que se comparan los nombres.
     *
     * Sin mayúsculas y sin tildes, para imitar al cotejo de MySQL, que trata
     * «SUCURSAL ÑUÑOA» y «Sucursal Ñuñoa» como la misma. Si acá se comparara
     * literal, el índice diría «no existe», se crearía la fila, y la base la
     * aceptaría: dos sucursales que para el motor son la misma.
     *
     * Es pública porque `CatalogImportService` compara los mismos nombres: uno
     * crea las sucursales que nombra la nómina y el otro las que trae la
     * planilla del catálogo, así que si compararan distinto se duplicarían
     * entre ellos.
     */
    public static function clave(string $valor): string
    {
        $sin = (string) iconv('UTF-8', 'ASCII//TRANSLIT', $valor);

        return mb_strtolower(trim($sin));
    }
}
