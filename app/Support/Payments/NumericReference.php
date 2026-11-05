<?php

namespace App\Support\Payments;

use App\Models\EqubPayment;

/**
 * A payment reference a customer can type into a bank app.
 *
 * CBE's core system accepts at most 13 digits, and Niya told CBE its
 * references are always exactly 10 digits with no leading zero
 * (1000000000 to 9999999999), randomly generated and unique. That promise is
 * kept here and nowhere else.
 *
 * Unique across BOTH equb_payments.reference and batch_reference, because
 * settlement resolves a reference by trying one column and then the other: a
 * row reference equal to some batch reference would settle the wrong rows.
 */
final class NumericReference
{
    public const MIN = 1000000000;

    public const MAX = 9999999999;

    public static function generate(): string
    {
        for ($attempt = 0; $attempt < 20; $attempt++) {
            $candidate = (string) random_int(self::MIN, self::MAX);

            $taken = EqubPayment::query()
                ->where('reference', $candidate)
                ->orWhere('batch_reference', $candidate)
                ->exists();

            if (! $taken) {
                return $candidate;
            }
        }

        throw new \RuntimeException('Could not generate a unique payment reference.');
    }

    public static function isValid(string $value): bool
    {
        return (bool) preg_match('/^[1-9]\d{9}$/', $value);
    }
}
