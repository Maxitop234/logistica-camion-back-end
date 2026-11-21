<?php

return [

    /*
    | La API usa tokens Bearer, sin cookies, así que no se habilitan credenciales.
    | CORS_ALLOWED_ORIGINS: orígenes del panel web separados por coma
    | (p. ej. https://ecoruta-demo.netlify.app). Vacío = ninguno.
    */

    'paths' => ['api/*'],

    'allowed_methods' => ['*'],

    'allowed_origins' => array_values(array_filter(array_map(
        'trim',
        explode(',', (string) env('CORS_ALLOWED_ORIGINS', '')),
    ))),

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['*'],

    'exposed_headers' => [],

    'max_age' => 600,

    'supports_credentials' => false,

];
