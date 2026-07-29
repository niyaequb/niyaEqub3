<?php

namespace App\Filament\Resources\AgentPayouts\Schemas;

use App\Enums\PayoutStatus;
use App\Models\Agent;
use App\Models\AgentCommission;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;

class AgentPayoutForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
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
                Select::make('commission_ids')
                    ->label('Commissions')
                    ->multiple()
                    ->searchable()
                    ->live()
                    ->required(fn ($context): bool => $context === 'create')
                    ->options(function (Get $get): array {
                        $agentId = $get('agent_id');

                        if (! $agentId) {
                            return [];
                        }

                        return AgentCommission::query()
                            ->where('agent_id', $agentId)
                            ->whereIn('status', ['pending', 'approved'])
                            ->whereDoesntHave('payoutItems')
                            ->orderByDesc('created_at')
                            ->get()
                            ->mapWithKeys(function (AgentCommission $commission): array {
                                $label = sprintf(
                                    '#%s • %s • %s',
                                    $commission->id,
                                    $commission->source?->value ?? '',
                                    $commission->commission_amount
                                );

                                return [$commission->id => $label];
                            })
                            ->toArray();
                    })
                    ->afterStateUpdated(function (?array $state, Set $set): void {
                        $ids = $state ?? [];
                        $sum = $ids === []
                            ? 0
                            : (float) AgentCommission::query()
                                ->whereIn('id', $ids)
                                ->sum('commission_amount');
                        $set('total_amount', (string) $sum);
                    })
                    ->helperText('Select unpaid commissions to include in this payout.')
                    ->visible(fn ($context) => $context === 'create'),
                TextInput::make('total_amount')
                    ->label('Total Amount')
                    ->numeric()
                    ->default(0)
                    ->minValue(0)
                    ->step(0.01)
                    ->helperText('Pre-filled from selected commissions. You can edit if needed.')
                    ->required(),
                Select::make('status')
                    ->label('Status')
                    ->options(collect(PayoutStatus::cases())->mapWithKeys(
                        fn (PayoutStatus $status): array => [$status->value => $status->name]
                    )->toArray())
                    ->default(PayoutStatus::Pending->value)
                    ->required(),
                DateTimePicker::make('paid_at')
                    ->label('Paid At')
                    ->nullable(),
                Textarea::make('note')
                    ->label('Note')
                    ->rows(3)
                    ->nullable(),
            ]);
    }
}
