<?php

namespace App\Filament\Pages;

use Filament\Pages\Dashboard as BaseDashboard;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\View as ViewComponent;
use Filament\Schemas\Schema;
use Illuminate\Support\Facades\Auth;

class Dashboard extends BaseDashboard
{
    public function getWidgets(): array
    {
        return [
            \App\Filament\Widgets\StatsOverview::class,
            \App\Filament\Widgets\MemberGrowthChart::class,
            \App\Filament\Widgets\PaymentTrendsChart::class,
            \App\Filament\Widgets\LatestEqubPayments::class,
        ];
    }

    public function content(Schema $schema): Schema
    {
        $user = Auth::user();

        // Check if user has any dashboard permissions
        $hasDashboardPermissions = $user->hasRole('Super Admin') ||
            $user->can('dashboard.view.stats_cards') ||
            $user->can('dashboard.view.member_growth') ||
            $user->can('dashboard.view.payment_trends') ||
            $user->can('dashboard.view.latest_payments');

        if (!$hasDashboardPermissions) {
            return $schema
                ->components([
                    Section::make()
                        ->schema([
                            ViewComponent::make('filament.pages.dashboard.welcome-banner')
                                ->viewData([
                                    'name' => $user->name,
                                ])
                        ])
                ]);
        }

        // Use default widget rendering with proper grid layout
        return parent::content($schema);
    }
}


