<?php

namespace App\Filament\Resources\Agents\Tables;

use App\Models\Agent;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Auth;

class AgentsTable
{
    public static function configure(Table $table): Table
    {
        $table
            ->columns([
                TextColumn::make('id')
                    ->label('ID')
                    ->sortable(),
                TextColumn::make('user.name')
                    ->label('Name')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('user.phone')
                    ->label('Phone')
                    ->searchable(),
                TextColumn::make('referral_code')
                    ->label('Referral Code')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('members_count')
                    ->label('Members')
                    ->counts('members')
                    ->sortable(),
                IconColumn::make('is_active')
                    ->label('Active')
                    ->boolean()
                    ->sortable(),
                TextColumn::make('joined_at')
                    ->label('Joined At')
                    ->dateTime()
                    ->sortable(),
                TextColumn::make('bank_name')
                    ->label('Bank')
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('account_number')
                    ->label('Account Number')
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('account_holder_name')
                    ->label('Account Holder')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                //
            ])
            ->recordActions([
                ViewAction::make()
                    ->visible(fn (): bool =>
                        Auth::check() &&
                         ( Auth::user()->hasRole('Super Admin') || Auth::user()->can('agents.index'))),
                EditAction::make()
                    ->visible(fn (): bool =>
                        Auth::check() &&
                         ( Auth::user()->hasRole('Super Admin') || Auth::user()->can('agents.edit'))),
                Action::make('activate')
                    ->label('Activate')
                    ->icon('heroicon-s-check-circle')
                    ->color('success')
                    ->visible(function (Agent $record): bool {
                        return Auth::check() &&
                         ( Auth::user()->hasRole('Super Admin') || Auth::user()->can('agents.edit')) && ! $record->is_active;
                    })
                    ->action(fn (Agent $record) => $record->update(['is_active' => true])),
                Action::make('deactivate')
                    ->label('Deactivate')
                    ->icon('heroicon-s-x-mark')
                    ->color('danger')
                    ->visible(function (Agent $record): bool {
                        return Auth::check() &&
                         ( Auth::user()->hasRole('Super Admin') || Auth::user()->can('agents.edit')) && $record->is_active;
                    })
                    ->action(fn (Agent $record) => $record->update(['is_active' => false])),
            ])
            ->toolbarActions([
                //
            ]);

        \App\Filament\Support\TableExportHelper::attach($table, 'Agents');

        return $table;
    }
}
