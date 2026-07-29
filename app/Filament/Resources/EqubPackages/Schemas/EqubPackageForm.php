<?php

namespace App\Filament\Resources\EqubPackages\Schemas;

use App\Enums\EqubDurationType;
use App\Enums\EqubPackageType;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Schema;

class EqubPackageForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('name')
                    ->label('Name')
                    ->required()
                    ->maxLength(255),
                Hidden::make('type')
                    ->default(EqubPackageType::Normal->value),
                TextInput::make('contribution_frequency_days')
                    ->label('Contribution Frequency (Days)')
                    ->numeric()
                    ->minValue(1)
                    ->default(1)
                    ->required(),
                Hidden::make('duration_type')
                    ->default(EqubDurationType::PerMember->value),
                Textarea::make('terms_content')
                    ->label('Terms and Conditions')
                    ->rows(4)
                    ->columnSpanFull()
                    ->nullable(),
                Toggle::make('is_active')
                    ->label('Active')
                    ->default(true),
            ]);
    }
}
