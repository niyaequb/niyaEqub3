<?php

namespace App\Services\Equb;

use App\Enums\EqubMembershipStatus;
use App\Models\EqubMembership;
use App\Support\Equb\DrawEntry;
use App\Support\Equb\MembershipStanding;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Who may win, how much of the pool each of them holds, and which one the
 * draw lands on.
 *
 * WHY THE ODDS ARE NOT EQUAL
 *
 * A flat lottery treats the member who paid six rounds in advance and the one
 * who pays on the last possible day as the same person. That is not neutral —
 * it is a standing subsidy paid by the punctual to the late, and over a few
 * rounds everybody works out that paying early buys nothing.
 *
 * So four behaviours move the odds, each capped so none of them can take over:
 *
 *   advance       rounds already paid beyond what is due
 *   streak        consecutive rounds settled on time
 *   loyalty       rounds waited without ever having won
 *   clean record  never late, nothing owed
 *
 * and one moves them down:
 *
 *   late          behind, but not far enough to be removed — halved odds
 *
 * THREE WAYS TO BE OUT OF IT ENTIRELY
 *
 *   arrears       two rounds behind: removed until they catch up. An Equb
 *                 pays a member the circle's money; handing it to somebody
 *                 who has stopped contributing is how the circle collapses.
 *   too new       joined and won before contributing anything is the cheapest
 *                 fraud available, and it costs a full payout.
 *   suspended     an admin has blocked this place by hand.
 *
 * ONE PERSON, MANY PLACES
 *
 * A member may hold their own place plus several "My Responsibility People"
 * places. Each is a real contribution and a real entry, so each earns its own
 * weight — but their combined share of the pool is capped, or a member who
 * took on eight places would hold most of the draw without breaking any rule.
 *
 * A DRAW THAT CAN BE RE-RUN
 *
 * The winner is picked by a seed recorded on the draw. Given the seed and the
 * entries — both stored — anyone can recompute the result and get the same
 * answer. A lottery nobody can re-derive is trustworthy exactly until the
 * first person asks how it was decided.
 */
class EqubLotteryEngine
{
    public function __construct(
        protected EqubRules $rules,
        protected EqubStandingService $standings,
    ) {}

    // -----------------------------------------------------------------
    // Building the pool
    // -----------------------------------------------------------------

    /**
     * Turn memberships into draw entries: eligible ones with a weight,
     * ineligible ones with a reason.
     *
     * @param  Collection<int, EqubMembership>  $memberships
     * @return Collection<int, DrawEntry>  Ordered by membership id, always.
     */
    public function entries(Collection $memberships, ?CarbonImmutable $asOf = null): Collection
    {
        $asOf ??= CarbonImmutable::now();
        $standings = $this->standings->forMany($memberships, $asOf);

        $entries = $memberships
            ->map(function (EqubMembership $membership) use ($standings, $asOf): DrawEntry {
                $standing = $standings->get($membership->id);

                return $this->entry($membership, $standing, $asOf);
            })
            // Sorted by id so the cumulative walk in pick() is deterministic.
            // Without a fixed order the same seed could produce a different
            // winner on a different day, and the audit trail would be a lie.
            ->sortBy(fn (DrawEntry $e): int => $e->membershipId)
            ->values();

        $this->capPayerShare($entries);
        $this->assignOdds($entries);

        return $entries;
    }

    protected function entry(EqubMembership $membership, ?MembershipStanding $standing, CarbonImmutable $asOf): DrawEntry
    {
        $name = $membership->displayName();

        if (! $standing) {
            return new DrawEntry(
                membershipId: (int) $membership->id,
                payerMemberId: $membership->payerMemberId(),
                name: $name,
                standing: $this->emptyStanding($membership),
                eligible: false,
                weight: 0.0,
                reasons: [__('filament.lottery.reason_no_standing')],
            );
        }

        $reasons = $this->blockingReasons($membership, $standing, $asOf);

        if ($reasons !== []) {
            return new DrawEntry(
                membershipId: (int) $membership->id,
                payerMemberId: $membership->payerMemberId(),
                name: $name,
                standing: $standing,
                eligible: false,
                weight: 0.0,
                reasons: $reasons,
            );
        }

        $weight = $this->weightFor($membership, $standing);

        return new DrawEntry(
            membershipId: (int) $membership->id,
            payerMemberId: $membership->payerMemberId(),
            name: $name,
            standing: $standing,
            eligible: true,
            weight: $weight,
            reasons: $this->weightNotes($standing),
            rawWeight: $weight,
        );
    }

