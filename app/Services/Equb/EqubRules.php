<?php

namespace App\Services\Equb;

use App\Services\SettingsService;

/**
 * The rules currently in force, wherever they were set.
 *
 * config/equb.php holds the defaults. An admin can override any of them from
 * Settings, and this class is the only place that knows both exist — so a
 * number changed in the panel takes effect everywhere at once, and nothing
 * else in the codebase has to remember to look in two places.
 *
 * Settings keys are namespaced `equb.` and stored as plain strings, which is
 * what the global_settings table holds; every read below coerces, because a
 * value typed into a form arrives as "5" and a value from config arrives as
 * 5.0 and the arithmetic must not care which.
 */
class EqubRules
{
    /**
     * Settings keys are namespaced, and the short half is the field name on
     * the Draw Settings form. Keeping the two halves joined here means adding
     * a rule is one entry in editable() rather than a name to remember in
     * three files.
     */
    public const PREFIX = 'equb.';

    /** Per-request memo. These are read dozens of times while a page renders. */
    protected array $memo = [];

    /**
     * Read config only, ignoring what an admin has saved.
     *
     * Set on the clone that defaults() returns, so a settings screen can show
     * "what this would go back to" beside "what it is now".
     */
    protected bool $ignoreOverrides = false;

    public function __construct(protected SettingsService $settings) {}

    // -----------------------------------------------------------------
    // Service fee
    // -----------------------------------------------------------------

    /** The company's cut, as a percentage (5.0 means 5%). */
    public function feePercent(): float
    {
        $value = $this->value('equb.service_fee_percent', config('equb.fee.percent', 5.0));

        // Clamped rather than trusted. A fee above 100% would make every
        // report show the company keeping more than the members paid, and a
        // negative one would show a loss on money that was collected.
        return max(0.0, min(100.0, (float) $value));
    }

    /** The same figure as a multiplier: 5% becomes 0.05. */
    public function feeRate(): float
    {
        return $this->feePercent() / 100;
    }

    /** deducted | added | payout — see config/equb.php. */
    public function feeModel(): string
    {
        $model = (string) $this->value('equb.service_fee_model', config('equb.fee.model', 'deducted'));

        return in_array($model, ['deducted', 'added', 'payout'], true) ? $model : 'deducted';
    }

    /**
     * Our share of a collected amount.
     *
     * The arithmetic differs by model and getting it wrong is a silent
     * overstatement of revenue, so it lives here once rather than at each
     * call site.
     */
    public function feeOn(float $collected): float
    {
        $rate = $this->feeRate();

        if ($rate <= 0.0 || $collected == 0.0) {
            return 0.0;
        }

        return round(match ($this->feeModel()) {
            // The member paid 105 for a 100 round; only the 5 is ours.
            'added' => $collected * $rate / (1 + $rate),
            // Booked at draw time, not on the contribution.
            'payout' => 0.0,
            default => $collected * $rate,
        }, 2);
    }

    /** What is left for the members once our share is taken out. */
    public function memberShareOf(float $collected): float
    {
        return round($collected - $this->feeOn($collected), 2);
    }

    // -----------------------------------------------------------------
    // Arrears
    // -----------------------------------------------------------------

    public function graceDays(): int
    {
        return max(0, (int) $this->value('equb.arrears_grace_days', config('equb.arrears.grace_days', 2)));
    }

    public function warnAt(): int
    {
        return max(1, (int) $this->value('equb.arrears_warn_at', config('equb.arrears.warn_at', 1)));
    }

    public function blockAt(): int
    {
        return max($this->warnAt(), (int) $this->value('equb.arrears_block_at', config('equb.arrears.block_at', 2)));
    }

    public function suspendAt(): int
    {
        return max($this->blockAt(), (int) $this->value('equb.arrears_suspend_at', config('equb.arrears.suspend_at', 3)));
    }

    /** @return array<int, int> Days-overdue boundaries for the ageing report. */
    public function ageingBuckets(): array
    {
        $buckets = config('equb.arrears.ageing_buckets', [30, 60, 90]);

        return array_values(array_filter(array_map('intval', (array) $buckets), fn (int $d): bool => $d > 0));
    }

