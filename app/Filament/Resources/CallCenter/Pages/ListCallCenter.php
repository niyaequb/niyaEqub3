<?php

namespace App\Filament\Resources\CallCenter\Pages;

use App\Filament\Resources\CallCenter\CallCenterResource;
use App\Filament\Resources\Terminations\TerminationResource;
use App\Models\CallCenterLog;
use App\Models\EqubMembership;
use App\Services\CallCenterService;
use Filament\Actions\Action;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Illuminate\Database\Eloquent\Builder;

class ListCallCenter extends ListRecords
{
    protected static string $resource = CallCenterResource::class;

    public function getTitle(): string
    {
        return 'Call Center';
    }

    public function getSubheading(): ?string
    {
        $today = rescue(fn () => CallCenterLog::query()->whereDate('created_at', today())->count(), 0, false);

        return "Members in running Equbs, grouped by how long they have not paid. Calls logged today: {$today}.";
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('terminations')
                ->label('Go to Termination')
                ->icon('heroicon-o-user-minus')
                ->color('danger')
                ->url(TerminationResource::getUrl('index')),
        ];
    }

    public function getDefaultActiveTab(): string|int|null
    {
        return 'unpaid';
    }

    /**
     * NOTE: Filament passes arguments to these closures BY NAME. The query
     * argument must be called $query; with any other name Filament injects an
     * empty Builder and the page crashes ("newQueryWithoutRelationships() on null").
     */
    public function getTabs(): array
    {
        $count = function (callable $scope): int {
            return (int) rescue(
                fn () => $scope(CallCenterService::liveMemberships(EqubMembership::query()))->count(),
                0,
                false
            );
        };

        $unpaid7 = fn (Builder $query): Builder => CallCenterService::unpaidBetween($query, 7);
        $neverPaid = fn (Builder $query): Builder => CallCenterService::unpaidBetween(CallCenterService::neverPaid($query), 1);
        $promised = fn (Builder $query): Builder => $query->whereHas(
            'latestCallLog',
            fn (Builder $log) => $log->whereIn('outcome', ['promised_to_pay', 'will_pay_today'])
        );
        $notCalled = fn (Builder $query): Builder => CallCenterService::unpaidBetween($query, 7)->whereNull('called_at');
        $called = fn (Builder $query): Builder => $query->whereNotNull('called_at');

        $tabs = [
            'all' => Tab::make('All members')
                ->icon('heroicon-o-users')
                ->badge($count(fn (Builder $query) => $query)),

            'unpaid' => Tab::make('Unpaid 7+ days')
                ->icon('heroicon-o-exclamation-triangle')
                ->badge($count($unpaid7))
                ->badgeColor('danger')
                ->modifyQueryUsing($unpaid7),
        ];

        foreach (CallCenterService::CATEGORIES as $key => [$label, $min, $max]) {
            $scope = fn (Builder $query): Builder => CallCenterService::unpaidBetween($query, $min, $max);

            $tabs[$key] = Tab::make($label)
                ->badge($count($scope))
                ->badgeColor($min >= 31 ? 'danger' : ($min >= 15 ? 'warning' : 'info'))
                ->modifyQueryUsing($scope);
        }

        $tabs['never_paid'] = Tab::make('Never paid')
            ->badge($count($neverPaid))
            ->badgeColor('danger')
            ->modifyQueryUsing($neverPaid);

        $tabs['promised'] = Tab::make('Promised to pay')
            ->icon('heroicon-o-hand-thumb-up')
            ->badge($count($promised))
            ->modifyQueryUsing($promised);

        $tabs['not_called'] = Tab::make('Uncalled')
            ->icon('heroicon-o-phone-x-mark')
            ->badge($count($notCalled))
            ->badgeColor('gray')
            ->modifyQueryUsing($notCalled);

        $tabs['called'] = Tab::make('Called')
            ->icon('heroicon-o-phone-arrow-up-right')
            ->badge($count($called))
            ->badgeColor('success')
            ->modifyQueryUsing($called);

        return $tabs;
    }
}
