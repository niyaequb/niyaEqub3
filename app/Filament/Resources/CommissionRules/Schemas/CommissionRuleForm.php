<?php

namespace App\Filament\Resources\CommissionRules\Schemas;

use App\Enums\CommissionTrigger;
use App\Enums\CommissionType;
use App\Models\Agent;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Schema;

class CommissionRuleForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('name')
                    ->label('Name')
                    ->required()
                    ->maxLength(255),
                Select::make('trigger')
                    ->label('Trigger')
                    ->options(collect(CommissionTrigger::cases())->mapWithKeys(
                        fn (CommissionTrigger $trigger): array => [$trigger->value => $trigger->name]
                    )->toArray())
                    ->required(),
                Select::make('commission_type')
                    ->label('Commission Type')
                    ->options(collect(CommissionType::cases())->mapWithKeys(
                        fn (CommissionType $type): array => [$type->value => $type->name]
                    )->toArray())
                    ->required(),
                TextInput::make('commission_value')
                    ->label('Commission Value')
                    ->numeric()
                    ->required(),
                Select::make('agent_id')
                    ->label('Agent Override')
                    ->relationship(
                        name: 'agent',
                        titleAttribute: 'referral_code'
                    )
                    ->getOptionLabelFromRecordUsing(
                        fn (Agent $record) => $record->user?->name.' ('.$record->referral_code.')'
                    )
                    ->searchable()
                    ->preload()
                    ->nullable()
                    ->helperText('Leave empty for global rules.'),
                Toggle::make('is_active')
                    ->label('Active')
                    ->default(true),
            ]);
    }
}
