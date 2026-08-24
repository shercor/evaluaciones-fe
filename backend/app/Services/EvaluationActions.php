<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\EvaluationStatus;

/**
 * Qué se puede hacer con una evaluación según su estado.
 *
 * En la intranet esto vivía dentro de `index.ctp`, como condiciones
 * encadenadas alrededor de cada botón: `$evaluation->estado === $STATUS['X']
 * && $evaluation->activo && !$evaluation->publicado`, repetido nueve veces.
 * Cualquier cambio de reglas obligaba a releer las nueve.
 *
 * Acá está en un solo lugar y lo consume tanto el frontend —para saber qué
 * botones dibujar— como el backend, que **vuelve a comprobarlo** antes de
 * ejecutar. Angular decide qué mostrar; esto decide qué se permite.
 */
class EvaluationActions
{
    public const OPEN = 'open';
    public const CLOSE = 'close';
    public const PUBLISH = 'publish';
    public const DELETE = 'delete';
    public const RESTORE = 'restore';
    public const EDIT = 'edit';
    public const PARTICIPANTS = 'participants';
    public const CONTINUE_CREATION = 'continue_creation';
    public const PREVIEW_FORMS = 'preview_forms';
    public const MONITOR = 'monitor';
    public const DASHBOARD = 'dashboard';

    /**
     * @param  object  $evaluation  la evaluación tal como la devuelve la API
     * @return array<int, string>
     */
    public function for(object $evaluation): array
    {
        $estado = EvaluationStatus::tryFromLabel($evaluation->estado ?? null);
        $activa = (bool) ($evaluation->activo ?? true);
        $publicada = (bool) ($evaluation->publicado ?? false);

        // Una evaluación desactivada solo se puede reactivar.
        if (! $activa) {
            return [self::RESTORE];
        }

        // Mientras la API prepara las tareas no se ofrece nada: cualquier
        // acción competiría con un proceso en curso.
        if ($estado === null || $estado->isTransient()) {
            return [];
        }

        $acciones = match ($estado) {
            EvaluationStatus::CREATING => [self::CONTINUE_CREATION],

            EvaluationStatus::NEVER_PUBLISHED => [
                self::OPEN,
                self::EDIT,
                self::PARTICIPANTS,
                self::PREVIEW_FORMS,
            ],

            EvaluationStatus::IN_PROCESS => [
                self::CLOSE,
                self::EDIT,
                self::PARTICIPANTS,
                self::MONITOR,
                self::DASHBOARD,
            ],

            EvaluationStatus::FINISHED => array_merge(
                // Se puede reabrir mientras no se hayan publicado los
                // resultados. Publicar es lo que cierra la puerta.
                $publicada ? [] : [self::OPEN, self::PUBLISH],
                [self::MONITOR, self::DASHBOARD],
            ),

            default => [],
        };

        // Borrar es desactivar, y una evaluación terminada no se desactiva:
        // su historial tiene que seguir consultable.
        if ($estado !== EvaluationStatus::FINISHED) {
            $acciones[] = self::DELETE;
        }

        return array_values(array_unique($acciones));
    }

    public function allows(object $evaluation, string $accion): bool
    {
        return in_array($accion, $this->for($evaluation), true);
    }

    /**
     * Motivo por el que una acción no está disponible, para poder explicarlo
     * en vez de devolver un 403 mudo.
     */
    public function reasonFor(object $evaluation, string $accion): string
    {
        $estado = EvaluationStatus::tryFromLabel($evaluation->estado ?? null);
        $activa = (bool) ($evaluation->activo ?? true);

        if (! $activa) {
            return 'La evaluación está desactivada. Reactivala primero.';
        }

        if ($estado?->isTransient()) {
            return 'La evaluación se está preparando. Esperá a que termine el proceso.';
        }

        if ($accion === self::PUBLISH && (bool) ($evaluation->publicado ?? false)) {
            return 'Los resultados de esta evaluación ya fueron publicados.';
        }

        $etiqueta = $estado?->label() ?? 'desconocido';

        return "No se puede realizar esta acción con la evaluación en estado «{$etiqueta}».";
    }
}
