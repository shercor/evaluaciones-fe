<?php

declare(strict_types=1);

namespace App\Support\E360;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Único punto por el que este servicio habla con la API de Evaluación 360.
 *
 * Porta `App\Utility\ApiEvaluacion360` de la intranet, con tres diferencias:
 *
 *  - No es estático ni necesita `init()`: se resuelve por el contenedor y lee
 *    la configuración al construirse.
 *  - Distingue un fallo de conexión de un error de la API. La versión original
 *    devolvía en ambos casos un objeto con `status = 'error'`, así que un
 *    backend caído era indistinguible de un «no encontrado».
 *  - Nunca registra el token en el log.
 */
class E360Client
{
    private string $baseUrl;

    private string $apiHost;

    public function __construct(private readonly array $config)
    {
        $this->baseUrl = rtrim((string) ($config['base_url'] ?? ''), '/');
        $this->apiHost = $this->hostFromUrl($this->baseUrl);
    }

    /**
     * Petición al plano tenant: todo lo que pertenece a una empresa.
     */
    public function tenant(
        string $method,
        string $endpoint,
        array $query = [],
        array $body = [],
    ): E360Response {
        return $this->send($method, $endpoint, $this->tenantHeaders(), $query, $body);
    }

    /**
     * Petición al plano central: alta y consulta de tenants.
     */
    public function central(
        string $method,
        string $endpoint,
        array $query = [],
        array $body = [],
    ): E360Response {
        return $this->send($method, $endpoint, $this->centralHeaders(), $query, $body);
    }

    /**
     * ¿Está completa la configuración para poder llamar a la API?
     *
     * Equivale a `validateAccessCredentials()`, que en la intranet estaba
     * copiado en tres controladores y se ejecutaba en cada acción. Acá se
     * comprueba al arrancar (ver E360ServiceProvider) y en el comando de
     * diagnóstico.
     *
     * @return array<int, string>  Claves faltantes; vacío si está todo.
     */
    public function missingConfiguration(): array
    {
        $missing = [];

        if ($this->baseUrl === '') {
            $missing[] = 'E360_BASE_URL';
        }
        if (blank($this->config['tenant_codename'] ?? null)) {
            $missing[] = 'E360_TENANT_CODENAME';
        }
        if (blank($this->config['tokens']['central'] ?? null)) {
            $missing[] = 'E360_CENTRAL_TOKEN';
        }
        if (blank($this->config['tokens']['tenant'] ?? null)) {
            $missing[] = 'E360_TENANT_TOKEN';
        }

        return $missing;
    }

    /**
     * El valor que viaja en la cabecera `host`. La API resuelve el tenant por
     * subdominio, así que este string es lo que decide de qué empresa se está
     * hablando.
     */
    public function tenantHost(): string
    {
        return $this->config['tenant_codename'].'.'.$this->apiHost;
    }

    public function baseUrl(): string
    {
        return $this->baseUrl;
    }

    // -----------------------------------------------------------------

    private function tenantHeaders(): array
    {
        return [
            'token' => (string) ($this->config['tokens']['tenant'] ?? ''),
            'host' => $this->tenantHost(),
            'Accept' => 'application/json',
        ];
    }

    private function centralHeaders(): array
    {
        return [
            'token' => (string) ($this->config['tokens']['central'] ?? ''),
            'Accept' => 'application/json',
        ];
    }

    private function send(
        string $method,
        string $endpoint,
        array $headers,
        array $query,
        array $body,
    ): E360Response {
        $method = strtoupper($method);
        $url = $this->baseUrl.'/'.ltrim($endpoint, '/');
        $http = $this->config['http'];

        $request = Http::withHeaders($headers)
            ->timeout($http['timeout'])
            ->connectTimeout($http['connect_timeout'])
            ->retry($http['retries'], $http['retry_delay_ms'], throw: false)
            ->acceptJson();

        if ($query !== []) {
            $url .= '?'.http_build_query($query);
        }

        try {
            $response = in_array($method, ['POST', 'PUT', 'PATCH'], true)
                ? $request->send($method, $url, ['json' => $body])
                : $request->send($method, $url);
        } catch (ConnectionException $e) {
            // La API no respondió. Es distinto de que respondiera un error, y
            // el llamador tiene que poder diferenciarlo para no confundir una
            // caída con un «no existe».
            Log::error('[E360] Sin conexión', [
                'method' => $method,
                'url' => $this->scrub($url),
                'error' => $e->getMessage(),
            ]);

            return E360Response::failure(
                'No se pudo conectar con el servicio de Evaluación 360.',
                'connection',
            );
        }

        return $this->interpret($response, $method, $url);
    }

    private function interpret(Response $response, string $method, string $url): E360Response
    {
        $decoded = json_decode($response->body());

        if (json_last_error() !== JSON_ERROR_NONE) {
            Log::error('[E360] Respuesta malformada', [
                'method' => $method,
                'url' => $this->scrub($url),
                'status' => $response->status(),
                'body' => mb_substr($response->body(), 0, 500),
            ]);

            return E360Response::failure(
                'El servicio de Evaluación 360 devolvió una respuesta ilegible.',
                'malformed',
                $response->status(),
            );
        }

        $message = $decoded->message ?? null;
        $status = $decoded->status ?? null;

        // La API marca el fallo de dos formas: por código HTTP y por el campo
        // `status` de la envoltura. Cualquiera de las dos cuenta.
        if ($response->failed() || $status === 'error') {
            Log::warning('[E360] Error de la API', [
                'method' => $method,
                'url' => $this->scrub($url),
                'status' => $response->status(),
                'message' => $message,
            ]);

            return E360Response::failure(
                $message ?? 'Error del servicio de Evaluación 360.',
                'http',
                $response->status(),
                $decoded->data ?? null,
            );
        }

        return E360Response::success(
            $decoded->data ?? null,
            $message,
            isset($decoded->meta) ? (array) $decoded->meta : [],
            $response->status(),
        );
    }

    /**
     * Host y puerto de una URL, sin esquema.
     *
     * Equivale al helper `UrlHost()` de la intranet: si no hay esquema,
     * devuelve la entrada tal cual, que es como estaba configurado en local.
     */
    private function hostFromUrl(string $url): string
    {
        $parsed = parse_url($url);

        if (isset($parsed['scheme'], $parsed['host'])) {
            return $parsed['host'].(isset($parsed['port']) ? ':'.$parsed['port'] : '');
        }

        return ltrim($url, '/');
    }

    /**
     * Las URLs no llevan credenciales, pero sí pueden llevar nombres en los
     * filtros de búsqueda. Se recorta antes de escribir en el log.
     */
    private function scrub(string $url): string
    {
        return strtok($url, '?') ?: $url;
    }
}
