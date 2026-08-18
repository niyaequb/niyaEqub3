<?php

/*
|--------------------------------------------------------------------------
| API Service Metadata
|--------------------------------------------------------------------------
|
| Values surfaced by the API service index (GET /api) and health check
| (GET /api/health). These are the first things a partner sees when they
| point a browser or a monitor at the base URL, so keep them accurate.
|
*/

return [

    /*
    | Public-facing service name. Deliberately not config('app.name'), which
    | is used for internal framework concerns and mail headers.
    */
    'name' => env('API_SERVICE_NAME', 'Niya Umrah Equb API'),

    /*
    | Specification version this deployment implements. Bump it in the same
    | commit that changes the published contract, never separately.
    */
    'version' => env('API_VERSION', '2.1'),

    /*
    | Where an integrator should go for the full specification. Returned in
    | the service index and in 404 responses so a wrong path is self-correcting.
    */
    'documentation_url' => env('API_DOCS_URL', 'https://niya-et.com/developers'),

    /*
    | Contact address for integration support.
    */
    'support_email' => env('API_SUPPORT_EMAIL', 'support@niya-et.com'),

];
