<?php

namespace App\Filament\Resources\EqubDraws\Tables;

use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class EqubDrawsTable
{
    public static function configure(Table $table): Table
    {
        $table
            ->columns([
                TextColumn::make('id')->label('ID')->sortable(),
                TextColumn::make('equbGroup.package.name')->label('Package')->searchable()->sortable(),
                TextColumn::make('equbGroup.name')->label('Group')->searchable()->sortable(),
                TextColumn::make('equb_group_id')->label('Group ID')->sortable(),
                TextColumn::make('draw_date')->label('Draw Date')->dateTime()->sortable(),
                TextColumn::make('winnerMembership.member.full_name')->label('Winner')->searchable()->sortable(),
                TextColumn::make('winnerMembership.member.user.phone')->label('Winner Phone')->searchable(),
                TextColumn::make('winnerMembership.expected_total_amount')
                    ->label('Won Amount')
                    ->money('ETB')
                    ->sortable(),
                TextColumn::make('winnerMembership.total_paid')
                    ->label('Paid')
                    ->money('ETB')
                    ->sortable(),
                TextColumn::make('winnerMembership.remaining_amount')
                    ->label('Remaining')
                    ->money('ETB')
                    ->color('danger')
                    ->sortable(),
                TextColumn::make('executedBy.name')->label('Executed By')->placeholder('System')->toggleable(),
            ])
            ->filters([
                SelectFilter::make('equb_group_id')
                    ->relationship('equbGroup', 'id')
                    ->label('Group')
                    ->getOptionLabelFromRecordUsing(fn ($r) => $r ? 'Group #'.$r->id.' - '.($r->package?->name ?? '') : ''),
            ])
            ->recordActions([])
            ->defaultSort('draw_date', 'desc');

        \App\Filament\Support\TableExportHelper::attach($table, 'Equb Draws');

        return $table;
    }
}
