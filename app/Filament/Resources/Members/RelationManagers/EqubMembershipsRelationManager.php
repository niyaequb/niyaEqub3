<?php

namespace App\Filament\Resources\Members\RelationManagers;

use App\Filament\Resources\Members\MemberResource;
use Filament\Actions\Action;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class EqubMembershipsRelationManager extends RelationManager
{
    protected static string $relationship = 'equbMemberships';

    protected static ?string $title = 'Equb Memberships';

    public function table(Table $table): Table
    {
        $table
            ->columns([
                TextColumn::make('equbGroup.package.name')->label('Equb Package')->searchable()->sortable(),
                TextColumn::make('equbGroup.name')->label('Group')->searchable()->sortable(),
                TextColumn::make('equbGroup.id')->label('Group')->formatStateUsing(fn ($state) => '#'.$state)->sortable(),
                TextColumn::make('contribution_amount')->label('Amount')->money('ETB')->sortable(),
                TextColumn::make('contribution_frequency_days')->label('Freq. Days'),
                TextColumn::make('join_date')->label('Join Date')->dateTime()->sortable(),
                TextColumn::make('status')->label('Status')->badge(),
                TextColumn::make('has_won')->label('Won')->formatStateUsing(fn ($state) => $state ? 'Yes' : 'No')->sortable(),
            ])
            ->defaultSort('join_date', 'desc')
            ->headerActions([
                Action::make('manage_equbs')
                    ->label('Manage Equbs')
                    ->icon('heroicon-o-rectangle-stack')
                    ->url(fn (): string => MemberResource::getUrl('equbs', ['record' => $this->getOwnerRecord()]))
                    ->color('primary'),
            ])
            ->actions([]);

        \App\Filament\Support\TableExportHelper::attach($table, 'Equb Memberships');

        return $table;
    }
}
