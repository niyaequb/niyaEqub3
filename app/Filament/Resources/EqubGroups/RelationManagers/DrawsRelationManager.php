<?php

namespace App\Filament\Resources\EqubGroups\RelationManagers;

use Filament\Actions\DeleteAction;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class DrawsRelationManager extends RelationManager
{
    protected static string $relationship = 'draws';

    protected static ?string $title = 'Draws';

    public function table(Table $table): Table
    {
        $table
            ->columns([
                TextColumn::make('draw_date')->label('Draw Date')->dateTime()->sortable(),
                TextColumn::make('winnerMembership.member.full_name')->label('Winner')->searchable(),
                TextColumn::make('executedBy.name')->label('Executed By')->placeholder('System')->toggleable(),
            ])
            ->defaultSort('draw_date', 'desc')
            ->recordActions([
                DeleteAction::make(),
            ]);

        \App\Filament\Support\TableExportHelper::attach($table, 'Draws');

        return $table;
    }
}
