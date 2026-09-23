<?php

namespace App\Services;

use App\Models\EqubDraw;
use App\Models\EqubDrawWinner;
use App\Models\EqubGroup;
use App\Services\Equb\EqubLotteryEngine;
use App\Services\Equb\EqubRules;
use App\Support\Equb\DrawEntry;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Draws winners at the *parent* Equb level, where each winner is a whole Group
 * Equb rather than a single member.
 *
 * Automatic mode: the admin says how many members should win this round and the
 * engine balances whole groups against that target — a target of 7 with groups
 * of 5, 4, 3 and 2 waiting picks 5 + 2, not 5 + 4.
 *
 * Manual mode: the admin hand-picks the winning groups.
 *
 * A GROUP IS ONLY AS ELIGIBLE AS ITS MEMBERS
 *
 * The pool used to be every group that had not won yet, regardless of whether
 * anybody in it was paying. Because a whole group collects together, that let
 * a family group where two of five had stopped contributing take a full round
 * of everybody else's money.
 *
 * So each group is now screened member by member and the ones carrying
 * arrears are held back with a reason attached. The groups that remain are
 * weighted by how their members have behaved — a group that pays early and on
 * time holds more of the pool than one scraping in late — which turns the
 * whole thing from a lottery between groups into a lottery the members of a
 * group can improve together.
 */
class GroupEqubLotteryService
{
    public function __construct(
        protected SmsService $smsService,
        protected FcmService $fcmService,
        protected EqubGroupLedgerService $ledger,
        protected EqubLotteryEngine $engine,
        protected EqubRules $rules,
    ) {}

    /**
     * Group Equbs still in the running on this parent, each with its head-count.
     *
     * Only groups that clear the standing check. `screen()` has the same list
     * with the rejected ones and their reasons, which is what the draw modal
     * shows.
     *
     * @return Collection<int, EqubGroup>
     */
    public function pool(EqubGroup $parent): Collection
    {
        return $this->screen($parent)
            ->filter(fn (array $row): bool => $row['eligible'])
            ->map(fn (array $row): EqubGroup => $row['group'])
            ->values();
    }

    /**
     * Every candidate group with its verdict.
     *
     * Each row carries the group, its head-count, whether it may be drawn,
     * the combined weight of its members and — when it may not — the members
     * holding it back. An admin about to run a round should be able to see
     * that before pressing the button rather than wondering why a family they
     * expected to be in the draw was not.
     *
     * @return Collection<int, array{group: EqubGroup, eligible: bool, weight: float, head_count: int, entries: Collection<int, DrawEntry>, blockers: Collection<int, DrawEntry>, reason: ?string}>
     */
    public function screen(EqubGroup $parent): Collection
    {
        $groups = $parent->eligibleSubGroups()
            ->with(['owner.user'])
            ->withCount(['memberships as head_count' => fn ($q) => $q->where('status', \App\Enums\EqubMembershipStatus::Active)])
            ->get()
            ->filter(fn (EqubGroup $g): bool => (int) $g->head_count > 0)
            ->values();

        if ($groups->isEmpty()) {
            return collect();
        }

        // Every active place across every candidate group, measured in one
        // pass. Screening group by group would run a payments query per
        // family and turn a forty-group Equb into forty round trips.
        $memberships = \App\Models\EqubMembership::query()
            ->with(['cohort', 'equbGroup', 'member', 'sponsor'])
            ->whereIn('equb_group_id', $groups->pluck('id'))
            ->where('status', \App\Enums\EqubMembershipStatus::Active)
            ->get();

        // Which group each place belongs to, as a lookup rather than a search
        // per entry. firstWhere() inside the grouping turns a four-hundred
        // place Equb into a hundred and sixty thousand comparisons every time
        // the draw modal re-renders.
        $groupOfMembership = $memberships->pluck('equb_group_id', 'id');

        $entriesByGroup = $this->engine->entries($memberships)
            ->groupBy(fn (DrawEntry $entry): int => (int) $groupOfMembership->get($entry->membershipId));

        $allowedInArrears = $this->rules->groupMaxMembersInArrears();
        $minEligible = $this->rules->groupMinEligibleMembers();

        return $groups->map(function (EqubGroup $group) use ($entriesByGroup, $allowedInArrears, $minEligible): array {
            $entries = $entriesByGroup->get($group->id, collect());
            $blockers = $entries->filter(fn (DrawEntry $e): bool => $e->blocksGroup())->values();
            $eligibleEntries = $entries->filter(fn (DrawEntry $e): bool => $e->eligible);

            $reason = match (true) {
                $entries->isEmpty() => __('filament.lottery.group_no_members'),
                $blockers->count() > $allowedInArrears => __('filament.lottery.group_has_arrears', [
                    'count' => $blockers->count(),
                    'names' => $blockers->take(3)->pluck('name')->implode(', '),
                ]),
                $eligibleEntries->count() < $minEligible => __('filament.lottery.group_too_few_eligible', [
                    'count' => $minEligible,
                ]),
                default => null,
            };

            return [
                'group' => $group,
                'eligible' => $reason === null,
                // The group's share of the pool is the sum of its members'.
                // A group of five punctual payers therefore outweighs a group
                // of five who scrape in late, and a bigger group outweighs a
                // smaller one — which is right, since it is also risking more.
                'weight' => round((float) $eligibleEntries->sum(fn (DrawEntry $e): float => $e->weight), 4),
                'head_count' => (int) $group->head_count,
                'entries' => $entries,
                'blockers' => $blockers,
                'reason' => $reason,
            ];
        })->values();
    }

