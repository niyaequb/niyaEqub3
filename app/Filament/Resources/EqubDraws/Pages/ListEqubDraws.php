<?php

namespace App\Filament\Resources\EqubDraws\Pages;

use App\Enums\EqubGroupStatus;
use App\Filament\Resources\EqubDraws\EqubDrawResource;
use App\Models\EqubGroup;
use App\Services\GroupEqubLotteryService;
use Filament\Actions\Action;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Utilities\Get;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\HtmlString;

class ListEqubDraws extends ListRecords
{
    protected static string $resource = EqubDrawResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('drawLottery')
                ->label(__('filament.lottery.draw_lottery'))
                ->icon('heroicon-o-sparkles')
                ->color('primary')
                ->modalHeading(__('filament.lottery.modal_heading'))
                ->modalSubmitActionLabel(__('filament.lottery.run'))
                ->modalWidth('2xl')
                ->visible(fn (): bool => Auth::check() && (
                    Auth::user()->hasRole('Super Admin')
                    || Auth::user()->can('equb-draws.create')
                ))
                ->schema([
                    Select::make('equb_group_id')
                        ->label(__('filament.lottery.equb_group'))
                        ->options(fn (): array => EqubGroup::query()
                            ->whereNull('owner_member_id')
                            ->where('status', '!=', EqubGroupStatus::Cancelled->value)
                            ->orderBy('name')
                            ->get(['id', 'name', 'status'])
                            ->mapWithKeys(fn (EqubGroup $g): array => [
                                $g->id => $g->name.' ('.($g->status?->value ?? '').')',
                            ])
                            ->toArray())
                        ->searchable()
                        ->preload()
                        ->native(false)
                        ->required()
                        ->live()
                        ->helperText(__('filament.lottery.equb_group_helper')),

                    Placeholder::make('pool_summary')
                        ->hiddenLabel()
                        ->visible(fn (Get $get): bool => filled($get('equb_group_id')))
                        ->content(function (Get $get): HtmlString {
                            $parent = EqubGroup::find($get('equb_group_id'));

                            if (! $parent) {
                                return new HtmlString('');
                            }

                            $pool = app(GroupEqubLotteryService::class)->pool($parent);
                            $groups = $pool->count();
                            $members = (int) $pool->sum(fn ($g): int => (int) $g->head_count);
                            $perPerson = number_format($parent->contributionPerPerson(), 2);

                            $rows = $pool
                                ->sortByDesc(fn ($g): int => (int) $g->head_count)
                                ->map(fn ($g): string => "<span class='inline-flex items-center rounded-md bg-gray-100 px-2 py-1 text-xs font-medium text-gray-700 dark:bg-gray-800 dark:text-gray-300'>"
                                    .e($g->name).' · '.(int) $g->head_count.'</span>')
                                ->implode(' ');

                            if ($groups === 0) {
                                return new HtmlString(
                                    "<p class='text-sm text-danger-600 dark:text-danger-400'>"
                                    .e(__('filament.lottery.no_pool')).'</p>'
                                );
                            }

                            return new HtmlString("
                                <div class='space-y-2 rounded-xl border border-gray-200 bg-gray-50 p-3 dark:border-gray-700 dark:bg-gray-800/50'>
                                    <p class='text-sm text-gray-700 dark:text-gray-300'>
                                        <strong>{$groups}</strong> ".e(__('filament.lottery.groups_waiting'))."
                                        · <strong>{$members}</strong> ".e(__('filament.lottery.members_waiting'))."
                                        · ".e(__('filament.lottery.per_person')).": <strong>{$perPerson} ETB</strong>
                                    </p>
                                    <div class='flex flex-wrap gap-1'>{$rows}</div>
                                </div>
                            ");
                        }),

                    Radio::make('mode')
                        ->label(__('filament.lottery.mode'))
                        ->options([
                            'automatic' => __('filament.lottery.mode_automatic'),
                            'manual' => __('filament.lottery.mode_manual'),
                        ])
                        ->default('automatic')
                        ->required()
                        ->live(),

                    TextInput::make('target_members')
                        ->label(__('filament.lottery.target_members'))
                        ->helperText(__('filament.lottery.target_helper'))
                        ->numeric()
                        ->minValue(1)
                        ->visible(fn (Get $get): bool => $get('mode') === 'automatic')
                        ->required(fn (Get $get): bool => $get('mode') === 'automatic'),

                    Select::make('group_ids')
                        ->label(__('filament.lottery.pick_groups'))
                        ->multiple()
                        ->searchable()
                        ->native(false)
                        ->options(function (Get $get): array {
                            $parent = EqubGroup::find($get('equb_group_id'));

                            if (! $parent) {
                                return [];
                            }

                            return app(GroupEqubLotteryService::class)
                                ->pool($parent)
                                ->mapWithKeys(fn ($g): array => [
                                    $g->id => $g->name.' — '.(int) $g->head_count.' '.__('filament.lottery.members_short'),
                                ])
                                ->toArray();
                        })
                        ->visible(fn (Get $get): bool => $get('mode') === 'manual')
                        ->required(fn (Get $get): bool => $get('mode') === 'manual')
                        ->helperText(__('filament.lottery.pick_groups_helper')),
                ])
                ->action(function (array $data): void {
                    $parent = EqubGroup::find($data['equb_group_id']);

                    if (! $parent) {
                        Notification::make()->title(__('filament.lottery.group_missing'))->danger()->send();

                        return;
                    }

                    // Guard against a double submit without leaving a lock
                    // behind: if a round was already drawn seconds ago, that was
                    // the same click arriving twice.
                    $lastDraw = $parent->draws()->latest('draw_date')->first();

                    if ($lastDraw && $lastDraw->draw_date?->gt(now()->subSeconds(10))) {
                        Notification::make()->title(__('filament.lottery.already_running'))->warning()->send();

                        return;
                    }

                    $result = app(GroupEqubLotteryService::class)->draw(
                        $parent,
                        ($data['mode'] ?? 'automatic') === 'automatic' ? (int) $data['target_members'] : null,
                        ($data['mode'] ?? 'automatic') === 'manual' ? ($data['group_ids'] ?? []) : [],
                        Auth::id(),
                    );

                    if (! $result['success']) {
                        Notification::make()->title($result['message'])->danger()->send();

                        return;
                    }

                    $names = $result['winners']->pluck('name')->implode(', ');

                    Notification::make()
                        ->title(__('filament.lottery.done', ['count' => $result['members_won']]))
                        ->body($names)
                        ->success()
                        ->persistent()
                        ->send();
                }),
        ];
    }
}
