<?php

namespace App\Filament\Resources\AgentPaymentRequests\Schemas;

use Filament\Infolists\Components\Section;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Schema;

class AgentPaymentRequestInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Payment details')
                    ->schema([
                        TextEntry::make('agent.user.name')
                            ->label('Agent')
                            ->placeholder('-'),
                        TextEntry::make('agent.referral_code')
                            ->label('Referral Code')
                            ->placeholder('-'),
                        TextEntry::make('amount')
                            ->label('Amount')
                            ->numeric(decimalPlaces: 2),
                        TextEntry::make('status')
                            ->label('Status')
                            ->badge(),
                        TextEntry::make('paid_at')
                            ->label('Paid At')
                            ->dateTime()
                            ->placeholder('-'),
                        TextEntry::make('created_at')
                            ->label('Created')
                            ->dateTime(),
                    ])
                    ->columns(2),
                Section::make('Bank information')
                    ->schema([
                        TextEntry::make('bank_name')
                            ->label('Bank Name')
                            ->placeholder('-'),
                        TextEntry::make('account_number')
                            ->label('Account Number')
                            ->placeholder('-'),
                        TextEntry::make('account_holder_name')
                            ->label('Account Holder Name')
                            ->placeholder('-'),
                    ])
                    ->columns(2)
                    ->collapsible(),
            ]);
    }
}
