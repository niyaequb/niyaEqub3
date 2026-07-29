<?php

namespace App\Filament\Resources\AgentCommissions\Schemas;

use App\Enums\CommissionStatus;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;

class AgentCommissionForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('agent_id')
                    ->label('Agent ID')
                    ->disabled()
                    ->dehydrated(false),
                TextInput::make('member_id')
                    ->label('Member ID')
                    ->disabled()
                    ->dehydrated(false),
                TextInput::make('source')
                    ->label('Source')
                    ->disabled()
                    ->dehydrated(false),
                TextInput::make('base_amount')
                    ->label('Base Amount')
                    ->disabled()
                    ->dehydrated(false),
                TextInput::make('commission_amount')
                    ->label('Commission Amount')
                    ->disabled()
                    ->dehydrated(false),
                Select::make('status')
                    ->label('Status')
                    ->options(collect(CommissionStatus::cases())->mapWithKeys(
                        fn (CommissionStatus $status): array => [$status->value => $status->name]
                    )->toArray())
                    ->required(),
            ]);
    }
}
