<?php

namespace App\Filament\Resources\AgentPaymentRequests\Schemas;

use App\Models\AgentPaymentRequest;
use Filament\Infolists\Components\TextEntry;
// Filament v4: Section lives in Schemas, not Infolists. The old
// Filament\Infolists\Components\Section import made this page return 500.
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class AgentPaymentRequestInfolist
{
    public static function configure(Schema $schema): Schema
    {
        $status = fn ($state): string => $state instanceof \BackedEnum ? $state->value : (string) $state;

        return $schema
            ->components([
                Section::make('Payment details')
                    ->schema([
                        TextEntry::make('reference')
                            ->label('Reference')
                            ->copyable()
                            ->weight('bold')
                            ->placeholder('-'),
                        TextEntry::make('status')
                            ->label('Status')
                            ->badge()
                            ->formatStateUsing(fn ($state, AgentPaymentRequest $record): string => $record->cancelled_at
                                ? 'Cancelled by agent'
                                : ucfirst($status($state)))
                            ->color(fn ($state, AgentPaymentRequest $record): string => $record->cancelled_at
                                ? 'gray'
                                : match ($status($state)) {
                                    'completed' => 'success',
                                    'pending' => 'warning',
                                    'failed' => 'danger',
                                    default => 'gray',
                                }),
                        TextEntry::make('agent.user.name')
                            ->label('Agent')
                            ->placeholder('-'),
                        TextEntry::make('agent.user.phone')
                            ->label('Agent phone')
                            ->placeholder('-'),
                        TextEntry::make('agent.referral_code')
                            ->label('Referral Code')
                            ->placeholder('-'),
                        TextEntry::make('amount')
                            ->label('Amount')
                            ->numeric(decimalPlaces: 2)
                            ->suffix(' ETB')
                            ->weight('bold'),
                        TextEntry::make('created_at')
                            ->label('Requested')
                            ->dateTime(),
                        TextEntry::make('paid_at')
                            ->label('Paid At')
                            ->dateTime()
                            ->placeholder('-'),
                        TextEntry::make('cancelled_at')
                            ->label('Cancelled At')
                            ->dateTime()
                            ->placeholder('-'),
                        TextEntry::make('processedBy.name')
                            ->label('Processed by')
                            ->placeholder('-'),
                    ])
                    ->columns(2),
                Section::make('Bank information')
                    ->schema([
                        TextEntry::make('bank_name')
                            ->label('Bank Name')
                            ->placeholder('-'),
                        TextEntry::make('account_number')
                            ->label('Account Number')
                            ->copyable()
                            ->placeholder('-'),
                        TextEntry::make('account_holder_name')
                            ->label('Account Holder Name')
                            ->placeholder('-'),
                        TextEntry::make('transaction_reference')
                            ->label('Bank transfer reference')
                            ->copyable()
                            ->placeholder('-'),
                    ])
                    ->columns(2),
                Section::make('Notes')
                    ->schema([
                        TextEntry::make('note')
                            ->label('Note from the agent')
                            ->placeholder('-'),
                        TextEntry::make('admin_note')
                            ->label('Admin note / reason')
                            ->placeholder('-'),
                    ])
                    ->columns(2)
                    ->collapsible(),
            ]);
    }
}
