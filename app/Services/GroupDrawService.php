<?php

namespace App\Services;

use App\Enums\EqubGroupStatus;
use App\Enums\EqubMembershipStatus;
use App\Enums\EqubPaymentStatus;
use App\Enums\WinnerSelectionMode;
use App\Models\EqubDraw;
use App\Models\EqubDrawWinner;
use App\Models\EqubGroup;
use App\Models\EqubMembership;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Draw engine for member-created groups, where a single round can produce a
 * *winner group* rather than one winner.
 *
 *   7 members, sizes 2–5  ->  round 1 picks 3 winners, round 2 picks the other 4
 *
 * Platform groups keep using EqubDrawService untouched; this service handles
 * any group whose winner_selection_mode is not `single`.
 */
class GroupDrawService
{
    public function __construct(
        protected SmsService $smsService,
        protected FcmService $fcmService,
        protected GroupSplitPlanner $planner,
        protected EqubGroupLedgerService $ledger,
        protected EqubMembershipService $membershipService,
    ) {}

    /**
     * Build (and optionally persist) the winner-group plan for a group.
     *
     * @return int[] e.g. [3, 4]
     */
    public function buildSplitPlan(EqubGroup $group, bool $persist = false): array
    {
        $memberCount = $group->activeMemberships()->count();

        $plan = match ($group->winner_selection_mode) {
            WinnerSelectionMode::FixedSize => $this->planner->fixedSizePlan(
                $memberCount,
                (int) ($group->winners_per_draw ?? 1)
            ),
            WinnerSelectionMode::RandomSplit, WinnerSelectionMode::Manual => $this->planner->plan(
                $memberCount,
                (int) ($group->min_winners_per_draw ?? 1),
                (int) ($group->max_winners_per_draw ?? $group->winners_per_draw ?? 1)
            ),
            default => $this->planner->singlePlan($memberCount),
        };

        if ($persist) {
            $group->forceFill([
                'winner_split_plan' => $plan,
                'split_plan_cursor' => 0,
            ])->save();
        }

        return $plan;
    }

    /**
     * Run one round.
     *
     * @param  int[]  $manualMembershipIds  Winner group chosen by hand (manual mode).
     * @return array{success: bool, message?: string, draw?: EqubDraw, winners?: array}
     */
    public function runRound(
        EqubGroup $group,
        ?int $executedByUserId = null,
        array $manualMembershipIds = [],
        ?int $winnersOverride = null,
    ): array {
        if ($group->status !== EqubGroupStatus::Running) {
            return ['success' => false, 'message' => 'This Equb is not running yet.'];
        }

        if (! $group->isApproved()) {
            return ['success' => false, 'message' => 'This Equb is awaiting admin approval.'];
        }

        $eligible = $this->eligibleMemberships($group);

        if ($eligible->isEmpty()) {
            return ['success' => false, 'message' => 'No members are eligible for this round yet.'];
        }

        $isManual = $manualMembershipIds !== [];

        if ($isManual) {
            $winners = $eligible->whereIn('id', $manualMembershipIds)->values();

            if ($winners->count() !== count(array_unique($manualMembershipIds))) {
                return [
                    'success' => false,
                    'message' => 'Some of the selected members cannot win this round. They may have won already or be behind on payments.',
                ];
            }
        } else {
            $wanted = $winnersOverride ?? $group->winnersForNextRound();
            $wanted = max(1, min($wanted, $eligible->count()));
            $winners = $this->pickWeightedWinners($eligible, $wanted);
        }

        if ($winners->isEmpty()) {
            return ['success' => false, 'message' => 'No members are eligible for this round yet.'];
        }

        $round = $group->nextRoundNumber();
        $amountPerWinner = $this->amountPerWinner($group, $winners->count());

        $this->announceDrawStarted($group, $winners->count(), $round);

        try {
            $draw = DB::transaction(function () use ($group, $winners, $executedByUserId, $round, $amountPerWinner, $isManual) {
                $draw = EqubDraw::create([
                    'equb_group_id' => $group->id,
                    'draw_date' => now(),
                    'round_number' => $round,
                    'winners_count' => $winners->count(),
                    'mode' => $isManual ? 'manual' : 'automatic',
                    'executed_by_admin_id' => $executedByUserId,
                    // Kept in sync for every existing screen and API resource.
                    'winner_membership_id' => $winners->first()->id,
                ]);

                foreach ($winners as $index => $winner) {
                    EqubDrawWinner::create([
                        'equb_draw_id' => $draw->id,
                        'equb_membership_id' => $winner->id,
                        'position' => $index + 1,
                        'amount_won' => $amountPerWinner,
                    ]);

                    $winner->update([
                        'has_won' => true,
                        'win_date' => now(),
                    ]);

                    $this->membershipService->completeIfEligible($winner);
                }

                if ($group->winner_split_plan) {
                    $group->increment('split_plan_cursor');
                }

                return $draw->load(['winners.membership.member.user', 'equbGroup']);
            });
        } catch (\Throwable $e) {
            Log::error("Group draw failed for group {$group->id}: ".$e->getMessage());

            return ['success' => false, 'message' => 'The draw could not be completed. Please try again.'];
        }

        $this->notifyWinners($group, $draw, $amountPerWinner);
        $this->announceDrawCompleted($group, $draw);
        $this->completeGroupIfFinished($group);

        return [
            'success' => true,
            'draw' => $draw,
            'round_number' => $round,
            'winners_count' => $winners->count(),
            'amount_per_winner' => $amountPerWinner,
            'winners' => $winners->map(fn (EqubMembership $m): array => [
                'membership_id' => $m->id,
                'member_id' => $m->member_id,
                'name' => $m->member?->full_name,
            ])->all(),
        ];
    }

