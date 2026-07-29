<?php

namespace App\Filament\Resources\CommissionRules\Tables;

use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Auth;

class CommissionRulesTable
{
    public static function configure(Table $table): Table
    {
        $table
            ->columns([
                TextColumn::make('name')
                    ->label('Name')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('trigger')
                    ->label('Trigger')
                    ->badge()
                    ->sortable(),
                TextColumn::make('commission_type')
                    ->label('Type')
                    ->badge()
                    ->sortable(),
                TextColumn::make('commission_value')
                    ->label('Value')
                    ->sortable(),
                TextColumn::make('agent.user.name')
                    ->label('Agent')
                    ->getStateUsing(fn ($record) => $record->agent?->user?->name ?? 'Global')
                    ->toggleable(),
                IconColumn::make('is_active')
                    ->label('Active')
                    ->boolean()
                    ->sortable(),
            ])
            ->filters([
                //
            ])
            ->recordActions([
                EditAction::make()
                    ->visible(fn (): bool =>
                        Auth::check() &&
                         ( Auth::user()->hasRole('Super Admin') || Auth::user()->can('commission-rules.edit'))),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make()
                     ->visible(fn (): bool =>
                        Auth::check() &&
                         ( Auth::user()->hasRole('Super Admin') || Auth::user()->can('commission-rules.delete')))
                     ->deselectRecordsAfterCompletion()
                     ->action(function ($records) {
                         foreach ($records as $record) {
                             $record->delete();
                         }
                     }),
                ]),
            ]);

        // \App\Filament\Support\TableExportHelper::attach($table, 'Commission Rules');

        return $table;
    }
}