    /**
     * Choose whole groups whose combined head-count lands as close as possible
     * to $target without a wasteful overshoot.
     *
     * Greedy on a weighted-random ordering: take any group that still fits,
     * and once nothing fits exactly, accept the smallest remaining group to
     * close the gap.
     *
     * The ordering used to be a plain shuffle, which made every group equally
     * likely to be reached first. It is now drawn against the weights the
     * engine assigned, so a group whose members pay early and on time comes up
     * sooner more often — while still being a draw, not a ranking.
     *
     * @param  Collection<int, EqubGroup>  $pool
     * @param  array<int, float>  $weights  Group id => weight. Equal if omitted.
     * @return Collection<int, EqubGroup>
     */
    public function balanceToTarget(Collection $pool, int $target, array $weights = [], ?string $seed = null): Collection
    {
        $target = max(1, $target);
        $chosen = collect();
        $remaining = $this->weightedOrder($pool, $weights, $seed);
        $filled = 0;

        // First pass: groups that fit inside the target exactly.
        foreach ($remaining as $group) {
            $size = (int) $group->head_count;

            if ($filled + $size <= $target) {
                $chosen->push($group);
                $filled += $size;
            }

            if ($filled === $target) {
                return $chosen;
            }
        }

        if ($filled >= $target || $chosen->isNotEmpty()) {
            // Second pass: close a leftover gap with the smallest group that
            // covers it, so we overshoot by as little as possible.
            $gap = $target - $filled;

            if ($gap > 0) {
                $filler = $remaining
                    ->reject(fn (EqubGroup $g): bool => $chosen->contains('id', $g->id))
                    ->sortBy(fn (EqubGroup $g): int => (int) $g->head_count)
                    ->first(fn (EqubGroup $g): bool => (int) $g->head_count >= $gap);

                if ($filler) {
                    $chosen->push($filler);
                }
            }

            return $chosen;
        }

        // Nothing fit at all: every group is larger than the target. Take the
        // smallest one so the round still produces a winner.
        $smallest = $remaining->sortBy(fn (EqubGroup $g): int => (int) $g->head_count)->first();

        return $smallest ? collect([$smallest]) : collect();
    }

