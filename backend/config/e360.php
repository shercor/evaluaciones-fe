<?php

return [

    /*
    |--------------------------------------------------------------------------
    | API de Evaluación 360
    |--------------------------------------------------------------------------
    |
    | Este servicio es el único que habla con la API de Evaluación 360. El
    | token del tenant nunca sale de aquí: Angular jamás lo recibe.
    |
    | Equivalencia con la configuración de la intranet, para copiar valores:
    |
    |   CFG_E360_URL           -> E360_BASE_URL   (ahora CON esquema)
    |   CFG_E360_CENTRAL_TOKEN -> E360_CENTRAL_TOKEN
    |   CFG_E360_TENANT_TOKEN  -> E360_TENANT_TOKEN
    |   CFG_INTRANET_CODENAME  -> E360_TENANT_CODENAME
    |
    */

    // A diferencia de la intranet, aquí la URL incluye el esquema. Allá se
    // guardaba sin él y se concatenaba directo, lo que dejaba a Guzzle con
    // una URI sin protocolo.
    'base_url' => env('E360_BASE_URL', 'http://localhost:81'),

    // Identifica a la empresa. La API resuelve el tenant por subdominio, así
    // que este valor termina en la cabecera `host` como {codename}.{host}.
    'tenant_codename' => env('E360_TENANT_CODENAME'),

    'tokens' => [
        // Plano central: alta y consulta de tenants.
        'central' => env('E360_CENTRAL_TOKEN'),
        // Plano tenant: absolutamente todo lo demás.
        'tenant' => env('E360_TENANT_TOKEN'),
    ],

    'http' => [
        // El envío del padrón completo puede tardar. La intranet subía el
        // límite a 300 s en tres lugares distintos por esta misma razón.
        'timeout' => (int) env('E360_TIMEOUT', 300),
        'connect_timeout' => (int) env('E360_CONNECT_TIMEOUT', 10),
        'retries' => (int) env('E360_RETRIES', 2),
        'retry_delay_ms' => (int) env('E360_RETRY_DELAY_MS', 500),
    ],

    /*
    |--------------------------------------------------------------------------
    | Tipos de formulario
    |--------------------------------------------------------------------------
    |
    | En la intranet el 5 de «clima laboral» estaba escrito a mano en seis
    | llamadas distintas. Acá tiene nombre y un solo lugar donde cambiarlo.
    |
    */

    'form_types' => [
        'clima_laboral' => (int) env('E360_FORM_TYPE_CLIMA_LABORAL', 5),
    ],
];
