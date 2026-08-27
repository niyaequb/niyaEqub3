<?php

use App\Services\Payments\Gateways\DashenGateway;

return [

    /*
    |--------------------------------------------------------------------------
    | Payment gateways
    |--------------------------------------------------------------------------
    |
    | Niya collects contributions through banks, and there will be several:
    | Dashen first, then CBE, Awash and the rest. This file is the register of
    | them. PaymentGatewayManager reads it; nothing else in the application
    | knows which banks exist.
    |
    | HOW TO ADD A BANK
    |
    |   1. Add a case to App\Enums\EqubPaymentMethod with the slug as its
    |      value. The slug is what lands in equb_payments.payment_method, so
    |      choose it once and never change it — historical rows carry it.
    |   2. Write the gateway class. If the bank uses the fabric scheme
    |      (timestamp / nonce_str / method / version / biz_content, RSA `sign`,
    |      HMAC `confirmpayload`) extend FabricGateway and you will mostly be
    |      declaring names. Otherwise implement PaymentGateway directly.
    |   3. Add a block below.
    |   4. Add the env keys. Every gateway reads its own config through its
    |      `env_prefix`, so DASHEN_APP_SECRET, CBE_APP_SECRET and so on never
    |      collide.
    |   5. Add a client bridge in the apps — see
    |      Mobile/lib/core/service/payments/payment_bridge.dart.
    |
    | Nothing else changes. Route registration, validation rules, the admin
    | filter, the settings screen and the API's provider list are all derived
    | from this array.
    |
    | A gateway that is registered but not fully configured is reported as
    | unavailable rather than offered and then failing at the moment someone
    | tries to pay — see PaymentGateway::isConfigured().
    |
    */

    'gateways' => [

        'dashen' => [
            'class' => DashenGateway::class,
            'name' => 'Dashen Bank',

            // Prefixes every env key this gateway reads: DASHEN_APP_SECRET,
            // DASHEN_MERCHANT_CODE, and so on.
            'env_prefix' => 'DASHEN',

            // Which client-side bridge authorises the payment. The apps look
            // this up to decide how to present the order; 'superapp' means the
            // order is handed to a host application over a JS bridge rather
            // than opened as a web checkout.
            'client' => [
                'kind' => 'superapp',
                // The global object the mini app talks to.
                'bridge' => 'dashenbanksuperapp',
            ],

            // Headers the bank may sign its settlement notification with. All
            // are tried; the first one present is checked. Listing more than
            // one costs nothing and saves an outage when a bank quietly
            // renames its header.
            'signature_headers' => [
                'x-dashen-signature',
                'x-api-signature',
                'x-signature',
            ],
        ],

        /*
        | Coming next. Left here as a worked example rather than as dead
        | config: this is the whole diff for a bank on the fabric scheme, and
        | it is commented out only because the gateway class and credentials
        | do not exist yet. Uncommenting it without step 1 and step 2 above
        | will fail loudly at boot, which is the intended behaviour.
        |
        | 'cbe' => [
        |     'class' => CbeGateway::class,
        |     'name' => 'Commercial Bank of Ethiopia',
        |     'env_prefix' => 'CBE',
        |     'client' => ['kind' => 'superapp', 'bridge' => 'cbebirrsuperapp'],
        |     'signature_headers' => ['x-cbe-signature'],
        | ],
        |
        | 'awash' => [
        |     'class' => AwashGateway::class,
        |     'name' => 'Awash Bank',
        |     'env_prefix' => 'AWASH',
        |     'client' => ['kind' => 'superapp', 'bridge' => 'awashsuperapp'],
        |     'signature_headers' => ['x-awash-signature'],
        | ],
        */

    ],

    /*
    |--------------------------------------------------------------------------
    | Default gateway
    |--------------------------------------------------------------------------
    |
    | Used when a client creates a contribution without naming a provider.
    | Every current client names one, so this is a compatibility floor rather
    | than a routing decision.
    |
    */

    'default' => env('PAYMENTS_DEFAULT_GATEWAY', 'dashen'),

    /*
    |--------------------------------------------------------------------------
    | Currency
    |--------------------------------------------------------------------------
    |
    | Ethiopian Birr throughout. There is no multi-currency settlement, and
    | amounts are decimal(12,2) end to end — never binary floats, which drift
    | at the cent level across a settlement window.
    |
    */

    'currency' => 'ETB',

];
