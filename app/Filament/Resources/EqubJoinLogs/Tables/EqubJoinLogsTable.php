<?php

namespace App\Filament\Resources\EqubJoinLogs\Tables;

use App\Models\EqubJoinLog;
use Filament\Forms\Components\DatePicker;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class EqubJoinLogsTable
{
    public static function configure(Table $table): Table
    {
        $table
            ->defaultSort('joined_at', 'desc')
            ->striped()
            ->columns([
                TextColumn::make('joined_at')
                    ->label(__('Joined'))
                    ->dateTime('M j, Y · H:i')
                    ->sortable(),

                TextColumn::make('member_name')
                    ->label(__('Name'))
                    ->placeholder('—')
                    ->description(fn (EqubJoinLog $record): ?string => $record->sponsor_name
                        ? __('Added by').' '.$record->sponsor_name
                        : null)
                    ->searchable(),

                TextColumn::make('member_phone')
                    ->label(__('Phone'))
                    ->placeholder('—')
                    ->copyable()
                    ->searchable(),

                TextColumn::make('equb_name')
                    ->label(__('Equb'))
                    ->placeholder('—')
                    ->description(fn (EqubJoinLog $record): ?string => $record->package_name)
                    ->searchable(),

                TextColumn::make('package_name')
                    ->label(__('Package'))
                    ->placeholder('—')
                    ->searchable()
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('contribution_amount')
                    ->label(__('Amount'))
                    ->money('ETB')
                    ->sortable(),

                TextColumn::make('cycle')
                    ->label(__('Cycle'))
                    ->state(fn (EqubJoinLog $record): string => $record->cycleLabel())
                    ->toggleable(),

                TextColumn::make('source')
                    ->label(__('From'))
                    ->badge()
                    ->formatStateUsing(fn (?string $state): string => EqubJoinLog::SOURCES[$state] ?? (string) $state)
                    ->color(fn (?string $state): string => match ($state) {
                        'mini_app' => 'info',
                        'mobile_app' => 'success',
                        'agent' => 'warning',
                        default => 'gray',
                    }),

                // Exact time for the CSV/PDF exports, which print dates only.
                TextColumn::make('joined_time')
                    ->label(__('Time'))
                    ->state(fn (EqubJoinLog $record): ?string => $record->joined_at?->format('H:i:s'))
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('equb_membership_id')
                    ->label(__('Membership #'))
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('equb_package_id')
                    ->label(__('Package'))
                    ->options(fn (): array => EqubJoinLog::query()
                        ->whereNotNull('equb_package_id')
                        ->orderBy('package_name')
                        ->pluck('package_name', 'equb_package_id')
                        ->filter()
                        ->all()),

                SelectFilter::make('equb_group_id')
                    ->label(__('Equb'))
                    ->searchable()
                    ->options(fn (): array => EqubJoinLog::query()
                        ->whereNotNull('equb_group_id')
                        ->orderBy('equb_name')
                        ->pluck('equb_name', 'equb_group_id')
                        ->filter()
                        ->all()),

                SelectFilter::make('source')
                    ->label(__('From'))
                    ->options(EqubJoinLog::SOURCES),

                TernaryFilter::make('is_group_equb')
                    ->label(__('Group (Family) Equbs'))
                    ->placeholder(__('All'))
                    ->trueLabel(__('Only Group (Family) Equbs'))
                    ->falseLabel(__('Only Niya packages')),

                Filter::make('joined_at')
                    ->schema([
                        DatePicker::make('from')->label(__('Joined from')),
                        DatePicker::make('until')->label(__('Joined until')),
                    ])
                    ->query(fn (Builder $query, array $data): Builder => $query
                        ->when($data['from'] ?? null, fn (Builder $q, $date): Builder => $q->whereDate('joined_at', '>=', $date))
                        ->when($data['until'] ?? null, fn (Builder $q, $date): Builder => $q->whereDate('joined_at', '<=', $date)))
                    ->indicateUsing(function (array $data): array {
                        $indicators = [];
                        // Keyed by field, so each date can be cleared on its own.
                        if ($data['from'] ?? null) {
                            $indicators['from'] = __('Joined from').' '.$data['from'];
                        }
                        if ($data['until'] ?? null) {
                            $indicators['until'] = __('Joined until').' '.$data['until'];
                        }

                        return $indicators;
                    }),
            ]);

        \App\Filament\Support\TableExportHelper::attach($table, 'Equb Join Log');

        return $table;
    }
}
