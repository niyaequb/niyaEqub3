<?php

namespace App\Filament\Resources\EqubDraws\Schemas;

use App\Models\EqubDraw;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

/**
 * One round, explained.
 *
 * WHY A DRAW NEEDS A PAGE AT ALL
 *
 * A row in a list can say who won. It cannot answer the question that
 * actually gets asked, which is never "who won" but "why them". Somebody who
 * has paid for eleven months and watched someone else collect is owed a
 * better answer than a name and a timestamp.
 *
 * So the page is built around the audit trail: the pool as it stood, what
 * each entry was worth and why, who was excluded and for what reason, and the
 * seed the result was derived from. With the seed and the entries the round
 * can be recomputed from scratch and checked — which is the only form of
 * fairness that survives being doubted.
 *
 * ROUNDS RUN BEFORE ANY OF THIS EXISTED
 *
 * They have no seed and no snapshot, and the page says so plainly instead of
 * showing empty sections. An old round that cannot be verified is a fact
 * about the system's history, not something to hide.
 */
class EqubDrawInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([

            // ---------------------------------------------------------------
            // The result
            // ---------------------------------------------------------------
            Section::make(__('filament.equb_draw.result'))
                ->description(fn (EqubDraw $record): string => $record->equbGroup?->name ?? '')
                ->columns(4)
                ->schema([
                    TextEntry::make('round_number')
                        ->label(__('filament.equb_draw.round'))
                        ->formatStateUsing(fn (?int $state, EqubDraw $record): string => '#'.($state ?? $record->id))
                        ->weight('bold')
                        ->size('lg'),

                    TextEntry::make('draw_date')
                        ->label(__('filament.equb_draw.draw_date'))
                        ->dateTime('d M Y, H:i:s')
                        ->helperText(fn (EqubDraw $record): ?string => $record->draw_date?->diffForHumans()),

                    TextEntry::make('mode')
                        ->label(__('filament.equb_draw.mode'))
                        ->badge()
                        ->color(fn (?string $state): string => $state === 'manual' ? 'warning' : 'success')
                        ->formatStateUsing(fn (?string $state): string => __('filament.equb_draw.mode_'.($state ?: 'automatic')))
                        ->helperText(fn (EqubDraw $record): ?string => $record->mode === 'manual'
                            ? __('filament.equb_draw.mode_manual_note')
                            : null),

                    TextEntry::make('executedBy.name')
                        ->label(__('filament.equb_draw.executed_by'))
                        ->placeholder(__('filament.equb_draw.system')),

                    TextEntry::make('winner_name')
                        ->label(__('filament.equb_draw.winner'))
                        ->state(fn (EqubDraw $record): string => $record->winners_count > 1
                            ? trans_choice('filament.equb_draw.winners_count', $record->winners_count, ['count' => $record->winners_count])
                            : ($record->winnerMembership?->displayName() ?? '—'))
                        ->weight('bold')
                        ->helperText(fn (EqubDraw $record): ?string => $record->winnerMembership?->isResponsibilitySeat()
                            ? __('filament.equb_draw.held_place_note', [
                                'name' => $record->winnerMembership->sponsor?->full_name ?? __('filament.equb_draw.the_sponsor'),
                            ])
                            : null),

                    TextEntry::make('winner_phone')
                        ->label(__('filament.equb_draw.winner_phone'))
                        ->state(fn (EqubDraw $record): ?string => $record->winnerMembership?->payerUser()?->phone)
                        ->copyable()
                        ->placeholder('—'),

                    TextEntry::make('total_awarded')
                        ->label(__('filament.equb_draw.won_amount'))
                        ->state(fn (EqubDraw $record): float => $record->winners_count > 1
                            ? $record->totalAwarded()
                            : (float) ($record->winnerMembership?->expected_total_amount ?? 0))
                        ->money('ETB')
                        ->weight('bold')
                        ->color('success'),

                    // The one figure that decides whether this round was a
                    // problem. A payout to somebody who has stopped paying is
                    // the circle's money leaving with no way back.
                    TextEntry::make('winner_outstanding')
                        ->label(__('filament.equb_draw.winner_still_owes'))
                        ->state(fn (EqubDraw $record): float => (float) ($record->winnerMembership?->remaining_amount ?? 0))
                        ->money('ETB')
                        ->color(fn (EqubDraw $record): string => ($record->winnerMembership?->remaining_amount ?? 0) > 0
                            ? 'danger'
                            : 'success')
                        ->helperText(fn (EqubDraw $record): ?string => ($record->winnerMembership?->remaining_amount ?? 0) > 0
                            ? __('filament.equb_draw.winner_owes_note')
                            : null),
                ]),

            // ---------------------------------------------------------------
            // How it was decided
            // ---------------------------------------------------------------
            Section::make(__('filament.equb_draw.fairness'))
                ->description(__('filament.equb_draw.fairness_description'))
                ->columns(4)
                ->visible(fn (EqubDraw $record): bool => $record->isAuditable())
                ->schema([
                    TextEntry::make('winner_odds')
                        ->label(__('filament.equb_draw.winner_odds'))
                        ->formatStateUsing(fn (?string $state): string => $state === null
                            ? '—'
                            : number_format((float) $state, 3).'%')
                        ->weight('bold')
                        ->helperText(__('filament.equb_draw.winner_odds_note')),

                    TextEntry::make('pool_size')
                        ->label(__('filament.equb_draw.pool_size'))
                        ->helperText(fn (EqubDraw $record): string => __('filament.equb_draw.pool_note', [
                            'eligible' => max(0, (int) $record->pool_size - (int) $record->excluded_count),
                            'excluded' => (int) $record->excluded_count,
                        ])),

                    TextEntry::make('winner_weight')
                        ->label(__('filament.equb_draw.winner_weight'))
                        ->formatStateUsing(fn (?string $state): string => $state === null ? '—' : number_format((float) $state, 3))
                        ->helperText(fn (EqubDraw $record): ?string => $record->total_weight
                            ? __('filament.equb_draw.of_total_weight', ['total' => number_format((float) $record->total_weight, 3)])
                            : null),

                    TextEntry::make('random_seed')
                        ->label(__('filament.equb_draw.seed'))
                        ->copyable()
                        ->fontFamily('mono')
                        ->helperText(__('filament.equb_draw.seed_note')),
                ]),

            // Said out loud rather than left as four empty fields.
            Section::make(__('filament.equb_draw.not_auditable'))
                ->visible(fn (EqubDraw $record): bool => ! $record->isAuditable())
                ->schema([
                    TextEntry::make('audit_missing')
                        ->hiddenLabel()
                        ->state(__('filament.equb_draw.not_auditable_note')),
                ]),

            // ---------------------------------------------------------------
            // Everyone who won this round
            // ---------------------------------------------------------------
            Section::make(__('filament.equb_draw.all_winners'))
                ->visible(fn (EqubDraw $record): bool => $record->winners_count > 1)
                ->schema([
                    RepeatableEntry::make('winners')
                        ->hiddenLabel()
                        ->columns(3)
                        ->schema([
                            TextEntry::make('membership.member.full_name')
                                ->label(__('filament.equb_draw.winner'))
                                ->state(fn ($record): string => $record->membership?->displayName() ?? '—'),

                            TextEntry::make('membership.equbGroup.name')
                                ->label(__('filament.equb_draw.group'))
                                ->placeholder('—'),

                            TextEntry::make('amount_won')
                                ->label(__('filament.equb_draw.won_amount'))
                                ->money('ETB'),
                        ]),
                ]),

            TextEntry::make('notes')
                ->label(__('filament.equb_draw.notes'))
                ->placeholder('—')
                ->columnSpanFull(),
        ]);
    }
}
