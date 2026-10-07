<?php

/*
| CORS only matters for browsers. The mobile apps are not affected.
| Only the marketing website (and staging/dev origins you list) may call the API from a browser.
*/
return [
    'paths' => ['api/v2/*'],
    'allowed_methods' => ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'],
    'allowed_origins' => array_values(array_filter(array_map('trim', explode(',', (string) env('CORS_ALLOWED_ORIGINS', 'https://12steptoolkit.com'))))),
    'allowed_origins_patterns' => [],
    'allowed_headers' => ['Accept', 'Authorization', 'Content-Type', 'X-Request-Id', 'X-App-Version', 'X-App-Platform'],
    'exposed_headers' => ['X-Request-Id', 'Retry-After'],
    'max_age' => 3600,
    'supports_credentials' => false,
];
