<?php

use Knuckles\Scribe\Extracting\Strategies;
use Knuckles\Scribe\Config\Defaults;
use Knuckles\Scribe\Config\AuthIn;
use function Knuckles\Scribe\Config\{removeStrategies, configureStrategy};

// Prevent crashes in production by skipping this config if Scribe is not installed.
if (!class_exists(Defaults::class)) {
    return [];
}

return [
    'title' => 'Niya Umrah Equb API',

    'description' => 'REST API for the Niya Umrah Equb digital rotating-savings platform. '
        .'Covers member participation, private Group Equbs, contribution collection and settlement, '
        .'the agent network, and platform administration.',

    'intro_text' => <<<'INTRO'
        This reference is generated directly from the running codebase, so it always reflects what the
        API actually does rather than what it was once documented to do.

        ## Base URL

        All paths below are relative to `https://cms.niya-et.com/api`.

        ## Authentication

        The API uses signed JSON Web Tokens presented as a bearer credential. There are no API keys.

        1. `POST /auth/send-otp` — a four-digit code is sent by SMS. Keep the returned `verificationId`.
        2. `POST /auth/verify-otp` — confirm the code.
        3. `POST /auth/register` — create the account. **This returns `token: null` by design.**
        4. `POST /auth/login` — exchange credentials for a usable bearer token.

        Send it on every protected request as `Authorization: Bearer <token>`.

        Access tokens live 60 minutes and may be refreshed for up to 14 days from issue via
        `POST /auth/refresh`. Refresh proactively rather than waiting for a 401.

        ## Roles

        Every account holds exactly one role, and the roles do not overlap. A `member` token receives
        HTTP 403 from every route under `/agent` and `/admin`, and vice versa. An integration needing
        two capabilities needs two accounts.

        ## Response shape

        Most endpoints return `status`, a human-readable `message`, and the payload under `data`.
        Paginated collections add a sibling `meta` object with `current_page`, `last_page`, `per_page`
        and `total`. A few older endpoints deviate; treat the HTTP status code as authoritative and the
        presence of the expected payload key as the success signal.

        ## Errors

        Errors return `status: "error"` with a `message` written for the end user. Validation failures
        add a field-keyed `errors` map. A `422` may be either a schema failure (carries `errors`) or a
        business-rule refusal (carries only `message`).

        ## Money

        All amounts are Ethiopian Birr with two decimal places. Some endpoints return them as decimal
        strings and others as JSON numbers, so parse defensively and use a decimal type for arithmetic.

        ## Payments

        Every contribution is collected through the Chapa gateway. A contribution is created in the
        `pending` state and becomes `paid` only once the gateway callback has been received and
        independently verified. **Neither the 201 response nor the payer's return from the hosted
        checkout page is proof of settlement** — poll the payment record.
    INTRO,

    // Production URL, not config('app.url'), which points at localhost in local environments.
    'base_url' => env('SCRIBE_BASE_URL', 'https://cms.niya-et.com'),

    'routes' => [
        [
            'match' => [
                'prefixes' => ['api/*'],
                'domains' => ['*'],
            ],

            'include' => [
                // The service index sits at exactly `api`, which `api/*` does not match.
                'GET /api',
            ],

            'exclude' => [],
        ],
    ],

    /*
     * Static output, deliberately.
     *
     * Scribe is a require-dev package, so a production `composer install --no-dev` leaves it absent
     * and the `laravel` type's auto-registered /docs route would never be registered. Static output
     * is plain HTML, JSON and YAML in public/docs, served by the web server with no runtime
     * dependency. Generate locally or in CI, commit the output, deploy it.
     */
    'type' => 'static',

    'theme' => 'default',

    'static' => [
        'output_path' => 'public/docs',
    ],

    'laravel' => [
        'add_routes' => false,
        'docs_url' => '/docs',
        'assets_directory' => null,
        'middleware' => [],
    ],

    'external' => [
        'html_attributes' => [],
    ],

    'try_it_out' => [
        // Lets a partner exercise a real endpoint from the docs page. Requires CORS to permit
        // the docs origin; disable if that is not acceptable for your deployment.
        'enabled' => true,
        'base_url' => env('SCRIBE_BASE_URL', 'https://cms.niya-et.com'),
        'use_csrf' => false,
        'csrf_url' => '/sanctum/csrf-cookie',
    ],

    'auth' => [
        'enabled' => true,

        // Most endpoints are protected, but the public onboarding and configuration routes are not,
        // so endpoints opt in with a class- or method-level @authenticated tag.
        'default' => false,

        'in' => AuthIn::BEARER->value,
        'name' => 'Authorization',
        'use_value' => env('SCRIBE_AUTH_KEY'),
        'placeholder' => '{YOUR_BEARER_TOKEN}',
        'extra_info' => 'Obtain a token from <code>POST /auth/login</code>. Tokens expire after '
            .'60 minutes and can be refreshed for up to 14 days via <code>POST /auth/refresh</code>.',
    ],

    'example_languages' => [
        'bash',
        'javascript',
        'php',
        'python',
    ],

    'postman' => [
        'enabled' => true,
        'overrides' => [
            'info.version' => '2.1.0',
        ],
    ],

    'openapi' => [
        'enabled' => true,
        'version' => '3.0.3',
        'overrides' => [
            'info.version' => '2.1.0',
            'info.contact.name' => 'Niya Umrah Equb Engineering',
            'info.contact.email' => 'support@niya-et.com',
        ],
        'generators' => [],
    ],

    'groups' => [
        'default' => 'Endpoints',

        // Presentation order. Anything not listed is appended alphabetically, so adding a new
        // @group without touching this list is safe.
        'order' => [
            'Service',
            'Authentication',
            'Account',
            'Platform Configuration',
            'Member · Equb Catalogue',
            'Member · Memberships',
            'Member · Contributions',
            'Member · Draws',
            'Member · Group Equb',
            'Member · Invitations',
            'Member · Directory',
            'Agent',
            'Admin · Accounts',
            'Admin · Members',
            'Admin · Equb Packages',
            'Admin · Equb Groups',
            'Admin · Memberships',
            'Admin · Contributions',
            'Admin · Draws',
            'Admin · Group Equb Moderation',
        ],
    ],

    'logo' => false,

    'last_updated' => 'Last updated: {date:F j, Y}',

    'examples' => [
        'faker_seed' => 1234,
        'models_source' => ['factoryCreate', 'factoryMake', 'databaseFirst'],
    ],

    'strategies' => [
        'metadata' => [
            ...Defaults::METADATA_STRATEGIES,
        ],
        'headers' => [
            ...Defaults::HEADERS_STRATEGIES,
            Strategies\StaticData::withSettings(data: [
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
            ]),
        ],
        'urlParameters' => [
            ...Defaults::URL_PARAMETERS_STRATEGIES,
        ],
        'queryParameters' => [
            ...Defaults::QUERY_PARAMETERS_STRATEGIES,
        ],
        'bodyParameters' => [
            ...Defaults::BODY_PARAMETERS_STRATEGIES,
        ],

        /*
         * Response calls are removed on purpose.
         *
         * They execute real requests during generation. Because almost every route here requires a
         * bearer token, the calls would return 401 and Scribe would publish those as the documented
         * responses. Explicit @response tags in the controllers are deterministic and correct.
         */
        'responses' => removeStrategies(
            Defaults::RESPONSES_STRATEGIES,
            [Strategies\Responses\ResponseCalls::class]
        ),

        'responseFields' => [
            ...Defaults::RESPONSE_FIELDS_STRATEGIES,
        ],
    ],

    'database_connections_to_transact' => [config('database.default')],

    'fractal' => [
        'serializer' => null,
    ],
];
