<?php

namespace App\Filament\Resources\AgentPaymentRequests\Tables;

use App\Enums\PaymentStatus;
use App\Models\AgentPaymentRequest;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Auth;

class AgentPaymentRequestsTable
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
                    ->getStateUsing(fn (AgentPaymentRequest $record): ?string => $record->agent?->user?->name)
                    ->sortable()
                    ->searchable(),
                TextColumn::make('agent.referral_code')
                    ->label('Referral Code')
                    ->sortable()
                    ->toggleable(),
                TextColumn::make('amount')
                    ->label('Amount')
                    ->numeric(decimalPlaces: 2)
                    ->sortable(),
                TextColumn::make('bank_name')
                    ->label('Bank')
                    ->placeholder('-')
                    ->searchable()
                    ->toggleable(),
                TextColumn::make('account_holder_name')
                    ->label('Account Holder')
                    ->placeholder('-')
                    ->searchable()
                    ->toggleable(),
                TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->sortable(),
                TextColumn::make('paid_at')
                    ->label('Paid At')
                    ->dateTime()
                    ->placeholder('-')
                    ->sortable()
                    ->toggleable(),
                TextColumn::make('created_at')
                    ->label('Created')
                    ->dateTime()
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('agent_id')
                    ->label('Agent')
                    ->relationship('agent', 'referral_code'),
                SelectFilter::make('status')
                    ->label('Status')
                    ->options(collect(PaymentStatus::cases())->mapWithKeys(
                        fn (PaymentStatus $status): array => [$status->value => $status->name]
                    )->toArray()),
            ])
            ->recordActions([
                ViewAction::make(),
                EditAction::make(),
                Action::make('mark_paid')
                    ->label('Mark Paid')
                    ->icon('heroicon-s-banknotes')
                    ->color('success')
                    ->visible(function (AgentPaymentRequest $record): bool {
                        return  Auth::check() &&
                         ( Auth::user()->hasRole('Super Admin') || Auth::user()->can('agent-payment-requests.edit')) && $record->status !== PaymentStatus::Completed;
                    })
                    ->action(function (AgentPaymentRequest $record): void {
                        $record->update([
                            'status' => PaymentStatus::Completed,
                            'paid_at' => now(),
                        ]);
                    }),
                Action::make('mark_failed')
                    ->label('Mark Failed')
                    ->icon('heroicon-s-x-circle')
                    ->color('danger')
                    ->visible(function (AgentPaymentRequest $record): bool {
                        return  Auth::check() &&
                         ( Auth::user()->hasRole('Super Admin') || Auth::user()->can('agent-payment-requests.edit')) && $record->status !== PaymentStatus::Failed;
                    })
                    ->action(function (AgentPaymentRequest $record): void {
                        $record->update([
                            'status' => PaymentStatus::Failed,
                            'paid_at' => null,
                        ]);
                    }),
            ])
            ->defaultSort('created_at', 'desc');

        \App\Filament\Support\TableExportHelper::attach($table, 'Agent Payment Requests');

        return $table;
    }
}
