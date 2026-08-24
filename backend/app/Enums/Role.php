<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Rol de una persona dentro del portal.
 *
 * Replica la distinción de la intranet, que separa al personal de Idea Uno de
 * los administradores del cliente. No es cosmética: `updatePersonalEvaluationUsers`
 * arma el padrón con `user_type_id IS NOT SUPER_ADMINISTRATOR`, o sea que un
 * super administrador nunca es evaluado.
 */
enum Role: string
{
    case SUPER_ADMIN = 'super_admin';
    case ADMIN = 'admin';
    case COLLABORATOR = 'collaborator';

    public function label(): string
    {
        return match ($this) {
            self::SUPER_ADMIN => 'Super administrador',
            self::ADMIN => 'Administrador',
            self::COLLABORATOR => 'Colaborador',
        };
    }

    /**
     * ¿Entra al portal de administración?
     */
    public function isAdministrative(): bool
    {
        return $this === self::SUPER_ADMIN || $this === self::ADMIN;
    }

    /**
     * ¿Puede ser participante de una evaluación?
     *
     * Los administradores del cliente sí — también responden su evaluación.
     * Los super administradores no: son personal externo a la empresa.
     */
    public function isEvaluable(): bool
    {
        return $this !== self::SUPER_ADMIN;
    }

    /**
     * Ruta donde aterriza al iniciar sesión.
     */
    public function home(): string
    {
        return $this->isAdministrative() ? '/admin' : '/portal';
    }

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
