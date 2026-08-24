<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Los seis estados de un proceso de evaluación.
 *
 * Espeja el enum del backend de Evaluación 360, que es quien manda: el estado
 * real siempre viene de la API. Esta copia existe para **decidir qué acciones
 * se ofrecen**, y así no tener esa lógica desperdigada por la interfaz como
 * pasa hoy en la intranet, donde `index.ctp` encadena condiciones sobre
 * literales en cada botón.
 *
 * Los colores también los define la API; acá hay respaldos por si una versión
 * futura agrega un estado que este enum todavía no conoce.
 */
enum EvaluationStatus: string
{
    case CREATING = 'en_creacion';
    case PREPARING = 'preparando';
    case NEVER_PUBLISHED = 'nunca_publicado';
    case IN_PROCESS = 'en_proceso';
    case FINISHED = 'finalizado';
    case CANCELED = 'cancelado';

    public function label(): string
    {
        return match ($this) {
            self::CREATING => 'En creación',
            self::PREPARING => 'Preparando',
            self::NEVER_PUBLISHED => 'Lista para abrir',
            self::IN_PROCESS => 'En proceso',
            self::FINISHED => 'Finalizada',
            self::CANCELED => 'Cancelada',
        };
    }

    /**
     * Qué significa el estado, en una línea, para mostrar al administrador.
     */
    public function description(): string
    {
        return match ($this) {
            self::CREATING => 'Todavía se está armando: falta elegir participantes y enviarlos.',
            self::PREPARING => 'La API está generando las tareas de cada participante. Puede tardar varios minutos.',
            self::NEVER_PUBLISHED => 'Ya tiene sus participantes y sus tareas. Falta abrirla para que empiecen a responder.',
            self::IN_PROCESS => 'Los participantes están respondiendo.',
            self::FINISHED => 'Cerrada. Nadie puede responder más.',
            self::CANCELED => 'Cancelada.',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::CREATING => '#ffc107',
            self::PREPARING => '#6f42c1',
            self::NEVER_PUBLISHED => '#6c757d',
            self::IN_PROCESS => '#0d6efd',
            self::FINISHED => '#198754',
            self::CANCELED => '#dc3545',
        };
    }

    /**
     * ¿Está la API trabajando en segundo plano?
     *
     * Mientras dure, no se ofrece ninguna acción y la interfaz consulta cada
     * tanto para ver si terminó.
     */
    public function isTransient(): bool
    {
        return $this === self::PREPARING;
    }

    public static function tryFromLabel(?string $valor): ?self
    {
        return $valor === null ? null : self::tryFrom($valor);
    }
}
