<?php

namespace App\Filament\Resources\EqubPayments\Tables;

use App\Models\EqubPayment;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;

/**
 * The money view.
 *
 * WHAT THIS TABLE IS FOR, AND WHAT IT WAS SHOWING INSTEAD
 *
 * Somebody opens this screen for one of three reasons: a member says they paid
 * and it is not showing, a figure has to be matched against a bank statement,
 * or an operator is working through what still needs confirming.
 *
 * It used to answer none of them. Twelve columns wide, with Package, Group,
 * Due Date and Method occupying the space before the fold, and the bank's own
 * record of the transaction — when the money actually moved, what the bank
 * calls it, which account it came from — pushed off the right-hand edge behind
 * a horizontal scrollbar. The data was there. Nobody could see it.
 *
 * So the order below is deliberate and the hidden columns are deliberate:
 *
 *   Member, Amount, Status, Settled  ... visible, because that is the question
 *   Package, Group, Due Date, Method ... a toggle away, because they are context
 *   references, payer account, receipt ... a toggle away, for the one case each
 *
 * Nothing was removed. Everything that used to be here is still reachable from
 * the column toggle, and still exports.
 *
 * TWO KINDS OF PAID
 *
 * A row the bank vouched for and a row an operator ticked are both legitimately
 * paid, and they are not the same evidence. The Status column says which,
 * underneath the badge, on every row — because the moment those two look
 * identical in a list is the moment reconciliation stops meaning anything.
 */
class EqubPaymentsTable
{
    public static function configure(Table $table): Table
    {
        $table
            ->defaultSort('id', 'desc')
            ->striped()
            ->columns([
                TextColumn::make('id')
                    ->label('#')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),

                // A payment can be against a place held for someone with no
                // Niya account, where there is no member row to read a name
                // from. The name shown is whose place it is; the line beneath
                // says whose money it was, which is what an admin reconciling
                // a receipt actually needs.
                TextColumn::make('membership.member.full_name')
                    ->label('Member')
                    ->sortable()
                    ->state(fn (EqubPayment $record): string => $record->membership?->displayName() ?? '—')
                    ->description(fn (EqubPayment $record): ?string => $record->membership?->isResponsibilitySeat()
                        ? 'Paid by '.($record->membership->sponsor?->full_name ?? 'the sponsor')
                        : $record->membership?->equbGroup?->name)
                    ->searchable(query: fn (Builder $query, string $search): Builder => $query
                        ->whereHas('membership', fn (Builder $m) => $m
                            ->where('responsibility_name', 'like', "%{$search}%")
                            ->orWhereHas('member', fn (Builder $mm) => $mm
                                ->where('full_name', 'like', "%{$search}%")))),

                // Two amounts, shown as one. `amount` is what we asked for;
                // bank_amount is what the bank says it took. They should agree
                // — and on the day they do not, that is the single most
                // important thing on this screen, so it is called out in red
                // rather than left for somebody to notice.
                TextColumn::make('amount')
                    ->label('Amount')
                    ->money('ETB')
                    ->sortable()
                    ->description(function (EqubPayment $record): ?string {
                        if ($record->bank_amount === null) {
                            return null;
                        }

                        return (float) $record->bank_amount === (float) $record->amount
                            ? null
                            : 'Bank took ETB '.number_format((float) $record->bank_amount, 2);
                    })
                    ->color(fn (EqubPayment $record): ?string => $record->bank_amount !== null
                        && (float) $record->bank_amount !== (float) $record->amount
                            ? 'danger'
                            : null),

                TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->sortable()
                    ->description(fn (EqubPayment $record): ?string => match (true) {
                        filled($record->bank_transaction_id) => 'Bank confirmed',
                        $record->isPaid() => 'Marked by hand',
                        default => null,
                    }),

                // WHEN THE MONEY ACTUALLY MOVED, and what the bank calls it.
                //
                // Not payment_date. That is the round this contribution belongs
                // to, set when the row was created, routinely weeks earlier —
                // and showing it under the heading "Payment Date" is what made
                // this table read "Sep 4" for money that moved on the 16th.
                TextColumn::make('bank_paid_at')
                    ->label('Settled')
                    ->dateTime('d M Y, H:i')
                    ->sortable()
                    ->placeholder('—')
                    ->description(fn (EqubPayment $record): ?string => $record->bank_transaction_id),

                TextColumn::make('payment_method')
                    ->label('Method')
                    ->badge()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('payment_date')
                    ->label('Due Date')
                    ->date('d M Y')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('membership.equbGroup.package.name')
                    ->label('Package')
                    ->searchable()
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('membership.equbGroup.name')
                    ->label('Group')
                    ->searchable()
                    ->toggleable(isToggledHiddenByDefault: true),

                // Searchable on purpose. This is the fastest way to answer
                // "I paid, here is my reference, where is it?" — the member is
                // holding one of these, not our order id.
                TextColumn::make('bank_reference')
                    ->label('Bank Ref')
                    ->searchable()
                    ->copyable()
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),

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

                TextColumn::make('reference')
                    ->label('Our Reference')
                    ->searchable()
                    ->copyable()
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('batch_reference')
                    ->label('Batch')
                    ->searchable()
                    ->copyable()
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

                // The distinction an auditor asks about first.
                Filter::make('bank_confirmed')
                    ->label('Confirmed by the bank')
                    ->query(fn (Builder $query): Builder => $query->whereNotNull('bank_transaction_id')),

                // The operator's working queue: money we have credited on
                // somebody's word rather than the bank's. Should trend to
                // empty now that settlement asks.
                Filter::make('needs_reconciling')
                    ->label('Paid but not bank-confirmed')
                    ->query(fn (Builder $query): Builder => $query
                        ->where('status', 'paid')
                        ->whereNull('bank_transaction_id')),
            ])
            ->recordActions([
                EditAction::make()
                    ->visible(fn (): bool => Auth::check()
                        && (Auth::user()->hasRole('Super Admin') || Auth::user()->can('equb-payments.edit'))),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make()
                        ->visible(fn (): bool => Auth::check()
                            && (Auth::user()->hasRole('Super Admin') || Auth::user()->can('equb-payments.delete'))),
                ]),
            ]);

        \App\Filament\Support\TableExportHelper::attach($table, 'Equb Payments');

        return $table;
    }
}