    /**
     * Every reason this place cannot win this round, most serious first.
     *
     * @return array<int, string>
     */
    protected function blockingReasons(EqubMembership $membership, MembershipStanding $standing, CarbonImmutable $asOf): array
    {
        $reasons = [];

        if ($membership->status !== EqubMembershipStatus::Active) {
            $reasons[] = __('filament.lottery.reason_not_active');
        }

        if ($membership->has_won) {
            $reasons[] = __('filament.lottery.reason_already_won');
        }

        // Set by an operator, not by the schedule. Investigating a suspected
        // duplicate account or a disputed payment has to be able to take a
        // place out of the draw immediately, without editing anybody's
        // payment history to do it.
        $blockedUntil = $membership->draw_blocked_until
            ? CarbonImmutable::instance($membership->draw_blocked_until)
            : null;

        if ($blockedUntil && $blockedUntil->greaterThan($asOf)) {
            $reasons[] = trim(__('filament.lottery.reason_admin_block').' '.($membership->draw_block_reason ?? ''));
        }

        $reasons = array_merge($reasons, match ($standing->status) {
            MembershipStanding::NEVER_PAID => [__('filament.lottery.reason_never_paid', [
                'rounds' => $standing->roundsDue,
            ])],
            MembershipStanding::SUSPENDED => [__('filament.lottery.reason_suspended', [
                'rounds' => $standing->missedRounds,
                'amount' => number_format($standing->arrears, 2),
            ])],
            MembershipStanding::BLOCKED => [__('filament.lottery.reason_in_arrears', [
                'rounds' => $standing->missedRounds,
                'amount' => number_format($standing->arrears, 2),
            ])],
            default => [],
        });

        $minRounds = $this->rules->minPaidRounds();

        if ($standing->paidRounds < $minRounds) {
            $reasons[] = __('filament.lottery.reason_too_few_rounds', ['rounds' => $minRounds]);
        }

        $minDays = $this->rules->minDaysInEqub();

        if ($minDays > 0 && $standing->joinedAt && $standing->joinedAt->diffInDays($asOf, false) < $minDays) {
            $reasons[] = __('filament.lottery.reason_too_new', ['days' => $minDays]);
        }

        return array_values(array_filter($reasons));
    }

    /**
     * How much of the pool this place holds.
     *
     * Additive bonuses on a base of 1, each capped, then a penalty applied as
     * a multiplier. Additive keeps a single exceptional behaviour from
     * dominating — a member sixteen rounds ahead is worth 2.5 entries, not
     * sixteen — while the penalty is multiplicative so it bites hardest on
     * the members who had the most to lose.
     *
     * The cohort's own `win_weight` is preserved as a final multiplier: it is
     * an existing, deliberately-set admin lever and this engine has no
     * business quietly overriding it.
     */
    public function weightFor(EqubMembership $membership, MembershipStanding $standing): float
    {
        $weight = $this->rules->baseWeight();

        $weight += min(
            $this->rules->advanceMax(),
            $standing->advanceRounds * $this->rules->advancePerRound(),
        );

        $weight += min(
            $this->rules->streakMax(),
            $standing->onTimeStreak * $this->rules->streakPerRound(),
        );

        $weight += min(
            $this->rules->loyaltyMax(),
            $standing->roundsWaited * $this->rules->loyaltyPerRound(),
        );

        if ($standing->cleanRecord) {
            $weight += $this->rules->cleanRecordBonus();
        }

        if ($standing->status === MembershipStanding::WARNED) {
            $weight *= $this->rules->latePenalty();
        }

        $cohortWeight = (float) ($membership->cohort->win_weight ?? 1.0);

        if ($cohortWeight > 0) {
            $weight *= $cohortWeight;
        }

        // Never zero for an eligible entry. A member whose weight rounded
        // away would sit in the pool looking eligible and could never be
        // drawn, which is worse than being told they are out.
        return max(0.01, round($weight, 4));
    }

    /**
     * Human-readable notes on why this entry weighs what it does.
     *
     * @return array<int, string>
     */
    protected function weightNotes(MembershipStanding $standing): array
    {
        $notes = [];

        if ($standing->advanceRounds > 0) {
            $notes[] = __('filament.lottery.note_advance', ['rounds' => $standing->advanceRounds]);
        }

        if ($standing->onTimeStreak > 1) {
            $notes[] = __('filament.lottery.note_streak', ['rounds' => $standing->onTimeStreak]);
        }

        if ($standing->cleanRecord) {
            $notes[] = __('filament.lottery.note_clean');
        }

        if ($standing->status === MembershipStanding::WARNED) {
            $notes[] = __('filament.lottery.note_late', ['amount' => number_format($standing->arrears, 2)]);
        }

        if ($standing->roundsWaited >= 3 && ! $standing->hasWon) {
            $notes[] = __('filament.lottery.note_loyalty', ['rounds' => $standing->roundsWaited]);
        }

        return $notes;
    }

