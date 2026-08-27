<?php

namespace App\Filament\Resources\EqubPayments\Tables;

use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use App\Models\EqubPayment;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;

class EqubPaymentsTable
{
    public static function configure(Table $table): Table
    {
        $table
            ->columns([
                TextColumn::make('id')->label('ID')->sortable(),

                // A payment can be against a place held for someone with no
                // Niya account, where there is no member row to read a name
                // from. The name shown is whose place it is; the line beneath
                // says whose money it was, which is what an admin
                // reconciling a receipt actually needs.
                TextColumn::make('membership.member.full_name')
                    ->label('Member')
                    ->sortable()
                    ->state(fn (EqubPayment $record): string => $record->membership?->displayName() ?? '—')
                    ->description(fn (EqubPayment $record): ?string => $record->membership?->isResponsibilitySeat()
                        ? 'Paid by '.($record->membership->sponsor?->full_name ?? 'the sponsor')
                        : null)
                    ->searchable(query: fn (Builder $query, string $search): Builder => $query
                        ->whereHas('membership', fn (Builder $m) => $m
                            ->where('responsibility_name', 'like', "%{$search}%")
                            ->orWhereHas('member', fn (Builder $mm) => $mm
                                ->where('full_name', 'like', "%{$search}%")))),
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
                    'dashen' => 'Dashen',
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
