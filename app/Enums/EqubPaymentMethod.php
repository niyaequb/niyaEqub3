<?php

namespace App\Enums;

/**
 * How a contribution was collected.
 *
 * `dashen` is the only gateway method. It replaced `chapa` when the product
 * moved inside the Dashen Bank SuperApp: there is no hosted checkout page any
 * more, the payment is authorised by the SuperApp itself through the mini-app
 * JS bridge, and settlement is confirmed against Dashen rather than Chapa.
 *
 * There is deliberately no `Chapa` case left. Every historical row was
 * rewritten to `dashen` by the 2026_08_27_000001 migration, so a stray 'chapa'
 * string in the column would be data corruption and should fail loudly on cast
 * rather than be quietly accepted.
 */
enum EqubPaymentMethod: string
{
    case Dashen = 'dashen';
    case Offline = 'offline';
    case Manual = 'manual';
}
