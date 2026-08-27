<?php

namespace App\Enums;

/**
 * How a contribution was collected.
 *
 * Each bank gets its own case, and the case VALUE is the gateway slug used
 * everywhere else — config/payments.php, the API, the client apps. Dashen is
 * the only one live today; CBE, Awash and the rest follow the same pattern.
 *
 * ADDING A BANK
 *
 * Add a case here, then follow the checklist in config/payments.php. This enum
 * and that config file are the only two places a bank is named. Validation,
 * the admin filter and the API's provider list are all derived, so a case with
 * no configured gateway behind it is simply never offered rather than being
 * offered and failing.
 *
 * WHY THE VALUES CAN NEVER CHANGE
 *
 * They are stored verbatim in equb_payments.payment_method and carried by
 * every historical row. Renaming one would orphan every contribution collected
 * through it. `chapa` was rewritten to `dashen` by the 2026_08_27_000001
 * migration precisely so that no unknown value survives to be cast here.
 *
 * There is deliberately no `Chapa` case: a stray 'chapa' in the column would
 * now be data corruption and should fail loudly on cast rather than being
 * quietly accepted.
 */
enum EqubPaymentMethod: string
{
    // ----- Banks -------------------------------------------------------
    case Dashen = 'dashen';

    // ----- No gateway involved -----------------------------------------

    /** Cash or a transfer recorded by an agent. Settles on creation. */
    case Offline = 'offline';

    /** Recorded by an operator in the admin panel. Settles on creation. */
    case Manual = 'manual';

    /**
     * Whether this method goes through a bank.
     *
     * The distinction that matters operationally: a gateway payment is created
     * pending and settles asynchronously on a verified notification, while
     * offline and manual are settled the moment they are recorded. Reports and
     * reconciliation should split on this rather than on a list of bank names,
     * which would need editing every time a bank is added.
     */
    public function isGateway(): bool
    {
        return ! in_array($this, [self::Offline, self::Manual], true);
    }

    /** Settles the instant it is recorded, with no bank round trip. */
    public function settlesImmediately(): bool
    {
        return ! $this->isGateway();
    }

    /**
     * Every bank case.
     *
     * @return array<int, self>
     */
    public static function gateways(): array
    {
        return array_values(array_filter(
            self::cases(),
            fn (self $m): bool => $m->isGateway()
        ));
    }

    /**
     * A display label, preferring the bank's configured name.
     *
     * Falls back to the case name so a bank that is registered in the enum but
     * not yet in config still reads sensibly in the admin panel instead of
     * showing a bare slug.
     */
    public function label(): string
    {
        return config("payments.gateways.{$this->value}.name") ?? $this->name;
    }
}