    /**
     * Shuffle a pool so that heavier groups tend to come out first.
     *
     * The standard trick for weighted sampling without replacement: give each
     * item the key U^(1/w) for a uniform U, and sort descending. An item with
     * twice the weight is twice as likely to lead, and every item still has a
     * real chance — which is what keeps this a lottery.
     *
     * With a seed the ordering is reproducible, so a round can be re-derived
     * from what was recorded on the draw.
     *
     * @param  Collection<int, EqubGroup>  $pool
     * @param  array<int, float>  $weights
     * @return Collection<int, EqubGroup>
     */
    protected function weightedOrder(Collection $pool, array $weights, ?string $seed): Collection
    {
        if ($weights === []) {
            return $pool->shuffle();
        }

        return $pool
            ->map(function (EqubGroup $group, int $index) use ($weights, $seed): array {
                $weight = max(0.0001, (float) ($weights[$group->id] ?? 1.0));

                $u = $seed !== null
                    ? $this->seededUnit($seed, $group->id)
                    : (mt_rand(1, mt_getrandmax()) / (mt_getrandmax() + 1));

                // Guard the log against a u that rounds to exactly zero.
                $u = min(0.999999999, max(0.000000001, $u));

                return ['group' => $group, 'key' => pow($u, 1 / $weight)];
            })
            ->sortByDesc('key')
            ->map(fn (array $row): EqubGroup => $row['group'])
            ->values();
    }

    /** A reproducible number in [0, 1) for one group under one seed. */
    protected function seededUnit(string $seed, int $groupId): float
    {
        $hash = hash('sha256', $seed.':group:'.$groupId);

        return hexdec(substr($hash, 0, 15)) / (hexdec('fffffffffffffff') + 1);
    }

