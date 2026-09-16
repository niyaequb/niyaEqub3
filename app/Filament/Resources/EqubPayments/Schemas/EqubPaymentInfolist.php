<?php

namespace App\Filament\Resources\EqubPayments\Schemas;

use App\Models\EqubPayment;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

/**
 * One contribution, in full.
 *
 * WHO OPENS THIS AND WHY
 *
 * Three people, with three different questions, and the page is ordered by how
 * often each is asked:
 *
 *   A member says "I paid and it is not showing."  -> Bank transaction
 *   Finance is matching a statement line.          -> Bank transaction
 *   Someone asks "what does this member still owe?" -> Membership position
 *
 * THE THREE SOURCES ARE KEPT APART ON PURPOSE
 *
 * What we asked for, what the bank says happened, and what the membership now
 * stands at are three different kinds of fact, and mixing them is how a screen
 * starts lying quietly. The amount we billed sits in one section; the amount
 * the bank actually took sits in another; if they ever disagree, the page shows
 * both rather than picking one.
 *
 * WHAT IS DELIBERATELY BLANK
 *
 * The payer's name. Dashen's `check-status` publishes the phone number and the
 * debit account but not the name, even though their own receipt screen shows
 * it. It would be easy to fill that gap from our own membership record and it
 * would be wrong: the entire point of this section is that it says what the
 * BANK says. A name copied from our side would look identical to a verified one
 * and could not be told apart later.
 */
class EqubPaymentInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([

            // ---------------------------------------------------------------
            // What we billed
            // ---------------------------------------------------------------
            Section::make('Contribution')
                ->description('What this row asked the member for.')
                ->columns(3)
                ->schema([
                    TextEntry::make('amount')
                        ->label('Amount due')
                        ->money('ETB')
                        ->weight('bold'),

                    TextEntry::make('status')
                        ->label('Status')
                        ->badge()
                        ->helperText(fn (EqubPayment $record): ?string => match (true) {
                            filled($record->bank_transaction_id) => 'Confirmed by the bank',
                            $record->isPaid() => 'Marked paid by an operator, not bank-confirmed',
                            default => null,
                        }),

                    TextEntry::make('payment_method')
                        ->label('Method')
                        ->badge(),

                    TextEntry::make('payment_date')
                        ->label('Due date')
                        ->date('d M Y')
                        ->helperText('The round this contribution belongs to'),

                    TextEntry::make('created_at')
                        ->label('Started')
                        ->dateTime('d M Y, H:i')
                        ->helperText('When the member tapped Pay'),

                    TextEntry::make('updated_at')
                        ->label('Last changed')
                        ->dateTime('d M Y, H:i'),

                    TextEntry::make('reference')
                        ->label('Our reference')
                        ->copyable()
                        ->helperText('The merchant order id sent to the bank'),

                    TextEntry::make('batch_reference')
                        ->label('Batch reference')
                        ->copyable()
                        ->placeholder('—')
                        ->helperText('Shared when several places were paid in one charge'),

                    TextEntry::make('id')
                        ->label('Payment #'),
                ]),

            // ---------------------------------------------------------------
            // What the bank says happened
            // ---------------------------------------------------------------
            Section::make('Bank transaction')
                ->description('Straight from the bank. Nothing here is written by us.')
                ->columns(3)
                ->visible(fn (EqubPayment $record): bool => filled($record->bank_transaction_id))
                ->schema([
                    TextEntry::make('bank_paid_at')
                        ->label('Settled at')
                        ->dateTime('d M Y, H:i:s')
                        ->weight('bold'),

                    TextEntry::make('bank_amount')
                        ->label('Amount taken')
                        ->money('ETB')
                        ->weight('bold')
                        // The one comparison on this page that matters. If the
                        // bank took a different figure from the one we billed,
                        // everything else is noise until that is explained.
                        ->color(fn (EqubPayment $record): ?string => $record->bank_amount !== null
                            && (float) $record->bank_amount !== (float) $record->amount
                                ? 'danger'
                                : 'success')
                        ->helperText(fn (EqubPayment $record): ?string => $record->bank_amount !== null
                            && (float) $record->bank_amount !== (float) $record->amount
                                ? 'Does not match the amount due — investigate before crediting anything else'
                                : null),

                    TextEntry::make('bank_payer_name')
                        ->label('Payer name')
                        ->placeholder('Not published by the bank')
                        ->helperText('Dashen return the account and phone, not the name'),

                    TextEntry::make('bank_payer_account')
                        ->label('Paid from account')
                        ->copyable()
                        ->placeholder('—'),

                    TextEntry::make('bank_payer_phone')
                        ->label('Payer phone')
                        ->copyable()
                        ->placeholder('—'),

                    TextEntry::make('bank_payload.data.credit_account')
                        ->label('Settled into')
                        ->copyable()
                        ->placeholder('—')
                        ->helperText('Niya settlement account'),

                    TextEntry::make('bank_transaction_id')
                        ->label('Transaction id')
                        ->copyable()
                        ->helperText('What the member is holding on their receipt'),

                    TextEntry::make('bank_reference')
                        ->label('FT reference')
                        ->copyable()
                        ->placeholder('—'),

                    TextEntry::make('bank_receipt_url')
                        ->label('Receipt')
                        ->placeholder('—')
                        ->formatStateUsing(fn (): string => 'Open the bank receipt')
                        ->url(fn (EqubPayment $record): ?string => $record->bank_receipt_url, shouldOpenInNewTab: true),
                ]),

            // Shown INSTEAD of the section above, so the page never just ends
            // silently on a row nobody has confirmed. Somebody arriving here
            // because a member is asking about their money should be told what
            // state it is in and what happens next.
            Section::make('Not yet confirmed by the bank')
                ->visible(fn (EqubPayment $record): bool => blank($record->bank_transaction_id))
                ->schema([
                    TextEntry::make('bank_status_explanation')
                        ->hiddenLabel()
                        ->state(fn (EqubPayment $record): string => match (true) {
                            $record->isPaid() => 'This contribution was marked paid by an operator rather than '
                                .'confirmed with the bank. There is no transaction record attached to it.',
                            $record->status->value === 'failed' => 'The bank has no completed transaction for this '
                                .'order. Either the member never finished it, or the charge was refused.',
                            default => 'Waiting on the bank. Settlement asks Dashen about every pending '
                                .'contribution every few minutes; this row will fill in on its own once the '
                                .'money is confirmed.',
                        }),
                ]),

            // ---------------------------------------------------------------
            // Where this leaves the member
            // ---------------------------------------------------------------
            Section::make('Membership position')
                ->description('The package this contribution belongs to, and what is still owed on it.')
                ->columns(3)
                ->schema([
                    TextEntry::make('membership.displayName')
                        ->label('Member')
                        ->state(fn (EqubPayment $record): string => $record->membership?->displayName() ?? '—')
                        ->helperText(fn (EqubPayment $record): ?string => $record->membership?->isResponsibilitySeat()
                            ? 'A place held for someone without a Niya account, paid by '
                                .($record->membership->sponsor?->full_name ?? 'their sponsor')
                            : null),

                    TextEntry::make('membership.equbGroup.name')
                        ->label('Equb')
                        ->placeholder('—'),

                    TextEntry::make('membership.equbGroup.package.name')
                        ->label('Package')
                        ->placeholder('—'),

                    TextEntry::make('contribution_per_round')
                        ->label('Per round')
                        ->state(fn (EqubPayment $record): ?float => $record->membership
                            ? (float) $record->membership->contribution_amount
                            : null)
                        ->money('ETB')
                        ->placeholder('—'),

                    TextEntry::make('total_paid')
                        ->label('Paid so far')
                        ->state(fn (EqubPayment $record): ?float => $record->membership?->total_paid)
                        ->money('ETB')
                        ->color('success')
                        ->placeholder('—')
                        ->helperText('Confirmed contributions on this membership'),

                    TextEntry::make('remaining_amount')
                        ->label('Outstanding')
                        ->state(fn (EqubPayment $record): ?float => $record->membership?->remaining_amount)
                        ->money('ETB')
                        ->weight('bold')
                        ->color(fn (EqubPayment $record): string => ($record->membership?->remaining_amount ?? 0) > 0
                            ? 'warning'
                            : 'success')
                        ->placeholder('—')
                        ->helperText(fn (EqubPayment $record): ?string => $record->membership
                            ? 'Of '.number_format($record->membership->expected_total_amount, 2).' ETB expected in total'
                            : null),

                    TextEntry::make('rounds_paid')
                        ->label('Rounds paid')
                        ->state(function (EqubPayment $record): ?string {
                            $membership = $record->membership;

                            if (! $membership) {
                                return null;
                            }

                            $paid = $membership->payments()
                                ->where('status', \App\Enums\EqubPaymentStatus::Paid)
                                ->count();

                            $schedule = $membership->payment_schedule;

                            return $schedule === []
                                ? (string) $paid
                                : $paid.' of '.count($schedule);
                        })
                        ->placeholder('—'),

                    TextEntry::make('membership.status')
                        ->label('Membership')
                        ->badge()
                        ->placeholder('—'),

                    TextEntry::make('membership.join_date')
                        ->label('Joined')
                        ->date('d M Y')
                        ->placeholder('—'),
                ]),
        ]);
    }
}