    // -----------------------------------------------------------------
    // Lottery
    // -----------------------------------------------------------------

    public function baseWeight(): float
    {
        return (float) config('equb.lottery.base_weight', 1.0);
    }

    public function advancePerRound(): float
    {
        return (float) $this->value('equb.lottery_advance_per_round', config('equb.lottery.advance.per_round', 0.25));
    }

    public function advanceMax(): float
    {
        return (float) $this->value('equb.lottery_advance_max', config('equb.lottery.advance.max', 1.5));
    }

    public function streakPerRound(): float
    {
        return (float) $this->value('equb.lottery_streak_per_round', config('equb.lottery.streak.per_round', 0.05));
    }

    public function streakMax(): float
    {
        return (float) $this->value('equb.lottery_streak_max', config('equb.lottery.streak.max', 0.75));
    }

    public function loyaltyPerRound(): float
    {
        return (float) $this->value('equb.lottery_loyalty_per_round', config('equb.lottery.loyalty.per_round', 0.03));
    }

    public function loyaltyMax(): float
    {
        return (float) $this->value('equb.lottery_loyalty_max', config('equb.lottery.loyalty.max', 0.6));
    }

    public function cleanRecordBonus(): float
    {
        return (float) $this->value('equb.lottery_clean_bonus', config('equb.lottery.clean_record_bonus', 0.25));
    }

    public function latePenalty(): float
    {
        return max(0.0, min(1.0, (float) $this->value('equb.lottery_late_penalty', config('equb.lottery.late_penalty', 0.5))));
    }

    public function minPaidRounds(): int
    {
        return max(0, (int) $this->value('equb.lottery_min_paid_rounds', config('equb.lottery.min_paid_rounds', 1)));
    }

    public function minDaysInEqub(): int
    {
        return max(0, (int) $this->value('equb.lottery_min_days', config('equb.lottery.min_days_in_equb', 0)));
    }

    /** 0.25 means no single payer may hold more than a quarter of the pool. */
    public function maxSharePerPayer(): float
    {
        $share = (float) $this->value('equb.lottery_max_payer_share', config('equb.lottery.max_share_per_payer', 0.25));

        // Anything at or above 1 disables the cap, which is a legitimate
        // choice for a small Equb where one family is most of the circle.
        return $share <= 0 ? 1.0 : min(1.0, $share);
    }

    public function groupMaxMembersInArrears(): int
    {
        return max(0, (int) $this->value('equb.lottery_group_max_arrears', config('equb.lottery.group.max_members_in_arrears', 0)));
    }

    public function groupMinEligibleMembers(): int
    {
        return max(1, (int) $this->value('equb.lottery_group_min_members', config('equb.lottery.group.min_eligible_members', 1)));
    }

    public function auditSnapshotLimit(): int
    {
        return max(0, (int) config('equb.lottery.audit_snapshot_limit', 300));
    }

    // -----------------------------------------------------------------

    /**
     * A single figure, admin override first.
     *
     * An override that is blank or a stray empty string is treated as absent.
     * Someone clearing a field in Settings means "go back to the default",
     * not "the late penalty is now zero".
     */
    protected function value(string $key, mixed $default): mixed
    {
        if (array_key_exists($key, $this->memo)) {
            return $this->memo[$key];
        }

        if ($this->ignoreOverrides) {
            return $this->memo[$key] = $default;
        }

        $stored = $this->settings->get($key);

        return $this->memo[$key] = (($stored === null || $stored === '') ? $default : $stored);
    }

    // -----------------------------------------------------------------
    // Editing
    // -----------------------------------------------------------------

