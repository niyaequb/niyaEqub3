<?php

namespace App\Filament\Resources\EqubMemberships\Tables;

use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Auth;

class EqubMembershipsTable
{
    public static function configure(Table $table): Table
    {
        $table
            ->columns([
                TextColumn::make('id')->label('ID')->sortable(),
                TextColumn::make('equbGroup.package.name')->label('Package')->searchable()->sortable(),
                TextColumn::make('equbGroup.name')->label('Group')->searchable()->sortable(),
                TextColumn::make('member.full_name')->label('Member')->searchable()->sortable(),
                TextColumn::make('member.user.phone')->label('Phone')->searchable(),
                TextColumn::make('contribution_amount')->label('Amount')->money('ETB')->sortable(),
                TextColumn::make('contribution_frequency_days')->label('Freq. Days'),
                TextColumn::make('join_date')->label('Join Date')->dateTime()->sortable(),
                TextColumn::make('calculated_end_date')->label('End Date')->dateTime()->toggleable(),
                TextColumn::make('draw_position')->label('Position')->toggleable(),
                IconColumn::make('has_won')->label('Won')->boolean()->sortable(),
                TextColumn::make('status')->label('Status')->badge()->sortable(),
            ])
            ->filters([
                SelectFilter::make('status')->options([
                    'active' => 'Active',
                    'completed' => 'Completed',
                    'cancelled' => 'Cancelled',
                ]),
            ])
            ->recordActions([
                EditAction::make()
                    ->visible(fn (): bool =>
                        Auth::check() &&
                         ( Auth::user()->hasRole('Super Admin') || Auth::user()->can('equb-memberships.edit'))),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make()
                        ->visible(fn (): bool =>
                            Auth::check() &&
                             ( Auth::user()->hasRole('Super Admin') || Auth::user()->can('equb-memberships.delete'))),
                ]),
            ]);

        \App\Filament\Support\TableExportHelper::attach($table, 'Equb Memberships');

        return $table;
    }
}
