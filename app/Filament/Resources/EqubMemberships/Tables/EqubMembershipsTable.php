<?php

namespace App\Filament\Resources\EqubMemberships\Tables;

use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use App\Models\EqubMembership;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
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

                // A "My Responsibility People" place has no member row behind
                // it, so member.full_name comes back empty and the row looks
                // broken. displayName() returns the name the sponsor gave them
                // instead, and the badge below says who is paying.
                TextColumn::make('member.full_name')
                    ->label('Member')
                    ->sortable()
                    ->state(fn (EqubMembership $record): string => $record->displayName())
                    ->description(fn (EqubMembership $record): ?string => $record->isResponsibilitySeat()
                        ? 'Responsibility · paid by '.($record->sponsor?->full_name ?? 'the sponsor')
                        : null)
                    // Searching has to cover both names, otherwise typing a
                    // child's name finds nothing even though the row is right
                    // there on screen.
                    ->searchable(query: fn (Builder $query, string $search): Builder => $query
                        ->where(fn (Builder $q) => $q
                            ->where('responsibility_name', 'like', "%{$search}%")
                            ->orWhereHas('member', fn (Builder $m) => $m
                                ->where('full_name', 'like', "%{$search}%")))),

                // Falls back to the contact number the sponsor recorded, which
                // is the only number one of these places ever has.
                TextColumn::make('member.user.phone')
                    ->label('Phone')
                    ->state(fn (EqubMembership $record): ?string => $record->isResponsibilitySeat()
                        ? $record->responsibility_phone
                        : $record->member?->user?->phone)
                    ->searchable(query: fn (Builder $query, string $search): Builder => $query
                        ->where(fn (Builder $q) => $q
                            ->where('responsibility_phone', 'like', "%{$search}%")
                            ->orWhereHas('member.user', fn (Builder $u) => $u
                                ->where('phone', 'like', "%{$search}%")))),
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

                // Lets an admin separate real accounts from places held on
                // someone's behalf — useful when reconciling a head-count
                // against the number of people who can actually log in.
                TernaryFilter::make('is_responsibility_seat')
                    ->label('Responsibility places')
                    ->placeholder('All')
                    ->trueLabel('Only responsibility places')
                    ->falseLabel('Only real members')
                    ->queries(
                        true: fn (Builder $q): Builder => $q->whereNull('member_id'),
                        false: fn (Builder $q): Builder => $q->whereNotNull('member_id'),
                        blank: fn (Builder $q): Builder => $q,
                    ),
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