    /**
     * Run a round on a parent Equb.
     *
     * @param  int[]  $manualGroupIds  Hand-picked winning Group Equbs.
     * @return array{success: bool, message?: string, draw?: EqubDraw, winners?: Collection, members_won?: int}
     */
    public function draw(
        EqubGroup $parent,
        ?int $targetMembers = null,
        array $manualGroupIds = [],
        ?int $executedByUserId = null,
    ): array {
        if ($parent->isMemberCreated()) {
            return ['success' => false, 'message' => 'Pick a platform Equb group, not a Group Equb.'];
        }

        $screened = $this->screen($parent);
        $eligibleRows = $screened->filter(fn (array $row): bool => $row['eligible'])->values();
        $pool = $eligibleRows->map(fn (array $row): EqubGroup => $row['group'])->values();

        if ($pool->isEmpty()) {
            // Say which wall was hit. "No groups are eligible" on an Equb with
            // twelve waiting families is a message that sends somebody
            // hunting through the database.
            $held = $screened->filter(fn (array $row): bool => ! $row['eligible']);

            return [
                'success' => false,
                'message' => $held->isEmpty()
                    ? __('filament.lottery.no_pool')
                    : __('filament.lottery.pool_all_groups_held', [
                        'count' => $held->count(),
                        'reason' => (string) $held->first()['reason'],
                    ]),
            ];
        }

        $isManual = $manualGroupIds !== [];
        $seed = $this->engine->seed();
        $weights = $eligibleRows
            ->mapWithKeys(fn (array $row): array => [$row['group']->id => $row['weight']])
            ->all();

        if ($isManual) {
            $winners = $pool->whereIn('id', $manualGroupIds)->values();

            if ($winners->count() !== count(array_unique($manualGroupIds))) {
                return [
                    'success' => false,
                    'message' => 'Some of the selected Group Equbs are not eligible. They may have won already, or a member is in arrears.',
                ];
            }
        } else {
            if (! $targetMembers || $targetMembers < 1) {
                return ['success' => false, 'message' => 'Enter how many members should win this round.'];
            }

            $winners = $this->balanceToTarget($pool, $targetMembers, $weights, $seed);
        }

        if ($winners->isEmpty()) {
            return ['success' => false, 'message' => 'No combination of groups could be drawn for that target.'];
        }

        $snapshot = $this->groupSnapshot($screened, $winners, $seed, $isManual);

        $membersWon = (int) $winners->sum(fn (EqubGroup $g): int => (int) $g->head_count);
        $perPerson = $parent->contributionPerPerson();
        $round = $parent->nextRoundNumber();

        $this->announceStarted($parent, $round, $membersWon);

        try {
            $draw = DB::transaction(function () use ($parent, $winners, $round, $membersWon, $perPerson, $executedByUserId, $isManual, $targetMembers, $seed, $snapshot, $screened) {
                $firstMembership = $winners->first()->activeMemberships()->first();

                $draw = EqubDraw::create([
                    'equb_group_id' => $parent->id,
                    'draw_date' => now(),
                    'round_number' => $round,
                    'winners_count' => $winners->count(),
                    'mode' => $isManual ? 'manual' : 'automatic',
                    'executed_by_admin_id' => $executedByUserId,
                    'winner_membership_id' => $firstMembership?->id,
                    'notes' => $isManual
                        ? 'Manual group selection.'
                        : "Target {$targetMembers} members, drew {$membersWon}.",
                    // A manual round records the seed too, even though nothing
                    // was drawn against it. The rest of the snapshot — who was
                    // eligible, who was held back and why — is the part that
                    // matters when an admin's own choice is questioned later.
                    'random_seed' => $seed,
                    'pool_size' => $screened->count(),
                    'excluded_count' => $screened->filter(fn (array $r): bool => ! $r['eligible'])->count(),
                    'total_weight' => round((float) $screened->sum(fn (array $r): float => $r['eligible'] ? $r['weight'] : 0), 4),
                    'winner_weight' => round((float) $winners->sum(fn (EqubGroup $g): float => (float) ($snapshot['weights'][$g->id] ?? 0)), 4),
                    'eligibility_snapshot' => $snapshot,
                ]);

                foreach ($winners as $index => $group) {
                    // Every member of a winning group is a winner of the round.
                    foreach ($group->activeMemberships as $position => $membership) {
                        EqubDrawWinner::create([
                            'equb_draw_id' => $draw->id,
                            'equb_membership_id' => $membership->id,
                            'position' => $index + 1,
                            'amount_won' => $perPerson,
                        ]);

                        $membership->update(['has_won' => true, 'win_date' => now()]);
                    }

                    $group->update([
                        'has_won_round' => true,
                        'won_round_at' => now(),
                    ]);
                }

                return $draw->load([
                    'winners.membership.member.user',
                    'winners.membership.sponsor.user',
                    'equbGroup',
                ]);
            });
        } catch (\Throwable $e) {
            Log::error("Group Equb lottery failed on parent {$parent->id}: ".$e->getMessage(), [
                'exception' => $e,
            ]);

            return [
                'success' => false,
                'message' => 'The draw could not be completed: '.$e->getMessage(),
            ];
        }

        $this->notifyWinners($winners, $parent, $draw, $perPerson);
        $this->announceCompleted($parent, $draw, $winners, $membersWon);

        return [
            'success' => true,
            'draw' => $draw,
            'winners' => $winners,
            'members_won' => $membersWon,
        ];
    }

    // -----------------------------------------------------------------

    /**
     * The round as it stood, for the audit trail.
     *
     * Group level rather than member level: a round here picks families, so
     * that is the granularity a challenge would be about. Each row says what
     * the group weighed, how many places it holds, and — for the ones held
     * back — exactly which members were carrying arrears on the day.
     *
     * @param  Collection<int, array<string, mixed>>  $screened
     * @param  Collection<int, EqubGroup>  $winners
     * @return array<string, mixed>
     */
    protected function groupSnapshot(Collection $screened, Collection $winners, string $seed, bool $isManual): array
    {
        $winnerIds = $winners->pluck('id')->all();

        return [
            'algorithm' => $isManual ? 'manual-selection' : 'weighted-order/sha256',
            'level' => 'group',
            'seed' => $seed,
            'rules' => $this->rules->toArray(),
            'weights' => $screened
                ->mapWithKeys(fn (array $row): array => [$row['group']->id => $row['weight']])
                ->all(),
            'groups' => $screened->map(fn (array $row): array => [
                'group_id' => $row['group']->id,
                'name' => $row['group']->name,
                'head_count' => $row['head_count'],
                'weight' => $row['weight'],
                'eligible' => $row['eligible'],
                'reason' => $row['reason'],
                'won' => in_array($row['group']->id, $winnerIds, true),
                'blockers' => $row['blockers']->map(fn (DrawEntry $e): array => [
                    'membership_id' => $e->membershipId,
                    'name' => $e->name,
                    'status' => $e->standing->status,
                    'arrears' => $e->standing->arrears,
                    'missed_rounds' => $e->standing->missedRounds,
                    'reason' => $e->reason(),
                ])->values()->all(),
            ])->values()->all(),
        ];
    }

