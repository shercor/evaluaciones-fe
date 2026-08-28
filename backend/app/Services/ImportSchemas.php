<?php

declare(strict_types=1);

namespace App\Services;

/**
 * Qué destinos admite la homologación.
 *
 * El destino se elige al subir el archivo y queda **guardado en el borrador**.
 * No viaja de nuevo en los pasos siguientes a propósito: si viajara, el
 * resumen podría calcularse con un esquema y la importación ejecutarse con
 * otro, que es exactamente lo que la pantalla promete que no pasa.
 */
final class ImportSchemas
{
    public const NOMINA = 'nomina';

    /** @var array<int, string> */
    public const DESTINOS = [self::NOMINA, 'sucursales', 'cargos'];

    public function __construct(
        private readonly DirectoryImportSchema $nomina,
        private readonly CatalogImportService $catalogos,
    ) {}

    public function para(string $destino): ImportSchema
    {
        return match ($destino) {
            self::NOMINA => $this->nomina,
            'sucursales', 'cargos' => new CatalogImportSchema($this->catalogos, $destino),
            default => abort(404, 'No sé importar eso.'),
        };
    }
}
