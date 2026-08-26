<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Evaluation;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification as Notifier;

/**
 * Reparte un aviso entre el padrón de un proceso.
 *
 * Es un job que encola otros jobs, y no por capricho: las notificaciones son
 * `ShouldQueue`, así que cada envío deja un trabajo propio. Si el recorrido
 * del padrón corriera dentro de la petición HTTP, avisar a un proceso de 7.092
 * personas serían 7.092 inserciones antes de responderle al administrador. Así
 * responde de inmediato y el reparto ocurre atrás.
 *
 * El padrón se recorre **por lotes**. Cargar 7.092 modelos de una es
 * exactamente el error que ya hubo que corregir al armar el padrón.
 *
 * Cada aviso concreto —[NotifyEvaluationOpened], [NotifyEvaluationReminder],
 * [NotifyResultsPublished]— solo dice a quién le toca y qué se manda.
 */
abstract class NotifyRoster implements ShouldQueue
{
    use Queueable;

    /** Cuántas personas se traen de la base por vuelta. */
    private const LOTE = 500;

    public function __construct(
        protected readonly int $e360Id,
        protected readonly string $nombre,
    ) {}

    /**
     * A quiénes va este aviso.
     *
     * Es estática porque el controlador la usa para contar destinatarios
     * **antes** de encolar: el administrador se entera del alcance en el
     * momento, no cuando la cola termine.
     */
    abstract public static function audiencia(int $evaluationId): Builder;

    /** Cuántos del mismo conjunto quedan afuera por no tener casilla. */
    abstract public static function sinCorreo(int $evaluationId): int;

    /** El aviso que se reparte. */
    abstract protected function aviso(): Notification;

    public function handle(): void
    {
        $evaluacion = Evaluation::where('e360_id', $this->e360Id)->first();

        if (! $evaluacion) {
            Log::warning('Aviso sin espejo local', [
                'aviso' => class_basename(static::class),
                'e360_id' => $this->e360Id,
            ]);

            return;
        }

        $avisados = 0;

        // La columna del `chunkById` va calificada y con alias: al unir con
        // `evaluation_users`, un `id` a secas es ambiguo para MySQL y la
        // consulta ni siquiera llega a prepararse.
        static::audiencia($evaluacion->id)->chunkById(
            self::LOTE,
            function ($personas) use (&$avisados): void {
                // `Notifier::send()` sobre la colección entera encola un
                // trabajo por persona igual que un `foreach`, pero con una
                // sola llamada al despachador.
                Notifier::send($personas, $this->aviso());
                $avisados += $personas->count();
            },
            'users.id',
            'id',
        );

        Log::info('Aviso repartido', [
            'aviso' => class_basename(static::class),
            'e360_id' => $this->e360Id,
            'avisados' => $avisados,
        ]);
    }
}
