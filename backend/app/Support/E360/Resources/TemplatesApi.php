<?php

declare(strict_types=1);

namespace App\Support\E360\Resources;

use App\Support\E360\E360Client;
use App\Support\E360\E360Response;

/**
 * Plantillas de evaluación y sus formularios.
 *
 * Las plantillas no se crean desde este portal: se eligen y se previsualizan.
 */
class TemplatesApi
{
    public function __construct(private readonly E360Client $client) {}

    /** Todas las plantillas con sus tipos de formulario. */
    public function withForms(): E360Response
    {
        return $this->client->tenant('GET', '/api/templates/forms');
    }

    /** Previsualización de las preguntas de una plantilla. */
    public function questionsPreview(int $templateId): E360Response
    {
        return $this->client->tenant('GET', "/api/templates/{$templateId}/previsualizacion_preguntas");
    }
}
