<?php

namespace App\Filament\Widgets;

use App\Models\Agent;
use App\Models\EqubGroup;
use App\Models\EqubPayment;
use App\Models\Member;
use App\Enums\EqubGroupStatus;
use App\Enums\EqubPaymentStatus;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Facades\Auth;

class StatsOverview extends BaseWidget
{
    protected static ?int $sort = 1;

    protected function getStats(): array
    {
        $memberTrend = $this->getTrendData(Member::class);
        $agentTrend = $this->getTrendData(Agent::class);
        $revenueTrend = $this->getTrendData(EqubPayment::class, 'amount', 'payment_date', true);

        return [
            Stat::make('Total Members', Member::count())
                ->description($this->getTrendDescription($memberTrend))
                ->descriptionIcon($this->getTrendIcon($memberTrend))
                ->chart($memberTrend)
                ->color($this->getTrendColor($memberTrend)),
            Stat::make('Total Agents', Agent::count())
                ->description($this->getTrendDescription($agentTrend))
                ->descriptionIcon($this->getTrendIcon($agentTrend))
                ->chart($agentTrend)
                ->color($this->getTrendColor($agentTrend)),
            Stat::make('Active Equb Groups', EqubGroup::where('status', EqubGroupStatus::Running)->count())
                ->description('Groups currently running')
                ->descriptionIcon('heroicon-m-arrow-path')
                ->color('warning'),
            Stat::make('Total Revenue', number_format(EqubPayment::where('status', EqubPaymentStatus::Paid)->sum('amount'), 2) . ' ETB')
                ->description($this->getTrendDescription($revenueTrend, true))
                ->descriptionIcon($this->getTrendIcon($revenueTrend))
                ->chart($revenueTrend)
                ->color($this->getTrendColor($revenueTrend)),
        ];
    }

    protected function getTrendData($model, $column = '*', $dateColumn = 'created_at', $isSum = false): array
    {
        $data = [];
        for ($i = 6; $i >= 0; $i--) {
            $date = now()->subDays($i);
            $query = $model::whereDate($dateColumn, $date);

            if ($model === EqubPayment::class) {
                $query->where('status', EqubPaymentStatus::Paid);
            }

            $data[] = $isSum ? (float) $query->sum($column) : $query->count();
        }
        return $data;
    }

    protected function getTrendDescription(array $data, bool $isCurrency = false): string
    {
        $lastValue = end($data);
        $previousValue = prev($data) ?: 0;

        if ($previousValue == 0) {
            return $lastValue > 0 ? '100% increase' : 'No change';
        }

        $percentageChange = (($lastValue - $previousValue) / $previousValue) * 100;

        if ($percentageChange > 0) {
            return number_format($percentageChange, 0) . '% increase';
        } elseif ($percentageChange < 0) {
            return number_format(abs($percentageChange), 0) . '% decrease';
        }

        return 'No change';
    }

    protected function getTrendIcon(array $data): string
    {
        $lastValue = end($data);
        $previousValue = prev($data) ?: 0;

        return $lastValue >= $previousValue ? 'heroicon-m-arrow-trending-up' : 'heroicon-m-arrow-trending-down';
    }

    protected function getTrendColor(array $data): string
    {
        $lastValue = end($data);
        $previousValue = prev($data) ?: 0;

        return $lastValue >= $previousValue ? 'success' : 'danger';
    }

    public static function canView(): bool
    {
        return Auth::check() && (Auth::user()->hasRole('Super Admin') || Auth::user()->can('dashboard.view.stats_cards'));
    }
}
