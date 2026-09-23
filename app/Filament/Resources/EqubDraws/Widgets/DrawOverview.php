<?php

namespace App\Filament\Resources\EqubDraws\Widgets;

use App\Enums\EqubGroupStatus;
use App\Models\EqubDraw;
use App\Models\EqubDrawWinner;
use App\Models\EqubGroup;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Facades\DB;

/**
 * The state of the draws, above the list.
 *
 * Four figures, each chosen because it is the answer to a question somebody
 * would otherwise have to go and work out:
 *
 *   Rounds run         is this Equb moving, and how fast
 *   Paid out           how much has actually left the circle
 *   Still waiting      how many members have paid every round and never won,
 *                      which is the fairness question in one number
 *   Verifiable         how many rounds can be recomputed and checked
 *
 * The last one is deliberately prominent. Rounds run before the audit trail
 * existed cannot be re-derived, and a system that quietly mixes verifiable and
 * unverifiable results is claiming more than it can show.
 */
class DrawOverview extends BaseWidget
{
    protected function getStats(): array
    {
        $totalDraws = EqubDraw::count();
        $auditable = EqubDraw::whereNotNull('random_seed')->count();

        $last7 = EqubDraw::query()
            ->where('draw_date', '>=', now()->subDays(7))
            ->count();

        // Across both shapes of round. `equb_draw_winners` holds one row per
        // winner on a group round; a single-winner round has none, and its
        // payout is the winning membership's expected total — contribution x
        // rounds, which is the pot in an Equb where everyone pays every round.
        // Summing only one of the two would understate the money by whichever
        // shape was left out, and summing a single contribution instead of the
        // pot would understate it by the length of the Equb.
        $groupPaid = (float) EqubDrawWinner::sum('amount_won');

        $rounds = "(case
            when eg.duration_type = 'per_member'
                then coalesce(eg.max_members, eg.current_members_count, 0)
            else coalesce(eg.duration_value, 0)
        end)";

        $singlePaid = (float) DB::table('equb_draws as d')
            ->join('equb_memberships as em', 'em.id', '=', 'd.winner_membership_id')
            ->join('equb_groups as eg', 'eg.id', '=', 'em.equb_group_id')
            ->leftJoin('equb_draw_winners as w', 'w.equb_draw_id', '=', 'd.id')
            ->whereNull('w.id')
            ->sum(DB::raw("coalesce(em.contribution_amount, 0) * {$rounds}"));

        $waiting = DB::table('equb_memberships as em')
            ->join('equb_groups as eg', 'eg.id', '=', 'em.equb_group_id')
            ->where('em.status', 'active')
            ->where('em.has_won', false)
            ->whereIn('eg.status', [EqubGroupStatus::Running->value, EqubGroupStatus::Registration->value])
            ->count();

        $runningEqubs = EqubGroup::query()
            ->whereNull('owner_member_id')
            ->where('status', EqubGroupStatus::Running)
            ->count();

        return [
            Stat::make(__('filament.equb_draw.stat_rounds'), number_format($totalDraws))
                ->description(__('filament.equb_draw.stat_rounds_hint', [
                    'recent' => number_format($last7),
                    'equbs' => number_format($runningEqubs),
                ]))
                ->descriptionIcon('heroicon-m-ticket')
                ->color('primary'),

            Stat::make(__('filament.equb_draw.stat_paid_out'), number_format($groupPaid + $singlePaid, 2).' ETB')
                ->description(__('filament.equb_draw.stat_paid_out_hint'))
                ->descriptionIcon('heroicon-m-banknotes')
                ->color('success'),

            Stat::make(__('filament.equb_draw.stat_waiting'), number_format($waiting))
                ->description(__('filament.equb_draw.stat_waiting_hint'))
                ->descriptionIcon('heroicon-m-clock')
                ->color($waiting > 0 ? 'warning' : 'gray'),

            Stat::make(
                __('filament.equb_draw.stat_verifiable'),
                $totalDraws > 0
                    ? number_format(($auditable / $totalDraws) * 100, 0).'%'
                    : '—'
            )
                ->description(__('filament.equb_draw.stat_verifiable_hint', [
                    'auditable' => number_format($auditable),
                    'total' => number_format($totalDraws),
                ]))
                ->descriptionIcon('heroicon-m-shield-check')
                ->color($auditable === $totalDraws && $totalDraws > 0 ? 'success' : 'gray'),
        ];
    }
}
