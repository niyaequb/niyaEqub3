<?php

namespace App\Filament\Resources\EqubDraws\Tables;

use App\Models\EqubDraw;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * The record of every round ever run.
 *
 * WHAT THIS TABLE IS FOR
 *
 * Three questions bring somebody here, and the column order answers them in
 * the order they are asked:
 *
 *   "Who won, and has the money gone out?"     Winner, Won, Paid
 *   "Was that round fair?"                     Odds, Pool
 *   "Who ran it, and when?"                    Date, Mode, Executed by
 *
 * It used to lead with Package, Group and Group ID — three columns of context
 * before the first fact anybody came for — and showed no trace at all of how
 * the winner was chosen. A round is now summarised as a round: which one it
 * was, what it paid, what chance the winner held and how large a pool they
 * beat. The Group ID column is gone from the default view; it is a database
 * key and it was sitting in the fourth position on a screen used by people
 * reconciling payouts.
 *
 * ODDS AS A FIRST-CLASS COLUMN
 *
 * Showing the winner's own chance next to their name is the cheapest possible
 * defence of the draw. A run of winners all at 4-5% reads as a working
 * lottery; one at 60% is either a very small pool or something worth asking
 * about, and either way it is visible from the list instead of requiring
 * somebody to know to go looking.
 */
