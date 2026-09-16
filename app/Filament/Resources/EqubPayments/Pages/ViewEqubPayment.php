<?php

namespace App\Filament\Resources\EqubPayments\Pages;

use App\Filament\Resources\EqubPayments\EqubPaymentResource;
use App\Models\EqubPayment;
use App\Services\Payments\PaymentGatewayManager;
use App\Services\Payments\PaymentSettlementService;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Illuminate\Support\Facades\Auth;

class ViewEqubPayment extends ViewRecord
{
    protected static string $resource = EqubPaymentResource::class;

    protected function getHeaderActions(): array
    {
        return [
            // ASK THE BANK, NOW.
            //
            // The scheduled sweep already does this every few minutes, so this
            // button is not how settlement normally happens. It is for the
            // moment somebody is on the phone to a member who says they paid —
            // where waiting five minutes to be able to answer is the difference
            // between a resolved call and a complaint.
            //
            // It cannot credit anything the bank does not confirm. Same code
            // path, same verification, same refusal to settle on anything short
            // of a PAID from Dashen.
            Action::make('checkWithBank')
                ->label('Check with the bank')
                ->icon('heroicon-o-arrow-path')
                ->color('gray')
                ->visible(fn (EqubPayment $record): bool => Auth::check()
                    && (Auth::user()->hasRole('Super Admin') || Auth::user()->can('equb-payments.edit'))
                    && $record->payment_method?->isGateway()
                    && ! $record->isPaid())
                ->action(function (EqubPayment $record): void {
                    $gateway = app(PaymentGatewayManager::class)
                        ->tryGet($record->payment_method?->value ?? '');

                    if (! $gateway) {
                        Notification::make()
                            ->title('That bank is not configured on this server')
                            ->danger()
                            ->send();

                        return;
                    }

                    // The reference the BANK knows. For a member who settled
                    // several places in one charge that is the batch id, not
                    // this row's own reference — asking with the wrong one
                    // returns "Transaction not found" and means nothing.
                    $reference = $record->batch_reference ?: $record->reference;

                    if (blank($reference)) {
                        Notification::make()
                            ->title('This contribution has no bank reference to ask about')
                            ->danger()
                            ->send();

                        return;
                    }

                    $result = app(PaymentSettlementService::class)->reconcile($gateway, $reference);

                    $record->refresh();

                    if ($result['success'] ?? false) {
                        Notification::make()
                            ->title('Confirmed and credited')
                            ->body($result['message'] ?? '')
                            ->success()
                            ->send();

                        return;
                    }

                    // Not an error. "The bank has not confirmed this" is a real
                    // answer and the operator needs to read it, so it is shown
                    // as a warning rather than dressed up as a failure.
                    Notification::make()
                        ->title('Not confirmed')
                        ->body($result['message'] ?? 'The bank did not confirm this payment.')
                        ->warning()
                        ->send();
                }),

            EditAction::make()
                ->visible(fn (): bool => Auth::check()
                    && (Auth::user()->hasRole('Super Admin') || Auth::user()->can('equb-payments.edit'))),
        ];
    }
}
