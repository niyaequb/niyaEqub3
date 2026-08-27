<?php

namespace App\Filament\Resources\EqubPayments\Schemas;

use App\Enums\EqubPaymentMethod;
use App\Enums\EqubPaymentStatus;
use App\Models\EqubMembership;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;

class EqubPaymentForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Select::make('equb_membership_id')
                    ->label('Equb Membership')
                    ->relationship('membership', 'id')
                    ->getOptionLabelFromRecordUsing(fn (EqubMembership $r) => 'Membership #'.$r->id.' - '.($r->member?->full_name ?? '').' ('.($r->equbGroup?->package?->name ?? '').')')
                    ->required()
                    ->searchable()
                    ->preload(),
                TextInput::make('amount')
                    ->label('Amount')
                    ->numeric()
                    ->minValue(0)
                    ->required(),
                DateTimePicker::make('payment_date')
                    ->label('Payment Date')
                    ->default(now())
                    ->required(),
                Select::make('payment_method')
                    ->label('Payment Method')
                    // Only banks, and only ones that can currently take money.
                    // Offline and manual were withdrawn; their enum cases
                    // survive so historical rows still read, but they are not
                    // offered here.
                    ->options(collect(EqubPaymentMethod::selectable())->mapWithKeys(
                        fn (EqubPaymentMethod $m): array => [$m->value => $m->label()]
                    )->toArray())
                    ->default(EqubPaymentMethod::Dashen->value)
                    ->required()
                    // Creating a row here does NOT take any money. It records a
                    // contribution the member still has to authorise in the
                    // bank's app.
                    ->helperText('Records a pending contribution. No money moves until the member authorises it in the bank app and settlement is confirmed.'),
                Select::make('status')
                    ->label('Status')
                    ->options(collect(EqubPaymentStatus::cases())->mapWithKeys(
                        fn (EqubPaymentStatus $s): array => [$s->value => $s->name]
                    )->toArray())
                    ->default(EqubPaymentStatus::Pending->value)
                    ->required(),
            ]);
    }
}
