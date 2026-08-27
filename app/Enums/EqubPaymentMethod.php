<?php

namespace App\Enums;

/**
 * Which bank collected a contribution.
 *
 * Every contribution goes through a bank. Dashen is live; CBE, Awash and the
 * rest follow the same pattern — a case here, then the checklist in
 * config/payments.php.
 *
 * WHY THE VALUES CAN NEVER CHANGE
 *
 * They are stored verbatim in equb_payments.payment_method and carried by
 * every historical row. Renaming one would orphan every contribution collected
 * through it.
 */
enum EqubPaymentMethod: string
{
    // ----- Banks -------------------------------------------------------
    case Dashen = 'dashen';

    // ----- Retired -----------------------------------------------------

    /**
     * @deprecated Cash recorded by an agent. Withdrawn — cannot be created.
     */
    case Offline = 'offline';

    /**
     * @deprecated Recorded by an operator. Withdrawn — cannot be created.
     */
    case Manual = 'manual';

    /**
     * Methods a contribution may actually be created with.
     *
     * Offline and manual are deliberately absent. They were withdrawn as a
     * collection route: every contribution now goes through a bank, so nothing
     * in the admin panel, the API or the apps offers them.
     *
     * THE CASES REMAIN, AND THAT IS NOT AN OVERSIGHT. `payment_method` is cast
     * to this enum, and PHP throws on casting a value the enum does not have.
     * Deleting them would take out every screen that lists a contribution
     * collected before the change — the member's history, the admin register,
     * the reports — not just the payment path. Rewriting those rows to
     * `dashen` instead would be worse: it would state that a bank collected
     * money that was handed over in cash, which is a lie told to whoever
     * reconciles the account later.
     *
     * So they are tombstones. Old rows read correctly; new ones cannot be
     * made.
     *
     * @return array<int, self>
     */
    public static function selectable(): array
    {
        return array_values(array_filter(
            self::cases(),
            fn (self $m): bool => $m->isSelectable()
        ));
    }

    /** Whether a new contribution may be created with this method. */
    public function isSelectable(): bool
    {
        return ! in_array($this, [self::Offline, self::Manual], true);
    }

    /**
     * Whether this method goes through a bank.
     *
     * True for every selectable method. Kept as its own question because the
     * two are distinct: `isSelectable()` is a policy about what may be created
     * now, this is a fact about how the money moved, and reports reading
     * historical rows care about the second.
     */
    public function isGateway(): bool
    {
        return $this->isSelectable();
    }

    /**
     * A display label, preferring the bank's configured name.
     *
     * Retired methods are marked so a historical row is never mistaken for
     * something still on offer.
     */
    public function label(): string
    {
        if (! $this->isSelectable()) {
            return $this->name.' (retired)';
        }

        return config("payments.gateways.{$this->value}.name") ?? $this->name;
    }
}
