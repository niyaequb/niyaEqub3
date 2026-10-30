<?php

namespace App\Filament\Resources\CallCenter;

use App\Filament\Resources\CallCenter\Pages\ListCallCenter;
use App\Models\CallCenterLog;
use App\Models\EqubMembership;
use App\Models\EqubTermination;
use App\Services\CallCenterService;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\BulkAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

/**
 * Call Center: every member in a live Equb, grouped by how long they have
 * gone without paying, with a call log for their feedback.
 */
class CallCenterResource extends Resource
{
    protected static ?string $model = EqubMembership::class;

    protected static ?string $slug = 'call-center';

    protected static ?int $navigationSort = 1;

    public static function getNavigationIcon(): ?string
    {
        return 'heroicon-o-phone-arrow-up-right';
    }

    public static function getNavigationLabel(): string
    {
        return 'Call Center';
    }

    public static function getModelLabel(): string
    {
        return 'member';
    }

    public static function getPluralModelLabel(): string
    {
        return 'Call Center';
    }

    public static function getNavigationGroup(): ?string
    {
        return 'Call Center';
    }

    public static function getNavigationBadge(): ?string
    {
        try {
            $count = CallCenterService::unpaidBetween(
                CallCenterService::liveMemberships(EqubMembership::query()), 7
            )->count();

            return $count > 0 ? (string) $count : null;
        } catch (\Throwable) {
            return null; // e.g. before the migration has been run
        }
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'danger';
    }

    public static function getEloquentQuery(): Builder
    {
        return CallCenterService::liveMemberships(parent::getEloquentQuery())
            ->with(['equbGroup', 'member.user', 'sponsor.user', 'latestCallLog.caller', 'calledBy']);
    }