    /**
     * Hold each payer to their share of the pool.
     *
     * Scaled rather than truncated, so a member holding eight places keeps all
     * eight in the draw at reduced weight instead of having some of them
     * silently dropped.
     *
     * The target is solved rather than approached. Scaling a payer down to
     * `cap x total` does not work, because shrinking their weight also shrinks
     * the total and leaves them above the cap again — a member holding eight
     * of twelve places comes out at 43% on the first pass and 30% on the
     * second, when the cap says 25%. What we actually want is a weight `x`
     * satisfying x / (x + rest) = cap, which rearranges to
     * x = cap x rest / (1 - cap) and lands exactly on the cap in one step.
     *
     * The loop remains because several payers can be over the cap at once and
     * each correction lifts the others' shares. It is bounded: a pool that has
     * not settled after ten passes is pathological, and an extra fraction of a
     * percent on one payer is not worth spinning over.
     *
     * @param  Collection<int, DrawEntry>  $entries
     */
    protected function capPayerShare(Collection $entries): void
    {
        $cap = $this->rules->maxSharePerPayer();

        if ($cap >= 1.0 || $cap <= 0.0) {
            return;
        }

        $eligible = $entries->filter(fn (DrawEntry $e): bool => $e->eligible);

        if ($eligible->count() < 2) {
            return;
        }

        for ($pass = 0; $pass < 10; $pass++) {
            $total = (float) $eligible->sum(fn (DrawEntry $e): float => $e->weight);

            if ($total <= 0) {
                return;
            }

            $byPayer = $eligible->groupBy(fn (DrawEntry $e): string => (string) ($e->payerMemberId ?? 'seat-'.$e->membershipId));
            $adjusted = false;

            foreach ($byPayer as $group) {
                $held = (float) $group->sum(fn (DrawEntry $e): float => $e->weight);

                // A hair of tolerance, or floating point keeps re-triggering a
                // payer who is already exactly on the cap.
                if ($held <= 0 || $held <= ($cap * $total) + 1e-9) {
                    continue;
                }

                $rest = $total - $held;
                $target = ($cap * $rest) / (1 - $cap);
                $scale = $target / $held;

                foreach ($group as $entry) {
                    $entry->weight = max(0.01, round($entry->weight * $scale, 4));
                    $entry->cappedByPayerShare = true;
                }

                $adjusted = true;
            }

            if (! $adjusted) {
                return;
            }
        }
    }

    /** @param  Collection<int, DrawEntry>  $entries */
    protected function assignOdds(Collection $entries): void
    {
        $total = (float) $entries->filter(fn (DrawEntry $e): bool => $e->eligible)
            ->sum(fn (DrawEntry $e): float => $e->weight);

        foreach ($entries as $entry) {
            $entry->odds = ($entry->eligible && $total > 0)
                ? round(($entry->weight / $total) * 100, 3)
                : 0.0;
        }
    }

    // -----------------------------------------------------------------
    // Drawing
    // -----------------------------------------------------------------

    /** A fresh seed for one round. Recorded on the draw so it can be checked. */
    public function seed(): string
    {
        return bin2hex(random_bytes(16));
    }

    /**
     * Pick one entry, deterministically, from a seed.
     *
     * The randomness comes from the seed and nowhere else: no call to rand()
     * inside the walk, no reliance on collection order beyond the id sort
     * already applied. Given the same seed and the same entries this returns
     * the same membership every time, which is the whole basis on which a
     * result can be defended.
     *
     * $nonce distinguishes successive picks within one round, so drawing
     * three winners does not need three seeds.
     *
     * @param  Collection<int, DrawEntry>  $entries
     */
    public function pick(Collection $entries, string $seed, int $nonce = 0): ?DrawEntry
    {
        $eligible = $entries->filter(fn (DrawEntry $e): bool => $e->eligible && $e->weight > 0)->values();

        if ($eligible->isEmpty()) {
            return null;
        }

        $total = (float) $eligible->sum(fn (DrawEntry $e): float => $e->weight);

        if ($total <= 0) {
            return $eligible->first();
        }

        $target = $this->unitInterval($seed, $nonce) * $total;
        $cumulative = 0.0;

        foreach ($eligible as $entry) {
            $cumulative += $entry->weight;

            if ($target <= $cumulative) {
                return $entry;
            }
        }

        // Floating point can leave the target a hair beyond the last boundary.
        return $eligible->last();
    }

