<?php

namespace App\Filament\Resources\MemberEqubGroups\Pages;

use App\Filament\Resources\MemberEqubGroups\MemberEqubGroupResource;
use App\Models\EqubGroup;
use App\Services\EqubGroupLedgerService;
use App\Services\MemberEqubGroupService;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\Page;

/**
 * Contribution ledger for one group: totals across the circle plus a
 * per-member paid / unpaid breakdown.
 */
class GroupLedger extends Page
{
    protected static string $resource = MemberEqubGroupResource::class;

    protected string $view = 'filament.pages.group-ledger';

    public EqubGroup $record;

    /** @var array<string, mixed> */
    public array $ledger = [];

    public function mount(int|string $record): void
    {
        $this->record = EqubGroup::with(['package', 'owner.user'])->findOrFail($record);
        $this->loadLedger();
    }

    public function loadLedger(): void
    {
        $this->ledger = app(EqubGroupLedgerService::class)->forGroup($this->record);
    }

    public function getTitle(): string
    {
        return $this->record->name.' — '.__('filament.member_equb_group.ledger');
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('refresh')
                ->label(__('filament.member_equb_group.refresh'))
                ->icon('heroicon-o-arrow-path')
                ->action(fn () => $this->loadLedger()),

            Action::make('remind')
                ->label(__('filament.member_equb_group.remind_unpaid'))
                ->icon('heroicon-o-bell-alert')
                ->color('warning')
                ->requiresConfirmation()
                ->visible(fn (): bool => ($this->ledger['group']['members_behind'] ?? 0) > 0)
                ->action(function (): void {
                    $service = app(MemberEqubGroupService::class);
                    $ledgerService = app(EqubGroupLedgerService::class);

                    $result = $service->remindUnpaid($this->record, $ledgerService->membersBehind($this->record));

                    Notification::make()->title($result['message'])->success()->send();
                    $this->loadLedger();
                }),
        ];
    }
}
