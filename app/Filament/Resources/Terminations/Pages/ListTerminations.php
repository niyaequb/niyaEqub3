<?php

namespace App\Filament\Resources\Terminations\Pages;

use App\Enums\EqubMembershipStatus;
use App\Filament\Resources\CallCenter\CallCenterResource;
use App\Filament\Resources\Terminations\TerminationResource;
use App\Models\EqubMembership;
use App\Services\CallCenterService;
use Filament\Actions\Action;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Illuminate\Database\Eloquent\Builder;

class ListTerminations extends ListRecords
{
    protected static string $resource = TerminationResource::class;

    public function getTitle(): string
    {
        return 'Termination';
    }

    public function getSubheading(): ?string
    {
        return 'Members sent here by the call center, and members unpaid for over 30 days. Terminated members leave the Equb and the draw; you can reinstate them.';
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('call_center')
                ->label('Back to Call Center')
                ->icon('heroicon-o-phone')
                ->color('gray')
                ->url(CallCenterResource::getUrl('index')),
        ];
    }

    public function getDefaultActiveTab(): string|int|null
    {
        return 'requested';
    }

    /**
     * NOTE: Filament passes arguments to these closures BY NAME, so the query
     * argument must be called $query (any other name gets an empty Builder).
     */
    public function getTabs(): array
    {
        $requested = fn (Builder $query): Builder => CallCenterService::liveMemberships($query)
            ->whereNotNull('termination_requested_at');

        $overdue = fn (Builder $query): Builder => CallCenterService::unpaidBetween(
            CallCenterService::liveMemberships($query),
            31
        );

        $terminated = fn (Builder $query): Builder => $query
            ->where('status', EqubMembershipStatus::Cancelled->value)
            ->whereHas('terminations', fn (Builder $t) => $t->whereNull('reinstated_at'));

        $count = fn (callable $scope): int => (int) rescue(fn () => $scope(EqubMembership::query())->count(), 0, false);

        return [
            'requested' => Tab::make('Sent by call center')
                ->icon('heroicon-o-phone-arrow-down-left')
                ->badge($count($requested))
                ->badgeColor('danger')
                ->modifyQueryUsing($requested),
            'overdue' => Tab::make('Unpaid over 30 days')
                ->icon('heroicon-o-exclamation-triangle')
                ->badge($count($overdue))
                ->badgeColor('warning')
                ->modifyQueryUsing($overdue),
            'terminated' => Tab::make('Terminated')
                ->icon('heroicon-o-archive-box-x-mark')
                ->badge($count($terminated))
                ->modifyQueryUsing($terminated),
        ];
    }
}