    /**
     * Several winners from one seed, without repeats.
     *
     * @param  Collection<int, DrawEntry>  $entries
     * @return Collection<int, DrawEntry>
     */
    public function pickMany(Collection $entries, string $seed, int $count): Collection
    {
        $remaining = $entries;
        $winners = collect();

        for ($i = 0; $i < $count; $i++) {
            $winner = $this->pick($remaining, $seed, $i);

            if (! $winner) {
                break;
            }

            $winners->push($winner);
            $remaining = $remaining->reject(fn (DrawEntry $e): bool => $e->membershipId === $winner->membershipId)->values();
        }

        return $winners;
    }

    /**
     * A number in [0, 1) derived from the seed.
     *
     * Fifteen hex digits — 60 bits — because that is the widest value hexdec()
     * returns as an exact integer on a 64-bit build. Taking sixteen would
     * overflow into a float and quietly lose the low bits, which is the kind
     * of bug that never shows up in testing and biases every draw slightly.
     */
    protected function unitInterval(string $seed, int $nonce): float
    {
        $hash = hash('sha256', $seed.':'.$nonce);
        $slice = substr($hash, 0, 15);

        return hexdec($slice) / (hexdec('fffffffffffffff') + 1);
    }

    // -----------------------------------------------------------------
    // Audit
    // -----------------------------------------------------------------

    /**
     * Everything needed to recompute this round, recorded on the draw.
     *
     * Trimmed to the configured limit: a pool of forty thousand places would
     * otherwise write a JSON blob nobody can open. Eligible entries are kept
     * first because they are what decided the outcome, and the counts at the
     * top stay exact whatever gets trimmed.
     *
     * @param  Collection<int, DrawEntry>  $entries
     * @param  Collection<int, DrawEntry>  $winners
     * @return array<string, mixed>
     */
    public function auditSnapshot(Collection $entries, Collection $winners, string $seed): array
    {
        $eligible = $entries->filter(fn (DrawEntry $e): bool => $e->eligible);
        $limit = $this->rules->auditSnapshotLimit();

        $kept = $eligible
            ->sortByDesc(fn (DrawEntry $e): float => $e->weight)
            ->take($limit)
            ->concat(
                $entries->filter(fn (DrawEntry $e): bool => $e->ineligible())
                    ->take(max(0, $limit - min($limit, $eligible->count())))
            )
            ->values();

        return [
            'algorithm' => 'weighted-cumulative/sha256',
            'seed' => $seed,
            'rules' => $this->rules->toArray(),
            'pool' => [
                'total' => $entries->count(),
                'eligible' => $eligible->count(),
                'excluded' => $entries->count() - $eligible->count(),
                'total_weight' => round((float) $eligible->sum(fn (DrawEntry $e): float => $e->weight), 4),
            ],
            'winners' => $winners->map(fn (DrawEntry $e): array => [
                'membership_id' => $e->membershipId,
                'name' => $e->name,
                'weight' => round($e->weight, 4),
                'odds' => $e->odds,
            ])->values()->all(),
            'truncated' => $entries->count() > $kept->count(),
            'entries' => $kept->map(fn (DrawEntry $e): array => $e->toArray())->all(),
        ];
    }

    /** Placeholder standing for a membership we could not measure. */
    protected function emptyStanding(EqubMembership $membership): MembershipStanding
    {
        return new MembershipStanding(
            membershipId: (int) $membership->id,
            payerMemberId: $membership->payerMemberId(),
            displayName: $membership->displayName(),
            contribution: (float) ($membership->contribution_amount ?? 0),
            frequencyDays: max(1, (int) ($membership->contribution_frequency_days ?: 1)),
            totalRounds: 0,
            roundsDue: 0,
            joinedAt: null,
            paidAmount: 0.0,
            paidRounds: 0,
            lastPaidAt: null,
            expectedToDate: 0.0,
            arrears: 0.0,
            advanceAmount: 0.0,
            missedRounds: 0,
            advanceRounds: 0,
            daysOverdue: 0,
            onTimeStreak: 0,
            lateCount: 0,
            cleanRecord: false,
            roundsWaited: 0,
            hasWon: (bool) $membership->has_won,
            status: MembershipStanding::UNKNOWN,
        );
    }
}
