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
                TextColumn::make('reference')
                    ->label('Reference')
                    ->copyable()
                    ->searchable()
                    ->placeholder('-')
                    ->weight('bold'),
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
                    ->suffix(' ETB')
                    ->weight('bold')
                    ->sortable(),
                TextColumn::make('account_number')
                    ->label('Account No.')
                    ->placeholder('-')
                    ->copyable()
                    ->toggleable(),
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
                    ->formatStateUsing(fn ($state, AgentPaymentRequest $record): string => $record->cancelled_at
                        ? 'Cancelled'
                        : ucfirst($state instanceof \BackedEnum ? $state->value : (string) $state))
                    ->color(fn ($state, AgentPaymentRequest $record): string => $record->cancelled_at
                        ? 'gray'
                        : match ($state instanceof \BackedEnum ? $state->value : (string) $state) {
                            'completed' => 'success',
                            'pending' => 'warning',
                            'failed' => 'danger',
                            default => 'gray',
                        })
                    ->sortable(),
                TextColumn::make('note')
                    ->label('Agent note')
                    ->limit(30)
                    ->placeholder('-')
                    ->toggleable(),
                TextColumn::make('transaction_reference')
                    ->label('Transfer ref.')
                    ->copyable()
                    ->placeholder('-')
                    ->toggleable(),
                TextColumn::make('admin_note')
                    ->label('Admin note')
                    ->limit(30)
                    ->placeholder('-')
                    ->toggleable(isToggledHiddenByDefault: true),
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
                    ->modalHeading(fn (AgentPaymentRequest $record): string => 'Pay '.($record->reference ?? '#'.$record->id))
                    ->modalDescription(fn (AgentPaymentRequest $record): string => sprintf(
                        'Confirm you sent ETB %s to %s (%s, %s).',
                        number_format((float) $record->amount, 2),
                        $record->account_holder_name ?: '—',
                        $record->bank_name ?: '—',
                        $record->account_number ?: '—'
                    ))
                    ->schema([
                        \Filament\Forms\Components\TextInput::make('transaction_reference')
                            ->label('Bank transfer reference')
                            ->placeholder('e.g. FT26301XXXXX')
                            ->maxLength(255),
                        \Filament\Forms\Components\Textarea::make('admin_note')
                            ->label('Note to the agent (optional)')
                            ->rows(2)
                            ->maxLength(500),
                    ])
                    ->action(function (AgentPaymentRequest $record, array $data): void {
                        $record->update([
                            'status' => PaymentStatus::Completed,
                            'paid_at' => now(),
                            'cancelled_at' => null,
                            'transaction_reference' => $data['transaction_reference'] ?? null,
                            'admin_note' => $data['admin_note'] ?? $record->admin_note,
                            'processed_by' => Auth::id(),
                        ]);
                        \Filament\Notifications\Notification::make()->success()
                            ->title('Marked as paid.')->send();
                    }),
                Action::make('mark_failed')
                    ->label('Mark Failed')
                    ->icon('heroicon-s-x-circle')
                    ->color('danger')
                    ->visible(function (AgentPaymentRequest $record): bool {
                        return  Auth::check() &&
                         ( Auth::user()->hasRole('Super Admin') || Auth::user()->can('agent-payment-requests.edit')) && $record->status !== PaymentStatus::Failed;
                    })
                    ->modalHeading('Reject this withdrawal')
                    ->modalDescription('The amount goes back to the agent\'s available balance. The agent sees your reason in the app.')
                    ->schema([
                        \Filament\Forms\Components\Textarea::make('admin_note')
                            ->label('Reason')
                            ->required()
                            ->rows(3)
                            ->maxLength(500),
                    ])
                    ->action(function (AgentPaymentRequest $record, array $data): void {
                        $record->update([
                            'status' => PaymentStatus::Failed,
                            'paid_at' => null,
                            'admin_note' => $data['admin_note'],
                            'processed_by' => Auth::id(),
                        ]);
                    }),
            ])
            ->defaultSort('created_at', 'desc');

        \App\Filament\Support\TableExportHelper::attach($table, 'Agent Payment Requests');

        return $table;
    }
}