    /**
     * Active members who have not won yet and have actually contributed.
     * When the group requires it, members behind on contributions sit this out.
     */
    public function eligibleMemberships(EqubGroup $group): Collection
    {
        $memberships = EqubMembership::query()
            ->with(['cohort', 'member.user'])
            ->where('equb_group_id', $group->id)
            ->where('status', EqubMembershipStatus::Active)
            ->where('has_won', false)
            ->whereHas('payments', fn ($q) => $q->where('status', EqubPaymentStatus::Paid))
            ->get();

        if (! $group->draw_requires_up_to_date) {
            return $memberships;
        }

        $behind = collect($this->ledger->membersBehind($group))->pluck('membership_id')->all();

        return $memberships->reject(fn (EqubMembership $m): bool => in_array($m->id, $behind, true))->values();
    }

    /** What each winner in this round receives. */
    public function amountPerWinner(EqubGroup $group, int $winnersCount): float
    {
        if ($group->payout_per_winner !== null && (float) $group->payout_per_winner > 0) {
            return round((float) $group->payout_per_winner, 2);
        }

        $winnersCount = max(1, $winnersCount);

        return round($group->potPerRound() / $winnersCount, 2);
    }

    /**
     * Weighted random selection without replacement, reusing the cohort
     * win_weight so late joiners keep their compensation.
     */
    protected function pickWeightedWinners(Collection $pool, int $count): Collection
    {
        $winners = collect();
        $remaining = $pool->values();

        for ($i = 0; $i < $count && $remaining->isNotEmpty(); $i++) {
            $winner = $this->pickOne($remaining);

            if (! $winner) {
                break;
            }

            $winners->push($winner);
            $remaining = $remaining->reject(fn (EqubMembership $m): bool => $m->id === $winner->id)->values();
        }

        return $winners;
    }

    protected function pickOne(Collection $memberships): ?EqubMembership
    {
        if ($memberships->isEmpty()) {
            return null;
        }

        $totalWeight = $memberships->sum(fn (EqubMembership $m): float => (float) ($m->cohort->win_weight ?? 1.00));

        if ($totalWeight <= 0) {
            return $memberships->random();
        }

        $random = (mt_rand() / mt_getrandmax()) * $totalWeight;
        $cursor = 0.0;

        foreach ($memberships as $membership) {
            $cursor += (float) ($membership->cohort->win_weight ?? 1.00);

            if ($random <= $cursor) {
                return $membership;
            }
        }

        return $memberships->last();
    }

    protected function announceDrawStarted(EqubGroup $group, int $winnersCount, int $round): void
    {
        $candidates = $this->eligibleMemberships($group)
            ->map(fn (EqubMembership $m): string => (string) ($m->member?->full_name ?? str_pad((string) $m->member_id, 3, '0', STR_PAD_LEFT)))
            ->values()
            ->all();

        $this->fcmService->sendToTopic(
            FcmService::equbGroupTopic($group->id),
            [
                'type' => 'equb_group_draw_started',
                'equb_group_id' => (string) $group->id,
                'equb_group_name' => (string) $group->name,
                'round_number' => (string) $round,
                'winners_count' => (string) $winnersCount,
                'date_time' => now()->toDateTimeString(),
                'member_names' => json_encode($candidates),
            ],
            'Draw starting',
            "Round {$round} of {$group->name}: {$winnersCount} member(s) will win."
        );

        $delay = (int) config('services.equb.draw_delay', 0);

        if ($delay > 0) {
            sleep($delay);
        }
    }

    protected function announceDrawCompleted(EqubGroup $group, EqubDraw $draw): void
    {
        $names = $draw->winners
            ->map(fn (EqubDrawWinner $w): string => (string) ($w->membership?->member?->full_name ?? 'Member'))
            ->values()
            ->all();

        $this->fcmService->sendToTopic(
            FcmService::equbGroupTopic($group->id),
            [
                'type' => 'equb_group_draw_completed',
                'equb_group_id' => (string) $group->id,
                'equb_draw_id' => (string) $draw->id,
                'round_number' => (string) $draw->round_number,
                'winners_count' => (string) $draw->winners_count,
                'winners' => json_encode($names),
                'winner_name' => $names[0] ?? '',
            ],
            'Draw result',
            'This round goes to: '.implode(', ', $names)
        );
    }

    protected function notifyWinners(EqubGroup $group, EqubDraw $draw, float $amountPerWinner): void
    {
        foreach ($draw->winners as $winnerRow) {
            $phone = $winnerRow->membership?->member?->user?->phone;

            if (! $phone) {
                continue;
            }

            $amount = number_format($amountPerWinner, 2);
            $message = "Congratulations! You are in the winning group for {$group->name} "
                ."(round {$draw->round_number}). Your share is {$amount} ETB. "
                .'Draw date: '.$draw->draw_date->format('Y-m-d').'.';

            $this->smsService->sendSms($phone, $message, null, $draw);
        }
    }

    /** Close the group once every active member has had their turn. */
    protected function completeGroupIfFinished(EqubGroup $group): void
    {
        $remaining = EqubMembership::query()
            ->where('equb_group_id', $group->id)
            ->where('status', EqubMembershipStatus::Active)
            ->where('has_won', false)
            ->count();

        if ($remaining === 0) {
            $group->update(['status' => EqubGroupStatus::Completed]);

            $this->fcmService->sendToTopic(
                FcmService::equbGroupTopic($group->id),
                [
                    'type' => 'equb_group_completed',
                    'equb_group_id' => (string) $group->id,
                ],
                'Equb completed',
                "{$group->name} has finished all its rounds."
            );
        }
    }
}
