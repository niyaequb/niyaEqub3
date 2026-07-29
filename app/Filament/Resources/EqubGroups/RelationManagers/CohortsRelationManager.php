<?php

namespace App\Filament\Resources\EqubGroups\RelationManagers;

use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class CohortsRelationManager extends RelationManager
{
    protected static string $relationship = 'cohorts';

    protected static ?string $title = 'Cohorts';

    public function isReadOnly(): bool
    {
        return false;
    }

    public function form(Schema $form): Schema
    {
        return $form
            ->schema([
                TextInput::make('name')
                    ->required()
                    ->maxLength(255),
                TextInput::make('month')
                    ->numeric()
                    ->required()
                    ->minValue(1)
                    ->maxValue(12),
                TextInput::make('year')
                    ->numeric()
                    ->required(),
                TextInput::make('win_weight')
                    ->label('Win Priority Weight')
                    ->numeric()
                    ->default(1.00)
                    ->step(0.01)
                    ->required()
                    ->helperText('Higher weight means higher priority in draws.'),
                Toggle::make('is_active')
                    ->default(true)
                    ->required(),
            ]);
    }

    public function table(Table $table): Table
    {
        $table
            ->columns([
                TextColumn::make('name')->searchable(),
                TextColumn::make('month'),
                TextColumn::make('year'),
                TextColumn::make('win_weight')->label('Weight')->sortable(),
                IconColumn::make('is_active')->boolean(),
            ])
            ->toolbarActions([
                CreateAction::make(),
            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make(),
            ]);

        \App\Filament\Support\TableExportHelper::attach($table, 'Cohorts');

        return $table;
    }
}
