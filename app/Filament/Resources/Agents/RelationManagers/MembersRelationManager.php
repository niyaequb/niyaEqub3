<?php

namespace App\Filament\Resources\Agents\RelationManagers;

use Filament\Actions\ViewAction;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class MembersRelationManager extends RelationManager
{
    protected static string $relationship = 'members';

    public function table(Table $table): Table
    {
        $table
            ->columns([
                TextColumn::make('full_name')
                    ->label('Member')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('user.phone')
                    ->label('Phone')
                    ->searchable(),
                TextColumn::make('registered_via')
                    ->label('Registered Via')
                    ->badge(),
                TextColumn::make('registered_at')
                    ->label('Registered At')
                    ->dateTime()
                    ->sortable(),
            ])
            ->headerActions([])
            ->actions([
                ViewAction::make(),
            ]);

        \App\Filament\Support\TableExportHelper::attach($table, 'Members');

        return $table;
    }
}
