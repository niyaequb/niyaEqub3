<?php

namespace App\Filament\Resources\AgentPayouts\Tables;

use App\Enums\CommissionStatus;
use App\Enums\PayoutStatus;
use App\Models\AgentPayout;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Auth;

class AgentPayoutsTable
{
    public static function configure(Table $table): Table
    {
        $table
            ->columns([
                TextColumn::make('id')
                    ->label('ID')
                    ->sortable(),
                TextColumn::make('agent.user.name')
                    ->label('Agent')
                    ->getStateUsing(fn (AgentPayout $record): ?string => $record->agent?->user?->name)
                    ->sortable()
                    ->searchable(),
                TextColumn::make('total_amount')
                    ->label('Total')
                    ->sortable(),
                TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->sortable(),
                TextColumn::make('paid_at')
                    ->label('Paid At')
                    ->dateTime()
                    ->toggleable(),
                TextColumn::make('created_at')
                    ->label('Created At')
                    ->dateTime()
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('agent_id')
                    ->label('Agent')
                    ->relationship('agent', 'referral_code'),
                SelectFilter::make('status')
                    ->label('Status')
                    ->options(collect(PayoutStatus::cases())->mapWithKeys(
                        fn (PayoutStatus $status): array => [$status->value => $status->name]
                    )->toArray()),
            ])
            ->recordActions([
                EditAction::make()
                ->visible(function (AgentPayout $record): bool {
                        return  Auth::check() &&
                         ( Auth::user()->hasRole('Super Admin') || Auth::user()->can('agent-payouts.edit'));
                    })
                ,
                Action::make('mark_paid')
                    ->label('Mark Paid')
                    ->icon('heroicon-s-banknotes')
                    ->color('success')
                    ->visible(function (AgentPayout $record): bool {
                        return  Auth::check() &&
                         ( Auth::user()->hasRole('Super Admin') || Auth::user()->can('agent-payouts.edit')) && $record->status !== PayoutStatus::Paid;
                    })
                    ->action(function (AgentPayout $record): void {
                        $record->update([
                            'status' => PayoutStatus::Paid,
                            'paid_at' => now(),
                        ]);

                        $record->commissions()->update(['status' => CommissionStatus::Paid]);
                    }),
                Action::make('reject')
                    ->label('Reject')
                    ->icon('heroicon-s-x-circle')
                    ->color('danger')
                    ->visible(function (AgentPayout $record): bool {
                        return  Auth::check() &&
                         ( Auth::user()->hasRole('Super Admin') || Auth::user()->can('agent-payouts.edit')) && $record->status !== PayoutStatus::Rejected;
                    })
                    ->action(function (AgentPayout $record): void {
                        $record->update([
                            'status' => PayoutStatus::Rejected,
                            'paid_at' => null,
                        ]);
                    }),
            ])
            ->toolbarActions([]);

        \App\Filament\Support\TableExportHelper::attach($table, 'Agent Payouts');

        return $table;
    }
}