    public static function table(Table $table): Table
    {
        $service = app(CallCenterService::class);
        $ref = CallCenterService::referenceDateSql();

        return $table
            ->defaultSort(fn (Builder $query) => $query->orderByRaw("{$ref} asc"))
            ->columns([
                TextColumn::make('member_name')
                    ->label('Member')
                    ->state(fn (EqubMembership $record): string => $record->displayName())
                    ->description(fn (EqubMembership $record): ?string => $service->phone($record))
                    ->weight('bold')
                    ->searchable(query: function (Builder $query, string $search): Builder {
                        $term = '%'.$search.'%';

                        return $query->where(fn (Builder $q) => $q
                            ->whereHas('member', fn (Builder $m) => $m->where('full_name', 'like', $term)
                                ->orWhereHas('user', fn (Builder $u) => $u->where('phone', 'like', $term)))
                            ->orWhere('responsibility_name', 'like', $term)
                            ->orWhere('responsibility_phone', 'like', $term));
                    }),
                TextColumn::make('equbGroup.name')
                    ->label('Equb')
                    ->wrap()
                    ->searchable(),
                TextColumn::make('contribution_amount')
                    ->label('Contribution')
                    ->numeric(decimalPlaces: 2)
                    ->suffix(' ETB'),
                TextColumn::make('days_unpaid')
                    ->label('Days unpaid')
                    ->state(fn (EqubMembership $record): int => $service->daysUnpaid($record))
                    ->badge()
                    ->color(fn (int $state): string => match (true) {
                        $state >= 31 => 'danger',
                        $state >= 15 => 'warning',
                        $state >= 7 => 'info',
                        default => 'success',
                    })
                    ->sortable(query: fn (Builder $query, string $direction): Builder => $query->orderByRaw(
                        "{$ref} ".($direction === 'asc' ? 'desc' : 'asc')
                    )),
                TextColumn::make('arrears')
                    ->label('Owes')
                    ->state(fn (EqubMembership $record): float => $service->arrears($record))
                    ->numeric(decimalPlaces: 2)
                    ->suffix(' ETB')
                    ->color(fn (float $state): string => $state > 0 ? 'danger' : 'success'),
                IconColumn::make('has_won')
                    ->label('Won')
                    ->boolean()
                    ->tooltip('Already received the Equb payout'),
                TextColumn::make('latestCallLog.outcome')
                    ->label('Last call')
                    ->badge()
                    ->formatStateUsing(fn (?string $state): string => CallCenterLog::OUTCOMES[$state] ?? '—')
                    ->color(fn (?string $state): string => CallCenterLog::OUTCOME_COLORS[$state] ?? 'gray')
                    ->description(fn (EqubMembership $record): ?string => $record->latestCallLog
                        ? $record->latestCallLog->created_at?->diffForHumans().' · '.($record->latestCallLog->caller?->name ?? '')
                        : null)
                    ->placeholder('Not called yet'),
                TextColumn::make('latestCallLog.promised_payment_date')
                    ->label('Promised date')
                    ->date()
                    ->placeholder('—')
                    ->toggleable(),
                TextColumn::make('latestCallLog.feedback')
                    ->label('Feedback')
                    ->limit(40)
                    ->tooltip(fn (EqubMembership $record): ?string => $record->latestCallLog?->feedback)
                    ->placeholder('—')
                    ->toggleable(),
                TextColumn::make('termination_requested_at')
                    ->label('Sent to termination')
                    ->since()
                    ->badge()
                    ->color('danger')
                    ->placeholder('—')
                    ->toggleable(),
                TextColumn::make('join_date')
                    ->label('Joined')
                    ->date()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('equb_group_id')
                    ->label('Equb')
                    ->relationship('equbGroup', 'name')
                    ->searchable()
                    ->preload(),
                SelectFilter::make('last_outcome')
                    ->label('Last call outcome')
                    ->options(CallCenterLog::OUTCOMES)
                    ->query(fn (Builder $query, array $data): Builder => filled($data['value'] ?? null)
                        ? $query->whereHas('latestCallLog', fn (Builder $q) => $q->where('outcome', $data['value']))
                        : $query),
                SelectFilter::make('called')
                    ->label('Call status')
                    ->options(['yes' => 'Called', 'no' => 'Uncalled'])
                    ->query(fn (Builder $query, array $data): Builder => match ($data['value'] ?? null) {
                        'yes' => $query->whereNotNull('called_at'),
                        'no' => $query->whereNull('called_at'),
                        default => $query,
                    }),
            ])
            ->recordActions([
                // Called / Uncalled toggle. Click to switch the status.
                Action::make('toggle_called')
                    ->label(fn (EqubMembership $record): string => $record->called_at ? 'Called' : 'Uncalled')
                    ->icon(fn (EqubMembership $record): string => $record->called_at
                        ? 'heroicon-s-phone-arrow-up-right'
                        : 'heroicon-o-phone-x-mark')
                    ->color(fn (EqubMembership $record): string => $record->called_at ? 'success' : 'gray')
                    ->tooltip(fn (EqubMembership $record): string => $record->called_at
                        ? 'Called '.$record->called_at->diffForHumans().($record->calledBy ? ' by '.$record->calledBy->name : '').' — click to mark Uncalled'
                        : 'Click to mark as Called')
                    ->button()
                    ->visible(fn (): bool => CallCenterService::canUse('call-center.index'))
                    ->action(function (EqubMembership $record): void {
                        $called = $record->called_at === null;

                        $record->update([
                            'called_at' => $called ? now() : null,
                            'called_by' => $called ? auth()->id() : null,
                        ]);

                        Notification::make()
                            ->title($record->displayName().' marked as '.($called ? 'Called' : 'Uncalled').'.')
                            ->color($called ? 'success' : 'gray')
                            ->send();
                    }),
                Action::make('log_call')
                    ->label('Feedback')
                    ->icon('heroicon-o-chat-bubble-left-ellipsis')
                    ->color('primary')
                    ->button()
                    ->visible(fn (): bool => CallCenterService::canUse('call-center.index'))
                    ->modalHeading(fn (EqubMembership $record): string => 'Call result — '.$record->displayName())
                    ->modalDescription(fn (EqubMembership $record): string => sprintf(
                        'Phone: %s · Unpaid for %d days · Owes %s ETB',
                        $service->phone($record) ?? '—',
                        $service->daysUnpaid($record),
                        number_format($service->arrears($record), 2)
                    ))
                    ->schema([
                        Select::make('outcome')
                            ->label('What happened?')
                            ->options(CallCenterLog::OUTCOMES)
                            ->required()
                            ->live(),
                        DatePicker::make('promised_payment_date')
                            ->label('Promised to pay on')
                            ->minDate(today())
                            ->visible(fn (Get $get): bool => in_array($get('outcome'), ['promised_to_pay', 'call_back_later', 'financial_problem'], true)),
                        Textarea::make('feedback')
                            ->label('Member feedback / notes')
                            ->rows(4)
                            ->maxLength(2000),
                        Toggle::make('send_to_termination')
                            ->label('Send this member to the Termination page')
                            ->helperText('Use when the member refuses or cannot be reached.'),
                    ])
                    ->action(function (EqubMembership $record, array $data) use ($service): void {
                        $service->logCall($record, $data);

                        // Saving call feedback also marks the member as Called.
                        $record->update(['called_at' => now(), 'called_by' => auth()->id()]);

                        if (! empty($data['send_to_termination'])) {
                            $record->update(['termination_requested_at' => now()]);
                        }

                        Notification::make()->success()->title('Call feedback saved.')->send();
                    }),
                ActionGroup::make([
                    Action::make('dial')
                        ->label(fn (EqubMembership $record): string => 'Dial '.($service->phone($record) ?? ''))
                        ->icon('heroicon-o-phone')
                        ->url(fn (EqubMembership $record): ?string => ($phone = $service->phone($record)) ? 'tel:'.$phone : null)
                        ->visible(fn (EqubMembership $record): bool => filled($service->phone($record))),
                    Action::make('history')
                        ->label('Call history')
                        ->icon('heroicon-o-clock')
                        ->modalHeading(fn (EqubMembership $record): string => 'Call history — '.$record->displayName())
                        ->modalContent(fn (EqubMembership $record) => view('filament.call-center.history', [
                            'logs' => $record->callLogs()->with('caller')->get(),
                            'terminations' => $record->terminations()->with('terminatedBy')->get(),
                        ]))
                        ->modalSubmitAction(false)
                        ->modalCancelActionLabel('Close'),
                    Action::make('send_to_termination')
                        ->label('Send to termination')
                        ->icon('heroicon-o-user-minus')
                        ->color('danger')
                        ->visible(fn (EqubMembership $record): bool => $record->termination_requested_at === null
                            && CallCenterService::canUse('call-center.index'))
                        ->requiresConfirmation()
                        ->action(fn (EqubMembership $record) => $record->update(['termination_requested_at' => now()])),
                    Action::make('cancel_termination_request')
                        ->label('Cancel termination request')
                        ->icon('heroicon-o-arrow-uturn-left')
                        ->visible(fn (EqubMembership $record): bool => $record->termination_requested_at !== null
                            && CallCenterService::canUse('call-center.index'))
                        ->action(fn (EqubMembership $record) => $record->update(['termination_requested_at' => null])),
                ])->icon('heroicon-m-ellipsis-vertical'),
            ])
            ->toolbarActions([
                BulkAction::make('bulk_mark_called')
                    ->label('Mark selected Called')
                    ->icon('heroicon-s-phone-arrow-up-right')
                    ->color('success')
                    ->visible(fn (): bool => CallCenterService::canUse('call-center.index'))
                    ->deselectRecordsAfterCompletion()
                    ->action(function (Collection $records): void {
                        $records->each(fn (EqubMembership $m) => $m->update(['called_at' => now(), 'called_by' => auth()->id()]));
                        Notification::make()->success()->title($records->count().' member(s) marked Called.')->send();
                    }),
                BulkAction::make('bulk_mark_uncalled')
                    ->label('Mark selected Uncalled')
                    ->icon('heroicon-o-phone-x-mark')
                    ->color('gray')
                    ->visible(fn (): bool => CallCenterService::canUse('call-center.index'))
                    ->deselectRecordsAfterCompletion()
                    ->action(function (Collection $records): void {
                        $records->each(fn (EqubMembership $m) => $m->update(['called_at' => null, 'called_by' => null]));
                        Notification::make()->title($records->count().' member(s) marked Uncalled.')->send();
                    }),
                BulkAction::make('bulk_send_to_termination')
                    ->label('Send selected to termination')
                    ->icon('heroicon-o-user-minus')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->visible(fn (): bool => CallCenterService::canUse('call-center.index'))
                    ->deselectRecordsAfterCompletion()
                    ->action(function (Collection $records): void {
                        $records->each(fn (EqubMembership $m) => $m->update(['termination_requested_at' => now()]));
                        Notification::make()->success()->title($records->count().' member(s) sent to termination.')->send();
                    }),
            ])
            ->emptyStateHeading('Nobody in this list')
            ->emptyStateDescription('Everyone in this category is up to date.')
            ->striped()
            ->paginated([10, 25, 50, 100]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListCallCenter::route('/'),
        ];
    }

    public static function canViewAny(): bool
    {
        return CallCenterService::canUse('call-center.index');
    }

    public static function shouldRegisterNavigation(): bool
    {
        return static::canViewAny();
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit($record): bool
    {
        return false;
    }

    public static function canDelete($record): bool
    {
        return false;
    }
}
