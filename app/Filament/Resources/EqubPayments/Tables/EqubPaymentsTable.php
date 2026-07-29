<?php

namespace App\Filament\Resources\EqubPayments\Tables;

use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Auth;

class EqubPaymentsTable
{
    public static function configure(Table $table): Table
    {
        $table
            ->columns([
                TextColumn::make('id')->label('ID')->sortable(),
                TextColumn::make('membership.member.full_name')->label('Member')->searchable()->sortable(),
                TextColumn::make('membership.equbGroup.package.name')->label('Package')->searchable(),
                TextColumn::make('membership.equbGroup.name')->label('Group')->searchable(),
                TextColumn::make('amount')->label('Amount')->money('ETB')->sortable(),
                TextColumn::make('payment_date')->label('Payment Date')->dateTime()->sortable(),
                TextColumn::make('payment_method')->label('Method')->badge()->sortable(),
                TextColumn::make('status')->label('Status')->badge()->sortable(),
                TextColumn::make('reference')->label('Reference')->toggleable(),
            ])
            ->filters([
                SelectFilter::make('status')->options([
                    'pending' => 'Pending',
                    'paid' => 'Paid',
                    'failed' => 'Failed',
                ]),
                SelectFilter::make('payment_method')->options([
                    'chapa' => 'Chapa',
                    'offline' => 'Offline',
                    'manual' => 'Manual',
                ]),
            ])
            ->recordActions([
                EditAction::make()
                    ->visible(fn (): bool =>
                        Auth::check() &&
                         ( Auth::user()->hasRole('Super Admin') || Auth::user()->can('equb-payments.edit'))),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make()
                        ->visible(fn (): bool =>
                            Auth::check() &&
                             ( Auth::user()->hasRole('Super Admin') || Auth::user()->can('equb-payments.delete'))),
                ]),
            ]);

        \App\Filament\Support\TableExportHelper::attach($table, 'Equb Payments');

        return $table;
    }
}