    protected function announceStarted(EqubGroup $parent, int $round, int $membersWon): void
    {
        // No draw_delay sleep here on purpose. That pause exists to pace the
        // reveal animation in the app; inside an admin request it just holds
        // the connection open until the request times out.
        $this->safely(fn () => $this->fcmService->sendToTopic(
            FcmService::equbGroupTopic($parent->id),
            [
                'type' => 'equb_group_draw_started',
                'equb_group_id' => (string) $parent->id,
                'round_number' => (string) $round,
                'winners_count' => (string) $membersWon,
            ],
            'Draw starting',
            "Round {$round} of {$parent->name} is being drawn."
        ));
    }

    /**
     * Notifications must never fail a draw that has already been committed.
     * A dead FCM token or an SMS outage is logged, not thrown.
     */
    protected function safely(callable $callback): void
    {
        try {
            $callback();
        } catch (\Throwable $e) {
            Log::warning('Group Equb lottery notification failed: '.$e->getMessage());
        }
    }

    protected function announceCompleted(EqubGroup $parent, EqubDraw $draw, Collection $winners, int $membersWon): void
    {
        $names = $winners->pluck('name')->filter()->values()->all();

        $this->safely(fn () => $this->fcmService->sendToTopic(
            FcmService::equbGroupTopic($parent->id),
            [
                'type' => 'equb_group_draw_completed',
                'equb_group_id' => (string) $parent->id,
                'equb_draw_id' => (string) $draw->id,
                'round_number' => (string) $draw->round_number,
                'winners_count' => (string) $membersWon,
                'winners' => json_encode($names),
            ],
            'Draw result',
            'Winning groups: '.implode(', ', $names)
        ));
    }

    protected function notifyWinners(Collection $winners, EqubGroup $parent, EqubDraw $draw, float $perPerson): void
    {
        foreach ($winners as $group) {
            $this->safely(fn () => $this->fcmService->sendToTopic(
                FcmService::equbGroupTopic($group->id),
                [
                    'type' => 'equb_group_won',
                    'equb_group_id' => (string) $group->id,
                    'equb_draw_id' => (string) $draw->id,
                ],
                'Your group won!',
                "{$group->name} has won round {$draw->round_number} of {$parent->name}."
            ));

            // One message per person, not per place.
            //
            // A member holding three "My Responsibility People" places would
            // otherwise get three identical texts saying "your share is X" —
            // confusing to read, wrong about the amount, and billed three
            // times. Grouping by the payer means each person gets one message
            // stating what they are actually owed.
            $shares = [];

            foreach ($group->activeMemberships as $membership) {
                $user = $membership->payerUser();
                $phone = $user?->phone;

                if (! $phone) {
                    continue;
                }

                $shares[$phone] ??= ['places' => 0, 'held' => []];
                $shares[$phone]['places']++;

                if ($membership->isResponsibilitySeat()) {
                    $shares[$phone]['held'][] = $membership->displayName();
                }
            }

            foreach ($shares as $phone => $share) {
                $total = number_format($perPerson * $share['places'], 2);

                $heldNote = $share['held'] !== []
                    ? ' This includes the place(s) you hold for '.implode(', ', $share['held']).'.'
                    : '';

                $this->safely(fn () => $this->smsService->sendSms(
                    $phone,
                    "Congratulations! Your group \"{$group->name}\" has won round {$draw->round_number} "
                    ."of {$parent->name}. Your share is {$total} ETB.".$heldNote,
                    null,
                    $draw
                ));
            }
        }
    }
}
