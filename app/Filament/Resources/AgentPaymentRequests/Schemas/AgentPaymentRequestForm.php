<?php

namespace App\Filament\Resources\AgentPaymentRequests\Schemas;

use App\Enums\PaymentStatus;
use App\Models\Agent;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section as ComponentsSection;
use Filament\Schemas\Schema;

class AgentPaymentRequestForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                ComponentsSection::make('Payment details')
                    ->schema([
                        Select::make('agent_id')
                            ->label('Agent')
                            ->relationship(
                                name: 'agent',
                                titleAttribute: 'referral_code'
                            )
                            ->getOptionLabelFromRecordUsing(
                                fn (Agent $record) => $record->user?->name.' ('.$record->referral_code.')'
                            )
                            ->searchable()
                            ->preload()
                            ->disabled(fn ($context): bool => $context === 'edit')
                            ->required(),
                        TextInput::make('amount')
                            ->label('Amount')
                            ->numeric()
                            ->minValue(0)
                            ->step(0.01)
                            ->required(),
                        Select::make('status')
                            ->label('Status')
                            ->options(collect(PaymentStatus::cases())->mapWithKeys(
                                fn (PaymentStatus $status): array => [$status->value => $status->name]
                            )->toArray())
                            ->required(),
                        DateTimePicker::make('paid_at')
                            ->label('Paid At')
                            ->nullable(),
                    ])
                    ->columns(2),
                ComponentsSection::make('Bank information')
                    ->schema([
                        TextInput::make('bank_name')
                            ->label('Bank Name')
                            ->maxLength(255),
                        TextInput::make('account_number')
                            ->label('Account Number')
                            ->maxLength(50),
                        TextInput::make('account_holder_name')
                            ->label('Account Holder Name')
                            ->maxLength(255),
                    ])
                    ->columns(2)
                    ->collapsible(),
            ]);
    }
}
