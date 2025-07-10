<?php

return [
    
    'paths' => ['api/*','sanctum/csrf-cookie','*'],

    'allowed_methods' => ['*'],

    'allowed_origins' => [
        'http://localhost:82',
        'https://abdallahweb.com',
        'https://admin.abdallahweb.com',
    ],

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['*'],

    'exposed_headers' => [],

    'max_age' => 0,

    'supports_credentials' => true,

];
