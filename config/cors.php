<?php

/*
|--------------------------------------------------------------------------
| Cross-Origin Resource Sharing (CORS)
|--------------------------------------------------------------------------
|
| Identical to Laravel's built-in defaults - the file did not exist before, so
| those defaults were in force - with ONE change: max_age.
|
| WHY max_age MATTERS FOR THE MINI APP
|
| The web build of the app runs at https://app.niya-et.com and calls this API
| at https://cms.niya-et.com: a different origin, so the browser sends an
| OPTIONS "preflight" before every request that carries the Authorization
| header or a JSON body - which is nearly every request the app makes. With
| max_age 0 the browser may not remember the answer, so every single call
| cost two round trips instead of one, the first of them through the full
| Laravel stack. On a mobile connection inside the Dashen SuperApp that
| doubled the time every screen spent loading.
|
| 7200 seconds lets the browser reuse a preflight answer for two hours, which
| is also the most Chromium - and so the SuperApp's WebView - will honour.
|
| After deploying: php artisan config:cache (the server caches config).
|
*/

return [

    'paths' => ['api/*', 'sanctum/csrf-cookie'],

    'allowed_methods' => ['*'],

    'allowed_origins' => ['*'],

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['*'],

    'exposed_headers' => [],

    'max_age' => 7200,

    'supports_credentials' => false,

];
