<?php

declare(strict_types=1);

namespace App\Support\E360\Resources;

use App\Support\E360\E360Client;
use App\Support\E360\E360Response;

/**
 * Plano central de la API: alta y consulta de empresas.
 *
 * Es el único recurso que usa el token central en vez del token del tenant.
 */
class TenantsApi
{
    public function __construct(private readonly E360Client $client) {}

    public function all(): E360Response
    {
        return $this->client->central('GET', '/api/tenants');
    }

    public function show(string $codename): E360Response
    {
        return $this->client->central('GET', "/api/tenants/{$codename}");
    }

    /**
     * Da de alta la empresa en Evaluación 360.
     *
     * Equivale a `initializeModule()` en la intranet.
     */
    public function register(string $domain, string $token): E360Response
    {
        return $this->client->central('POST', '/api/register', body: [
            'domain' => $domain,
            'token' => $token,
        ]);
    }
}
