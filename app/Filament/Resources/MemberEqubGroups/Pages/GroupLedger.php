<?php

namespace App\Filament\Resources\MemberEqubGroups\Pages;

use App\Filament\Resources\MemberEqubGroups\MemberEqubGroupResource;
use App\Models\EqubGroup;
use App\Services\EqubGroupLedgerService;
use App\Services\MemberEqubGroupService;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\Concerns\InteractsWithRecord;
use Filament\Resources\Pages\Page;

/**
 * Contribution ledger for one Group Equb: totals across the circle plus a
 * per-member paid / unpaid breakdown.
 */
class GroupLedger extends Page
{
    use InteractsWithRecord;

    protected static string $resource = MemberEqubGroupResource::class;

    protected string $view = 'filament.pages.group-ledger';

    /** @var array<string, mixed> */
    public array $ledger = [];

    public function mount(int|string $record): void
    {
        // Resolves through the resource so route model binding and
        // authorisation behave like every other record page.
        $this->record = $this->resolveRecord($record);

        $this->loadLedger();
    }

    public function loadLedger(): void
    {
        /** @var EqubGroup $group */
        $group = $this->record;

        $this->ledger = app(EqubGroupLedgerService::class)->forGroup($group);
    }

    public function getTitle(): string
    {
        return $this->record->name.' — '.__('filament.member_equb_group.ledger');
    }

    protected function getHeaderActions(): array
    {
        return [
            EditAction::make()
                ->record(fn () => $this->record),

            Action::make('refresh')
                ->label(__('filament.member_equb_group.refresh'))
                ->icon('heroicon-o-arrow-path')
                ->color('gray')
                ->action(fn () => $this->loadLedger()),

            Action::make('remind')
                ->label(__('filament.member_equb_group.remind_unpaid'))
                ->icon('heroicon-o-bell-alert')
                ->color('warning')
                ->requiresConfirmation()
                ->visible(fn (): bool => ($this->ledger['group']['members_behind'] ?? 0) > 0)
                ->action(function (): void {
                    /** @var EqubGroup $group */
                    $group = $this->record;

                    $result = app(MemberEqubGroupService::class)->remindUnpaid(
                        $group,
                        app(EqubGroupLedgerService::class)->membersBehind($group),
                    );

                    Notification::make()->title($result['message'])->success()->send();
                    $this->loadLedger();
                }),
        ];
    }
}
