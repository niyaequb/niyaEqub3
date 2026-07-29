<?php

namespace App\Filament\Resources\Agents\RelationManagers;

use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class CommissionsRelationManager extends RelationManager
{
    protected static string $relationship = 'commissions';

    public function table(Table $table): Table
    {
        $table
            ->columns([
                TextColumn::make('member.full_name')
                    ->label('Member')
                    ->searchable(),
                TextColumn::make('source')
                    ->label('Source')
                    ->badge(),
                TextColumn::make('commission_amount')
                    ->label('Commission')
                    ->sortable(),
                TextColumn::make('status')
                    ->label('Status')
                    ->badge(),
                TextColumn::make('created_at')
                    ->label('Created At')
                    ->dateTime()
                    ->sortable(),
            ])
            ->headerActions([])
            ->actions([]);

        \App\Filament\Support\TableExportHelper::attach($table, 'Commissions');

        return $table;
    }
}