class EqubDrawsTable
{
    public static function configure(Table $table): Table
    {
        $table
            ->defaultSort('draw_date', 'desc')
            ->striped()
            ->recordUrl(fn (EqubDraw $record): string => \App\Filament\Resources\EqubDraws\EqubDrawResource::getUrl('view', ['record' => $record]))
            ->columns([
                // The round, not the row id. "Round 7 of Raha Daily" is how
                // this is spoken about; the primary key is an implementation
                // detail and sits behind a toggle.
                TextColumn::make('round_number')
                    ->label(__('filament.equb_draw.round'))
                    ->sortable()
                    ->alignCenter()
                    ->badge()
                    ->color('gray')
                    ->formatStateUsing(fn (?int $state, EqubDraw $record): string => $state
                        ? '#'.$state
                        : '#'.$record->id)
                    ->description(fn (EqubDraw $record): ?string => $record->winners_count > 1
                        ? trans_choice('filament.equb_draw.winners_count', $record->winners_count, ['count' => $record->winners_count])
                        : null),

                TextColumn::make('draw_date')
                    ->label(__('filament.equb_draw.draw_date'))
                    ->dateTime('d M Y, H:i')
                    ->sortable()
                    ->description(fn (EqubDraw $record): ?string => $record->draw_date?->diffForHumans()),

                TextColumn::make('equbGroup.name')
                    ->label(__('filament.equb_draw.equb'))
                    ->searchable()
                    ->sortable()
                    ->description(fn (EqubDraw $record): ?string => $record->equbGroup?->package?->name),

                // The winning place may be one held for someone with no Niya
                // account. displayName() names whose place it is; the line
                // under it names the member the payout is settled with, since
                // the money follows whoever has been paying the contributions.
                TextColumn::make('winnerMembership.member.full_name')
                    ->label(__('filament.equb_draw.winner'))
                    ->sortable()
                    ->weight('medium')
                    ->state(fn (EqubDraw $record): string => $record->winners_count > 1
                        ? trans_choice('filament.equb_draw.winners_count', $record->winners_count, ['count' => $record->winners_count])
                        : ($record->winnerMembership?->displayName() ?? '—'))
                    ->description(function (EqubDraw $record): ?string {
                        if ($record->winners_count > 1) {
                            return $record->winners->take(3)
                                ->map(fn ($w) => $w->membership?->displayName())
                                ->filter()
                                ->implode(', ');
                        }

                        return $record->winnerMembership?->isResponsibilitySeat()
                            ? __('filament.equb_draw.paid_to', [
                                'name' => $record->winnerMembership->sponsor?->full_name ?? __('filament.equb_draw.the_sponsor'),
                            ])
                            : null;
                    })
                    ->searchable(query: fn (Builder $query, string $search): Builder => $query
                        ->whereHas('winnerMembership', fn (Builder $m) => $m
                            ->where('responsibility_name', 'like', "%{$search}%")
                            ->orWhereHas('member', fn (Builder $mm) => $mm
                                ->where('full_name', 'like', "%{$search}%")))),

                TextColumn::make('winnerMembership.member.user.phone')
                    ->label(__('filament.equb_draw.winner_phone'))
                    ->searchable()
                    ->copyable()
                    ->toggleable()
                    // The place itself has no account, so the number that
                    // matters is the sponsor's — they are the one to call.
                    ->state(fn (EqubDraw $record): ?string => $record->winnerMembership?->payerUser()?->phone),

                TextColumn::make('amount_won')
                    ->label(__('filament.equb_draw.won_amount'))
                    ->money('ETB')
                    ->alignEnd()
                    ->weight('medium')
                    // Across every winner of the round, not just the first.
                    // On a group round the figure on the first winner's
                    // membership is one member's share and reads as if the
                    // whole family collected it.
                    ->state(fn (EqubDraw $record): float => $record->winners_count > 1
                        ? $record->totalAwarded()
                        : (float) ($record->winnerMembership?->expected_total_amount ?? 0)),

                TextColumn::make('winnerMembership.total_paid')
                    ->label(__('filament.equb_draw.paid'))
                    ->money('ETB')
                    ->alignEnd()
                    ->toggleable()
                    ->color('success'),

                // The debt a payout creates. A winner still owing money is the
                // single most expensive position in an Equb, because the
                // circle has already handed over the pot.
                TextColumn::make('winnerMembership.remaining_amount')
                    ->label(__('filament.equb_draw.remaining'))
                    ->money('ETB')
                    ->alignEnd()
                    ->color(fn (EqubDraw $record): string => ($record->winnerMembership?->remaining_amount ?? 0) > 0
                        ? 'danger'
                        : 'gray'),

                // What chance the winner actually held. The defence of the
                // draw, visible without opening anything.
                TextColumn::make('winner_odds')
                    ->label(__('filament.equb_draw.odds'))
                    ->alignEnd()
                    ->sortable()
                    ->placeholder('—')
                    ->formatStateUsing(fn (?string $state): string => $state === null
                        ? '—'
                        : number_format((float) $state, 2).'%')
                    ->description(fn (EqubDraw $record): ?string => $record->pool_size
                        ? __('filament.equb_draw.of_pool', ['count' => $record->pool_size])
                        : null)
                    ->color(fn (EqubDraw $record): string => match (true) {
                        $record->winner_odds === null => 'gray',
                        (float) $record->winner_odds > 50 => 'warning',
                        default => 'gray',
                    }),

                TextColumn::make('mode')
                    ->label(__('filament.equb_draw.mode'))
                    ->badge()
                    ->toggleable()
                    ->color(fn (?string $state): string => $state === 'manual' ? 'warning' : 'success')
                    ->formatStateUsing(fn (?string $state): string => __('filament.equb_draw.mode_'.($state ?: 'automatic'))),

                TextColumn::make('executedBy.name')
                    ->label(__('filament.equb_draw.executed_by'))
                    ->placeholder(__('filament.equb_draw.system'))
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('equb_group_id')
                    ->label(__('filament.equb_draw.group_id'))
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('id')
                    ->label('#')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('equb_group_id')
                    ->relationship('equbGroup', 'name')
                    ->label(__('filament.equb_draw.equb'))
                    ->searchable()
                    ->preload()
                    ->multiple(),

                SelectFilter::make('mode')
                    ->label(__('filament.equb_draw.mode'))
                    ->options([
                        'automatic' => __('filament.equb_draw.mode_automatic'),
                        'manual' => __('filament.equb_draw.mode_manual'),
                    ]),

                // A hand-picked winner is a legitimate operation and also the
                // one an auditor looks at first, so it gets its own switch
                // rather than being buried in the mode dropdown.
                Filter::make('manual_only')
                    ->label(__('filament.equb_draw.filter_manual'))
                    ->query(fn (Builder $query): Builder => $query->where('mode', 'manual')),

                // Rounds run before the audit trail existed. Worth being able
                // to isolate, because they are the ones that cannot be
                // re-derived and nothing on the row itself says so.
                Filter::make('unverifiable')
                    ->label(__('filament.equb_draw.filter_unverifiable'))
                    ->query(fn (Builder $query): Builder => $query->whereNull('random_seed')),

                Filter::make('winner_owes')
                    ->label(__('filament.equb_draw.filter_winner_owes'))
                    ->query(fn (Builder $query): Builder => $query
                        ->whereHas('winnerMembership', fn (Builder $m) => $m
                            ->whereDoesntHave('payments', fn (Builder $p) => $p
                                ->where('status', \App\Enums\EqubPaymentStatus::Paid)))),
            ])
            ->recordActions([
                ViewAction::make(),
            ]);

        \App\Filament\Support\TableExportHelper::attach($table, __('filament.equb_draw.title'));

        return $table;
    }
}
