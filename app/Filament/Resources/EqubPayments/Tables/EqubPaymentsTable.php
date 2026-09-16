<?php

namespace App\Filament\Resources\EqubPayments\Tables;

use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use App\Models\EqubPayment;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;

class EqubPaymentsTable
{
    public static function configure(Table $table): Table
    {
        $table
            ->columns([
                TextColumn::make('id')->label('ID')->sortable(),

                // A payment can be against a place held for someone with no
                // Niya account, where there is no member row to read a name
                // from. The name shown is whose place it is; the line beneath
                // says whose money it was, which is what an admin
                // reconciling a receipt actually needs.
                TextColumn::make('membership.member.full_name')
                    ->label('Member')
                    ->sortable()
                    ->state(fn (EqubPayment $record): string => $record->membership?->displayName() ?? '—')
                    ->description(fn (EqubPayment $record): ?string => $record->membership?->isResponsibilitySeat()
                        ? 'Paid by '.($record->membership->sponsor?->full_name ?? 'the sponsor')
                        : null)
                    ->searchable(query: fn (Builder $query, string $search): Builder => $query
                        ->whereHas('membership', fn (Builder $m) => $m
                            ->where('responsibility_name', 'like', "%{$search}%")
                            ->orWhereHas('member', fn (Builder $mm) => $mm
                                ->where('full_name', 'like', "%{$search}%")))),
                TextColumn::make('membership.equbGroup.package.name')->label('Package')->searchable(),
                TextColumn::make('membership.equbGroup.name')->label('Group')->searchable(),
                TextColumn::make('amount')->label('Amount')->money('ETB')->sortable(),
                // "Due Date", not "Payment Date". This is the round the
                // contribution belongs to — it is set when the row is created
                // and is routinely weeks before anyone pays. Labelling it
                // Payment Date is what made this table read "Sep 4" for money
                // that moved on the 16th, and sent somebody looking for a bug
                // that was only ever a word.
                TextColumn::make('payment_date')->label('Due Date')->date()->sortable(),
                TextColumn::make('payment_method')->label('Method')->badge()->sortable(),
                TextColumn::make('status')->label('Status')->badge()->sortable(),

                // WHEN THE MONEY ACTUALLY MOVED, straight from the bank.
                //
                // Empty on a row nobody has confirmed yet, and empty on one an
                // operator ticked by hand — which is the distinction that
                // matters: a date here means the bank said so.
                TextColumn::make('bank_paid_at')
                    ->label('Settled At')
                    ->dateTime('d M Y, H:i')
                    ->sortable()
                    ->placeholder('—')
                    ->description(fn (EqubPayment $record): ?string => $record->bank_amount !== null
                        ? 'ETB '.number_format((float) $record->bank_amount, 2).' confirmed'
                        : null),

                // What the member is holding when they walk in with a receipt.
                // Searchable on purpose: this is the fastest way to answer "I
                // paid, here is my reference, where is it?".
                TextColumn::make('bank_transaction_id')
                    ->label('Bank Transaction')
                    ->searchable()
                    ->copyable()
                    ->placeholder('—')
                    ->description(fn (EqubPayment $record): ?string => $record->bank_reference)
                    ->toggleable(),

                TextColumn::make('bank_payer_account')
                    ->label('Paid From')
                    ->searchable()
                    ->placeholder('—')
                    ->description(fn (EqubPayment $record): ?string => $record->bank_payer_phone)
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('bank_receipt_url')
                    ->label('Receipt')
                    ->formatStateUsing(fn (): string => 'Open')
                    ->url(fn (EqubPayment $record): ?string => $record->bank_receipt_url, shouldOpenInNewTab: true)
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('reference')->label('Our Reference')->searchable()->toggleable(),
                TextColumn::make('batch_reference')
                    ->label('Batch')
                    ->searchable()
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('status')->options([
                    'pending' => 'Pending',
                    'paid' => 'Paid',
                    'failed' => 'Failed',
                ]),
                // Built from the enum rather than written out, so a new bank
                // appears in this filter the moment it is added instead of
                // being invisible to reconciliation until someone notices.
                SelectFilter::make('payment_method')->options(
                    collect(\App\Enums\EqubPaymentMethod::cases())
                        ->mapWithKeys(fn (\App\Enums\EqubPaymentMethod $m): array => [
                            $m->value => $m->label(),
                        ])
                        ->all()
                ),

                // The distinction an auditor asks about first: which paid rows
                // does the BANK vouch for, and which did a person tick?
                // Both are legitimate — an operator reading the merchant portal
                // is how this worked before there was an endpoint to ask — but
                // they are not the same kind of evidence and should never look
                // the same in a list.
                Filter::make('bank_confirmed')
                    ->label('Confirmed by the bank')
                    ->query(fn (Builder $query): Builder => $query->whereNotNull('bank_transaction_id')),

                Filter::make('needs_reconciling')
                    ->label('Paid but not bank-confirmed')
                    ->query(fn (Builder $query): Builder => $query
                        ->where('status', 'paid')
                        ->whereNull('bank_transaction_id')),
            ])
            ->recordActions([
                EditAction::make()
                    ->visible(fn (): bool =>
                        Auth::check() &&
                         ( Auth::user()->hasRole('Super Admin') || Auth::user()->can('equb-payments.edit'))),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make()
                        ->visible(fn (): bool =>
                            Auth::check() &&
                             ( Auth::user()->hasRole('Super Admin') || Auth::user()->can('equb-payments.delete'))),
                ]),
            ]);

        \App\Filament\Support\TableExportHelper::attach($table, 'Equb Payments');

        return $table;
    }
}