    /**
     * Every rule an admin may change, keyed by its form field name.
     *
     * This list is the contract between the settings page and the engine.
     * The page fills its form from it, saves back through it, and previews
     * unsaved values by handing the same keys to overriding() — so a rule
     * cannot end up editable on screen but ignored by the draw, which is the
     * failure mode that makes a settings page worse than no settings page.
     *
     * @return array<string, float|int|string>
     */
    public function editable(): array
    {
        return [
            'service_fee_percent' => $this->feePercent(),
            'service_fee_model' => $this->feeModel(),

            'arrears_grace_days' => $this->graceDays(),
            'arrears_warn_at' => $this->warnAt(),
            'arrears_block_at' => $this->blockAt(),
            'arrears_suspend_at' => $this->suspendAt(),

            'lottery_advance_per_round' => $this->advancePerRound(),
            'lottery_advance_max' => $this->advanceMax(),
            'lottery_streak_per_round' => $this->streakPerRound(),
            'lottery_streak_max' => $this->streakMax(),
            'lottery_loyalty_per_round' => $this->loyaltyPerRound(),
            'lottery_loyalty_max' => $this->loyaltyMax(),
            'lottery_clean_bonus' => $this->cleanRecordBonus(),
            'lottery_late_penalty' => $this->latePenalty(),

            'lottery_min_paid_rounds' => $this->minPaidRounds(),
            'lottery_min_days' => $this->minDaysInEqub(),
            'lottery_max_payer_share' => $this->maxSharePerPayer(),
            'lottery_group_max_arrears' => $this->groupMaxMembersInArrears(),
            'lottery_group_min_members' => $this->groupMinEligibleMembers(),
        ];
    }

    /**
     * The same rules as config ships them — what "restore defaults" restores.
     *
     * A separate instance rather than a static array, so the two can never
     * describe different things: both go through the same accessors and the
     * same clamping.
     */
    public function defaults(): static
    {
        $clone = clone $this;
        $clone->ignoreOverrides = true;
        $clone->memo = [];

        return $clone;
    }

    /**
     * A copy of these rules with some values replaced, saving nothing.
     *
     * What lets the settings page show the effect of a change before it is
     * committed: hand the engine a rules object built from the form state and
     * it scores the preview members under the unsaved numbers.
     *
     * @param  array<string, mixed>  $values  Keyed as editable() is.
     */
    public function overriding(array $values): static
    {
        $clone = clone $this;
        $clone->memo = [];

        foreach ($values as $field => $value) {
            // A blank field means "leave it alone", not "set it to nothing" —
            // a cleared number box would otherwise preview as zero and read
            // as though the bonus had been switched off.
            if ($value === null || $value === '') {
                continue;
            }

            $clone->memo[self::PREFIX.$field] = $value;
        }

        return $clone;
    }

    /**
     * Persist a set of rules.
     *
     * Written through SettingsService rather than straight to the model so
     * the cached copy is dropped at the same moment. Writing the row and
     * leaving the cache alone is how a saved setting appears to have no
     * effect for the next hour.
     *
     * @param  array<string, mixed>  $values  Keyed as editable() is.
     */
    public function apply(array $values): void
    {
        $allowed = array_keys($this->editable());

        foreach ($values as $field => $value) {
            if (! in_array($field, $allowed, true)) {
                continue;
            }

            $this->settings->set(self::PREFIX.$field, is_bool($value) ? ($value ? '1' : '0') : (string) $value);
        }

        $this->memo = [];
    }

    /**
     * Everything at once, for a settings screen or an audit snapshot.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'fee_percent' => $this->feePercent(),
            'fee_model' => $this->feeModel(),
            'grace_days' => $this->graceDays(),
            'warn_at' => $this->warnAt(),
            'block_at' => $this->blockAt(),
            'suspend_at' => $this->suspendAt(),
            'base_weight' => $this->baseWeight(),
            'advance_per_round' => $this->advancePerRound(),
            'advance_max' => $this->advanceMax(),
            'streak_per_round' => $this->streakPerRound(),
            'streak_max' => $this->streakMax(),
            'loyalty_per_round' => $this->loyaltyPerRound(),
            'loyalty_max' => $this->loyaltyMax(),
            'clean_record_bonus' => $this->cleanRecordBonus(),
            'late_penalty' => $this->latePenalty(),
            'min_paid_rounds' => $this->minPaidRounds(),
            'min_days_in_equb' => $this->minDaysInEqub(),
            'max_share_per_payer' => $this->maxSharePerPayer(),
        ];
    }
}
