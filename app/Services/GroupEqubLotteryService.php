<?php

namespace App\Services;

use App\Models\EqubDraw;
use App\Models\EqubDrawWinner;
use App\Models\EqubGroup;
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
 */
class GroupEqubLotteryService
{
    public function __construct(
        protected SmsService $smsService,
        protected FcmService $fcmService,
        protected EqubGroupLedgerService $ledger,
    ) {}

    /**
     * Group Equbs still in the running on this parent, each with its head-count.
     *
     * @return Collection<int, EqubGroup>
     */
    public function pool(EqubGroup $parent): Collection
    {
        return $parent->eligibleSubGroups()
            ->with(['owner.user'])
            ->withCount(['memberships as head_count' => fn ($q) => $q->where('status', \App\Enums\EqubMembershipStatus::Active)])
            ->get()
            ->filter(fn (EqubGroup $g): bool => (int) $g->head_count > 0)
            ->values();
    }

    /**
     * Choose whole groups whose combined head-count lands as close as possible
     * to $target without a wasteful overshoot.
     *
     * Greedy on a shuffled pool: take any group that still fits, and once
     * nothing fits exactly, accept the smallest remaining group to close the
     * gap. Shuffling keeps it a lottery rather than a deterministic sort.
     *
     * @param  Collection<int, EqubGroup>  $pool
     * @return Collection<int, EqubGroup>
     */
    public function balanceToTarget(Collection $pool, int $target): Collection
    {
        $target = max(1, $target);
        $chosen = collect();
        $remaining = $pool->shuffle();
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

        $pool = $this->pool($parent);

        if ($pool->isEmpty()) {
            return ['success' => false, 'message' => 'No Group Equbs on this Equb are eligible yet.'];
        }

        $isManual = $manualGroupIds !== [];

        if ($isManual) {
            $winners = $pool->whereIn('id', $manualGroupIds)->values();

            if ($winners->count() !== count(array_unique($manualGroupIds))) {
                return [
                    'success' => false,
                    'message' => 'Some of the selected Group Equbs are not eligible. They may have won already.',
                ];
            }
        } else {
            if (! $targetMembers || $targetMembers < 1) {
                return ['success' => false, 'message' => 'Enter how many members should win this round.'];
            }

            $winners = $this->balanceToTarget($pool, $targetMembers);
        }

        if ($winners->isEmpty()) {
            return ['success' => false, 'message' => 'No combination of groups could be drawn for that target.'];
        }

        $membersWon = (int) $winners->sum(fn (EqubGroup $g): int => (int) $g->head_count);
        $perPerson = $parent->contributionPerPerson();
        $round = $parent->nextRoundNumber();

        $this->announceStarted($parent, $round, $membersWon);

        try {
            $draw = DB::transaction(function () use ($parent, $winners, $round, $membersWon, $perPerson, $executedByUserId, $isManual, $targetMembers) {
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

                return $draw->load(['winners.membership.member.user', 'equbGroup']);
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
        $amount = number_format($perPerson, 2);

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

            foreach ($group->activeMemberships as $membership) {
                $phone = $membership->member?->user?->phone;

                if (! $phone) {
                    continue;
                }

                $this->safely(fn () => $this->smsService->sendSms(
                    $phone,
                    "Congratulations! Your group \"{$group->name}\" has won round {$draw->round_number} "
                    ."of {$parent->name}. Your share is {$amount} ETB.",
                    null,
                    $draw
                ));
            }
        }
    }
}
