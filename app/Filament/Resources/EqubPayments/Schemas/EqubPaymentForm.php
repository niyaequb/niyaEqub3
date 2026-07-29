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
                    ->options(collect(EqubPaymentMethod::cases())->mapWithKeys(
                        fn (EqubPaymentMethod $m): array => [$m->value => $m->name]
                    )->toArray())
                    ->default(EqubPaymentMethod::Manual->value)
                    ->required(),
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
