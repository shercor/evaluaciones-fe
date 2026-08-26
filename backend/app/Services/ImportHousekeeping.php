<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\ImportDraft;
use App\Models\ImportRow;
use Illuminate\Support\Facades\Storage;

/**
 * Lo que hay que tirar de las importaciones cuando ya no sirve.
 *
 * Dos cosas crecen sin techo si nadie las mira: las planillas subidas para
 * homologar que alguien abandonó a mitad de camino, y las contraseñas
 * temporales, que se guardan **en claro** para que el administrador las
 * descargue una vez y las entregue en mano.
 *
 * La segunda no es un problema de espacio sino de riesgo: son unos pocos bytes
 * por fila, pero una tabla con miles de contraseñas de gente real, sin cifrar
 * y sin fecha de caducidad, es exactamente lo que no conviene tener. Después
 * de que la persona entró y las cambió, no vuelven a servir para nada.
 */
final class ImportHousekeeping
{
    /** Donde el controlador de homologación guarda las planillas subidas. */
    private const CARPETA = 'importaciones/borradores';

    /**
     * Tira las planillas subidas que nadie llegó a importar.
     *
     * @return int  cuántas se borraron
     */
    public function borrarBorradores(int $horas): int
    {
        $viejos = ImportDraft::where('created_at', '<', now()->subHours($horas))->get();

        foreach ($viejos as $borrador) {
            Storage::disk('local')->delete($borrador->stored_path);
            $borrador->delete();
        }

        return $viejos->count();
    }

    /**
     * Barre los archivos que quedaron sin dueño.
     *
     * Borrar el borrador borra su archivo, pero hay tres caminos por los que
     * la fila desaparece sin que nadie toque el disco: el borrado en cascada
     * cuando se da de baja a la persona que subió la planilla, el cambio de
     * base de datos —la carpeta es una sola y las filas viven en cada base— y
     * restaurar un respaldo viejo.
     *
     * Por eso el criterio es la **edad del archivo** y no si alguien lo
     * referencia: un borrador vive 24 horas, así que un archivo más viejo que
     * eso no le sirve a nadie, ni siquiera a otra base.
     *
     * @return int  cuántos se borraron
     */
    public function barrerArchivosHuerfanos(int $horas): int
    {
        $disco = Storage::disk('local');
        $limite = now()->subHours($horas)->getTimestamp();
        $borrados = 0;

        foreach ($disco->files(self::CARPETA) as $archivo) {
            if ($disco->lastModified($archivo) < $limite) {
                $disco->delete($archivo);
                $borrados++;
            }
        }

        return $borrados;
    }

    /**
     * Olvida las contraseñas temporales de las cargas viejas.
     *
     * No borra la fila —el registro de qué pasó con cada línea es la auditoría
     * de la importación y eso se queda— sino solo la contraseña.
     *
     * @return int  cuántas se olvidaron
     */
    public function olvidarContrasenas(int $dias): int
    {
        return ImportRow::whereNotNull('temporary_password')
            ->where('created_at', '<', now()->subDays($dias))
            ->update(['temporary_password' => null]);
    }
}
