<?php

declare(strict_types=1);

namespace App\Support\E360;

/**
 * Respuesta de la API de Evaluación 360.
 *
 * La API contesta siempre con la misma envoltura: {status, message, data, meta}.
 * La intranet devolvía el stdClass crudo de json_decode y cada llamador
 * comprobaba `->status == 'error'` a mano, con lo que un fallo de red se veía
 * igual que un 404 y las respuestas malformadas explotaban al desreferenciar.
 * Acá el resultado es explícito y siempre del mismo tipo.
 */
final class E360Response
{
    private function __construct(
        public readonly bool $ok,
        public readonly ?string $message,
        public readonly mixed $data,
        public readonly array $meta,
        public readonly ?int $httpStatus,
        public readonly ?string $errorKind,
    ) {}

    public static function success(mixed $data, ?string $message, array $meta, int $httpStatus): self
    {
        return new self(true, $message, $data, $meta, $httpStatus, null);
    }

    /**
     * @param  string  $kind  http | connection | malformed
     */
    public static function failure(string $message, string $kind, ?int $httpStatus = null, mixed $data = null): self
    {
        return new self(false, $message, $data, [], $httpStatus, $kind);
    }

    public function failed(): bool
    {
        return ! $this->ok;
    }

    /**
     * Una clave concreta de `data`, con valor por defecto.
     *
     * `data` llega como stdClass, así que evita el `??` anidado que se repetía
     * en cada acción de la intranet.
     */
    public function get(string $key, mixed $default = null): mixed
    {
        if (is_object($this->data) && isset($this->data->{$key})) {
            return $this->data->{$key};
        }

        if (is_array($this->data) && array_key_exists($key, $this->data)) {
            return $this->data[$key];
        }

        return $default;
    }

    /**
     * Una clave de `data` que se espera lista; siempre devuelve array.
     */
    public function collect(string $key): array
    {
        $value = $this->get($key, []);

        return is_array($value) ? $value : (array) $value;
    }
}
