<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Import;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * El resumen de una carga de nómina, tal como lo muestra la pantalla.
 *
 * Vive acá y no dentro de un controlador porque lo devuelven dos: el de la
 * planilla con formato propio y el de la homologada. Si cada uno armara el
 * suyo, la pantalla tendría que saber de cuál vino la respuesta.
 *
 * @mixin Import
 */
class ImportResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'filename' => $this->filename,
            // Qué se cargó: «nomina», «sucursales» o «cargos».
            'destino' => $this->destino,
            'status' => $this->status,
            'rows_total' => $this->rows_total,
            'rows_created' => $this->rows_created,
            'rows_updated' => $this->rows_updated,
            'rows_failed' => $this->rows_failed,
            // Filas que vinieron marcadas como inactivas y no había a quién
            // dar de baja: ni entran ni son un rechazo.
            'rows_skipped' => $this->rows_skipped,
            'rows_deactivated' => $this->rows_deactivated,
            'rows_reactivated' => $this->rows_reactivated,
            'error' => $this->error,
            // Con qué homologación se cargó, o `null` si vino con el formato
            // del sistema. La pantalla lo usa para distinguir las dos cargas
            // en el historial y para poder mostrar qué se conectó con qué.
            'mapping' => $this->mapping,
            'has_passwords' => $this->rowsWithPassword()->exists(),
            'created_at' => $this->created_at?->toIso8601String(),
            'user' => $this->relationLoaded('user') && $this->user
                ? trim($this->user->name.' '.$this->user->lastname)
                : null,
        ];
    }
}
