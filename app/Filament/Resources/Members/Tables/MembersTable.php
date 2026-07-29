<?php

namespace App\Filament\Resources\Members\Tables;

use App\Models\Member;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Auth;

class MembersTable
{
    public static function configure(Table $table): Table
    {
        $table
            ->columns([
                TextColumn::make('id')
                    ->label('ID')
                    ->sortable(),
                TextColumn::make('full_name')
                    ->label('Full Name')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('user.phone')
                    ->label('Phone')
                    ->searchable(),
                IconColumn::make('user.is_active')
                    ->label('Active')
                    ->boolean()
                    ->getStateUsing(fn (Member $record): bool => (bool) $record->user?->is_active)
                    ->sortable(),
                IconColumn::make('user.phone_verified_at')
                    ->label('Phone Verified')
                    ->boolean()
                    ->getStateUsing(fn (Member $record): bool => (bool) $record->user?->phone_verified_at)
                    ->sortable(),
                TextColumn::make('agent.name')
                    ->label('Agent')
                    ->getStateUsing(fn (Member $record): ?string => $record->agent?->user?->name)
                    ->toggleable(),
                TextColumn::make('registered_via')
                    ->label('Registered Via')
                    ->badge(),
                TextColumn::make('referral_code_used')
                    ->label('Referral Code')
                    ->toggleable(),
                TextColumn::make('registered_at')
                    ->label('Registered At')
                    ->dateTime()
                    ->sortable(),
                TextColumn::make('created_at')
                    ->label('Created At')
                    ->dateTime()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                //
            ])
            ->recordActions([
                ActionGroup::make([
                    ViewAction::make()
                        ->visible(fn (): bool =>
                        Auth::check() &&
                         ( Auth::user()->hasRole('Super Admin') || Auth::user()->can('members.index'))),
                    EditAction::make()
                        ->visible(fn (): bool =>
                        Auth::check() &&
                         ( Auth::user()->hasRole('Super Admin') || Auth::user()->can('members.edit'))),
                    Action::make('activate')
                        ->label('Activate')
                        ->color('success')
                        ->icon('heroicon-s-check-circle')
                        ->visible(function (Member $record): bool {
                            return Auth::check() &&
                             (Auth::user()->hasRole('Super Admin') || Auth::user()->can('members.edit')) && ! (bool) $record->user?->is_active;
                        })
                        ->action(function (Member $record): void {
                            $record->user?->update(['is_active' => true]);
                        }),
                    Action::make('deactivate')
                        ->label('Deactivate')
                        ->color('danger')
                        ->icon('heroicon-s-x-mark')
                        ->visible(function (Member $record): bool {
                            return Auth::check() &&
                             (Auth::user()->hasRole('Super Admin') || Auth::user()->can('members.edit')) && (bool) $record->user?->is_active;
                        })
                        ->action(function (Member $record): void {
                            $record->user?->update(['is_active' => false]);
                        }),
                    Action::make('verify_phone')
                        ->label('Verify Phone')
                        ->color('success')
                        ->icon('heroicon-s-check-circle')
                        ->visible(function (Member $record): bool {
                            return Auth::check() &&
                             (Auth::user()->hasRole('Super Admin') || Auth::user()->can('members.edit')) && ! (bool) $record->user?->phone_verified_at;
                        })
                        ->action(function (Member $record): void {
                            $record->user?->update(['phone_verified_at' => now()]);
                        }),
                    Action::make('unverify_phone')
                        ->label('Unverify Phone')
                        ->color('danger')
                        ->icon('heroicon-s-x-circle')
                        ->visible(function (Member $record): bool {
                            return Auth::check() &&
                             (Auth::user()->hasRole('Super Admin') || Auth::user()->can('members.edit')) && (bool) $record->user?->phone_verified_at;
                        })
                        ->action(function (Member $record): void {
                            $record->user?->update(['phone_verified_at' => null]);
                        }),
                    DeleteAction::make()
                        ->visible(function (Member $record): bool {
                            return Auth::check() &&
                             (Auth::user()->hasRole('Super Admin') || Auth::user()->can('members.delete'));
                        })
                        ->action(function (Member $record): void {
                            if ($record->user) {
                                $record->user->delete();
                            } else {
                                $record->delete();
                            }
                        }),
                ])
                    ->icon('heroicon-m-ellipsis-vertical')
                    ->iconButton()
                    ->label(''),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make()
                        ->visible(function (): bool {
                            return Auth::check() &&
                             (Auth::user()->hasRole('Super Admin') || Auth::user()->can('members.delete'));
                        }),
                ]),
            ]);

        \App\Filament\Support\TableExportHelper::attach($table, 'Members');

        return $table;
    }
}
