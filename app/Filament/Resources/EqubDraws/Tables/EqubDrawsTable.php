<?php

namespace App\Filament\Resources\EqubDraws\Tables;

use App\Models\EqubDraw;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

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
                // The winning place may be one held for someone with no Niya
                // account. displayName() names whose place it is; the line
                // under it names the member the payout is settled with, since
                // the money follows whoever has been paying the contributions.
                TextColumn::make('winnerMembership.member.full_name')
                    ->label('Winner')
                    ->sortable()
                    ->state(fn (EqubDraw $record): string => $record->winnerMembership?->displayName() ?? '—')
                    ->description(fn (EqubDraw $record): ?string => $record->winnerMembership?->isResponsibilitySeat()
                        ? 'Paid to '.($record->winnerMembership->sponsor?->full_name ?? 'the sponsor')
                        : null)
                    ->searchable(query: fn (Builder $query, string $search): Builder => $query
                        ->whereHas('winnerMembership', fn (Builder $m) => $m
                            ->where('responsibility_name', 'like', "%{$search}%")
                            ->orWhereHas('member', fn (Builder $mm) => $mm
                                ->where('full_name', 'like', "%{$search}%")))),

                TextColumn::make('winnerMembership.member.user.phone')
                    ->label('Winner Phone')
                    ->searchable()
                    // The place itself has no account, so the number that
                    // matters is the sponsor's — they are the one to call.
                    ->state(fn (EqubDraw $record): ?string => $record->winnerMembership?->payerUser()?->phone),
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
