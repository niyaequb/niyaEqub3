<?php

namespace App\Filament\Resources\AgentCommissions\Tables;

use App\Enums\CommissionStatus;
use App\Enums\CommissionTrigger;
use App\Models\AgentCommission;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Auth;

class AgentCommissionsTable
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
                    ->getStateUsing(fn (AgentCommission $record): ?string => $record->agent?->user?->name)
                    ->searchable()
                    ->sortable(),
                TextColumn::make('member.full_name')
                    ->label('Member')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('source')
                    ->label('Source')
                    ->badge()
                    ->sortable(),
                TextColumn::make('base_amount')
                    ->label('Base Amount')
                    ->sortable(),
                TextColumn::make('commission_amount')
                    ->label('Commission Amount')
                    ->sortable(),
                TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->sortable(),
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
                    ->options(collect(CommissionStatus::cases())->mapWithKeys(
                        fn (CommissionStatus $status): array => [$status->value => $status->name]
                    )->toArray()),
                SelectFilter::make('source')
                    ->label('Source')
                    ->options(collect(CommissionTrigger::cases())->mapWithKeys(
                        fn (CommissionTrigger $trigger): array => [$trigger->value => $trigger->name]
                    )->toArray()),
            ])
            ->recordActions([
                EditAction::make()
                ->visible(fn (): bool =>
                        Auth::check() &&
                         ( Auth::user()->hasRole('Super Admin') || Auth::user()->can('agent-commissions.edit'))),
                Action::make('approve')
                    ->label('Approve')
                    ->icon('heroicon-s-check-circle')
                    ->color('success')
                    ->visible(function (AgentCommission $record): bool {
                        return  Auth::check() &&
                         ( Auth::user()->hasRole('Super Admin') || Auth::user()->can('agent-commissions.edit')) && $record->status === CommissionStatus::Pending;
                    })
                    ->action(fn (AgentCommission $record) => $record->update(['status' => CommissionStatus::Approved])),
                Action::make('mark_paid')
                    ->label('Mark Paid')
                    ->icon('heroicon-s-banknotes')
                    ->color('success')
                    ->visible(function (AgentCommission $record): bool {
                        return  Auth::check() &&
                         ( Auth::user()->hasRole('Super Admin') || Auth::user()->can('agent-commissions.edit')) && $record->status !== CommissionStatus::Paid;
                    })

                    ->action(fn (AgentCommission $record) => $record->update(['status' => CommissionStatus::Paid])),
            ])
            ->toolbarActions([]);

        \App\Filament\Support\TableExportHelper::attach($table, 'Agent Commissions');

        return $table;
    }
}
