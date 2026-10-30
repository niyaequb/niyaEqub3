<?php

namespace App\Filament\Resources\Terminations;

use App\Enums\EqubMembershipStatus;
use App\Filament\Resources\Terminations\Pages\ListTerminations;
use App\Models\CallCenterLog;
use App\Models\EqubMembership;
use App\Models\EqubTermination;
use App\Services\CallCenterService;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

/**
 * Termination: remove members who did not pay from their Equb place.
 *
 * A terminated place is set to "cancelled" (it stops owing, and the draw
 * skips it) and a permanent record is written to equb_terminations, so the
 * history and the money owed at the time are never lost. A termination can
 * be undone with Reinstate.
 */
class TerminationResource extends Resource
{
    protected static ?string $model = EqubMembership::class;

    protected static ?string $slug = 'terminations';

    protected static ?int $navigationSort = 2;

    public static function getNavigationIcon(): ?string
    {
        return 'heroicon-o-user-minus';
    }

    public static function getNavigationLabel(): string
    {
        return 'Termination';
    }

    public static function getModelLabel(): string
    {
        return 'member';
    }

    public static function getPluralModelLabel(): string
    {
        return 'Termination';
    }

    public static function getNavigationGroup(): ?string
    {
        return 'Call Center';
    }

    public static function getNavigationBadge(): ?string
    {
        try {
            $count = EqubMembership::query()
                ->where('status', EqubMembershipStatus::Active->value)
                ->whereNotNull('termination_requested_at')
                ->count();

            return $count > 0 ? (string) $count : null;
        } catch (\Throwable) {
            return null;
        }
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'danger';
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->with(['equbGroup', 'member.user', 'sponsor.user', 'latestCallLog', 'terminations.terminatedBy']);
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
                            ->orWhere('responsibility_name', 'like', $term));
                    }),
                TextColumn::make('equbGroup.name')
                    ->label('Equb')
                    ->wrap(),
                TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->formatStateUsing(fn ($state): string => $state === EqubMembershipStatus::Cancelled
                        ? 'Terminated'
                        : ucfirst((string) ($state instanceof \BackedEnum ? $state->value : $state)))
                    ->color(fn ($state): string => $state === EqubMembershipStatus::Cancelled ? 'danger' : 'success'),
                TextColumn::make('days_unpaid')
                    ->label('Days unpaid')
                    ->state(fn (EqubMembership $record): int => $service->daysUnpaid($record))
                    ->badge()
                    ->color(fn (int $state): string => $state >= 31 ? 'danger' : ($state >= 15 ? 'warning' : 'info')),
                TextColumn::make('arrears')
                    ->label('Owes')
                    ->state(fn (EqubMembership $record): float => $record->status === EqubMembershipStatus::Cancelled
                        ? (float) ($record->terminations->first()?->arrears_at_termination ?? 0)
                        : $service->arrears($record))
                    ->numeric(decimalPlaces: 2)
                    ->suffix(' ETB'),
                TextColumn::make('total_paid')
                    ->label('Paid so far')
                    ->state(fn (EqubMembership $record): float => $record->total_paid)
                    ->numeric(decimalPlaces: 2)
                    ->suffix(' ETB')
                    ->toggleable(),
                IconColumn::make('has_won')
                    ->label('Won')
                    ->boolean()
                    ->tooltip('Already received the payout — terminating does NOT recover that money'),
                TextColumn::make('latestCallLog.outcome')
                    ->label('Last call')
                    ->badge()
                    ->formatStateUsing(fn (?string $state): string => CallCenterLog::OUTCOMES[$state] ?? '—')
                    ->color(fn (?string $state): string => CallCenterLog::OUTCOME_COLORS[$state] ?? 'gray')
                    ->placeholder('Never called'),
                TextColumn::make('termination_requested_at')
                    ->label('Requested')
                    ->since()
                    ->placeholder('—'),
                TextColumn::make('terminated_info')
                    ->label('Terminated')
                    ->state(function (EqubMembership $record): ?string {
                        $t = $record->terminations->first();

                        return ($t && ! $t->reinstated_at)
                            ? $t->created_at?->format('M j, Y').' · '.$t->reasonLabel().' · '.($t->terminatedBy?->name ?? '')
                            : null;
                    })
                    ->placeholder('—')
                    ->wrap(),
            ])
            ->filters([
                SelectFilter::make('equb_group_id')
                    ->label('Equb')
                    ->relationship('equbGroup', 'name')
                    ->searchable()
                    ->preload(),
            ])
            ->recordActions([
                Action::make('terminate')
                    ->label('Terminate')
                    ->icon('heroicon-o-user-minus')
                    ->color('danger')
                    ->button()
                    ->visible(fn (EqubMembership $record): bool => $record->status === EqubMembershipStatus::Active
                        && CallCenterService::canUse('terminations.edit'))
                    ->modalHeading(fn (EqubMembership $record): string => 'Terminate '.$record->displayName().'?')
                    ->modalDescription(fn (EqubMembership $record): string => sprintf(
                        '%s · unpaid %d days · owes %s ETB.%s The place is cancelled and the member leaves the draw. You can reinstate later.',
                        $record->equbGroup?->name ?? 'Equb',
                        $service->daysUnpaid($record),
                        number_format($service->arrears($record), 2),
                        $record->hasReceivedPayout() ? ' WARNING: this member already received the payout.' : ''
                    ))
                    ->schema([
                        Select::make('reason')
                            ->options(EqubTermination::REASONS)
                            ->default('non_payment')
                            ->required(),
                        Textarea::make('note')
                            ->label('Note')
                            ->rows(3),
                        Toggle::make('notify')
                            ->label('Send the member an SMS')
                            ->default(true),
                    ])
                    ->action(function (EqubMembership $record, array $data) use ($service): void {
                        $service->terminate($record, $data['reason'], $data['note'] ?? null, (bool) ($data['notify'] ?? false));
                        Notification::make()->success()->title($record->displayName().' was terminated.')->send();
                    }),
                Action::make('keep')
                    ->label('Keep')
                    ->icon('heroicon-o-arrow-uturn-left')
                    ->color('gray')
                    ->tooltip('Remove from the termination list without terminating')
                    ->visible(fn (EqubMembership $record): bool => $record->status === EqubMembershipStatus::Active
                        && $record->termination_requested_at !== null)
                    ->action(fn (EqubMembership $record) => $record->update(['termination_requested_at' => null])),
                Action::make('reinstate')
                    ->label('Reinstate')
                    ->icon('heroicon-o-arrow-path')
                    ->color('success')
                    ->button()
                    ->visible(fn (EqubMembership $record): bool => $record->status === EqubMembershipStatus::Cancelled
                        && $record->terminations->first() && ! $record->terminations->first()->reinstated_at
                        && CallCenterService::canUse('terminations.edit'))
                    ->requiresConfirmation()
                    ->modalDescription('The member goes back into the Equb as active.')
                    ->action(function (EqubMembership $record) use ($service): void {
                        $service->reinstate($record->terminations->first());
                        Notification::make()->success()->title($record->displayName().' was reinstated.')->send();
                    }),
            ])
            ->toolbarActions([
                BulkAction::make('bulk_terminate')
                    ->label('Terminate selected')
                    ->icon('heroicon-o-user-minus')
                    ->color('danger')
                    ->visible(fn (): bool => CallCenterService::canUse('terminations.edit'))
                    ->requiresConfirmation()
                    ->schema([
                        Select::make('reason')->options(EqubTermination::REASONS)->default('non_payment')->required(),
                        Textarea::make('note')->rows(2),
                        Toggle::make('notify')->label('Send SMS')->default(true),
                    ])
                    ->deselectRecordsAfterCompletion()
                    ->action(function (Collection $records, array $data) use ($service): void {
                        $done = 0;
                        $records->each(function (EqubMembership $m) use ($service, $data, &$done): void {
                            if ($m->status === EqubMembershipStatus::Active) {
                                $service->terminate($m, $data['reason'], $data['note'] ?? null, (bool) ($data['notify'] ?? false));
                                $done++;
                            }
                        });
                        Notification::make()->success()->title("{$done} member(s) terminated.")->send();
                    }),
            ])
            ->emptyStateHeading('Nobody to terminate')
            ->striped()
            ->paginated([10, 25, 50, 100]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListTerminations::route('/'),
        ];
    }

    public static function canViewAny(): bool
    {
        return CallCenterService::canUse('terminations.index');
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
