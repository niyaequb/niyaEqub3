<?php

namespace App\Filament\Resources\Agents\Tables;

use App\Models\Agent;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\BulkAction;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;

class AgentsTable
{
    private static function can(string $permission): bool
    {
        return Auth::check() && (Auth::user()->hasRole('Super Admin') || Auth::user()->can($permission));
    }

    public static function configure(Table $table): Table
    {
        $table
            ->defaultSort('id', 'desc')
            ->columns([
                TextColumn::make('id')
                    ->label('ID')
                    ->sortable(),
                TextColumn::make('user.name')
                    ->label('Name')
                    ->placeholder('— login deleted —')
                    ->weight('bold')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('user.phone')
                    ->label('Phone')
                    ->placeholder('—')
                    ->copyable()
                    ->searchable(),
                TextColumn::make('referral_code')
                    ->label('Referral Code')
                    ->badge()
                    ->color('gray')
                    ->copyable()
                    ->searchable()
                    ->sortable(),
                TextColumn::make('members_count')
                    ->label('Members')
                    ->counts('members')
                    ->sortable(),
                TextColumn::make('pending_commission')
                    ->label('Pending (ETB)')
                    ->state(fn (Agent $record): float => (float) $record->commissions()->where('status', 'pending')->sum('commission_amount'))
                    ->numeric(decimalPlaces: 2),
                TextColumn::make('earned_commission')
                    ->label('Earned (ETB)')
                    ->state(fn (Agent $record): float => (float) $record->commissions()->sum('commission_amount'))
                    ->numeric(decimalPlaces: 2)
                    ->toggleable(),
                IconColumn::make('is_active')
                    ->label('Active')
                    ->boolean()
                    ->sortable(),
                TextColumn::make('joined_at')
                    ->label('Joined At')
                    ->dateTime()
                    ->sortable(),
                TextColumn::make('bank_name')
                    ->label('Bank')
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('account_number')
                    ->label('Account Number')
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('account_holder_name')
                    ->label('Account Holder')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                TernaryFilter::make('is_active')->label('Active'),
                Filter::make('orphaned')
                    ->label('Login deleted (ghost agents)')
                    ->query(fn (Builder $query) => $query->whereDoesntHave('user')),
            ])
            ->headerActions([
                Action::make('cleanup_orphans')
                    ->label('Remove ghost agents')
                    ->icon('heroicon-o-trash')
                    ->color('danger')
                    ->visible(fn (): bool => self::can('agents.delete') && Agent::query()->whereDoesntHave('user')->exists())
                    ->requiresConfirmation()
                    ->modalDescription('Deletes every agent whose login account no longer exists. Their referred members are kept.')
                    ->action(function (): void {
                        $count = 0;
                        Agent::query()->whereDoesntHave('user')->get()->each(function (Agent $agent) use (&$count): void {
                            $agent->purge();
                            $count++;
                        });

                        Notification::make()->success()->title("Removed {$count} ghost agent(s).")->send();
                    }),
            ])
            ->recordActions([
                ActionGroup::make([
                    ViewAction::make()
                        ->visible(fn (): bool => self::can('agents.index')),
                    EditAction::make()
                        ->visible(fn (): bool => self::can('agents.edit')),
                    Action::make('activate')
                        ->label('Activate')
                        ->icon('heroicon-s-check-circle')
                        ->color('success')
                        ->visible(fn (Agent $record): bool => self::can('agents.edit') && ! $record->is_active)
                        ->action(fn (Agent $record) => $record->update(['is_active' => true])),
                    Action::make('deactivate')
                        ->label('Deactivate')
                        ->icon('heroicon-s-x-mark')
                        ->color('warning')
                        ->visible(fn (Agent $record): bool => self::can('agents.edit') && $record->is_active)
                        ->action(fn (Agent $record) => $record->update(['is_active' => false])),
                    DeleteAction::make()
                        ->visible(fn (): bool => self::can('agents.delete'))
                        ->modalDescription('This removes the agent, their login, commissions and payout history. Members they referred are kept and simply unlinked.')
                        ->using(fn (Agent $record): bool => $record->purge()),
                ])
                    ->icon('heroicon-m-ellipsis-vertical')
                    ->iconButton(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    BulkAction::make('delete_agents')
                        ->label('Delete selected')
                        ->icon('heroicon-o-trash')
                        ->color('danger')
                        ->visible(fn (): bool => self::can('agents.delete'))
                        ->requiresConfirmation()
                        ->modalDescription('Removes the selected agents and their logins. Referred members are kept.')
                        ->deselectRecordsAfterCompletion()
                        ->action(function (Collection $records): void {
                            $records->each(fn (Agent $agent) => $agent->purge());
                            Notification::make()->success()->title('Deleted '.$records->count().' agent(s).')->send();
                        }),
                ]),
            ]);

        \App\Filament\Support\TableExportHelper::attach($table, 'Agents');

        return $table;
    }
}
