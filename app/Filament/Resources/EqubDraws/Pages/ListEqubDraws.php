<?php

namespace App\Filament\Resources\EqubDraws\Pages;

use App\Enums\EqubGroupStatus;
use App\Filament\Pages\DrawSettings;
use App\Filament\Resources\EqubDraws\EqubDrawResource;
use App\Filament\Resources\EqubDraws\Widgets\DrawOverview;
use App\Models\EqubGroup;
use App\Services\Equb\EqubRules;
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

/**
 * The list of rounds, and the button that runs a new one.
 *
 * WHAT THE MODAL SHOWS BEFORE THE BUTTON IS PRESSED
 *
 * Running a draw pays out real money and cannot be undone. The old modal
 * asked for an Equb and a number and then ran; the first anyone learned about
 * a family being held back for arrears was a result that did not include them,
 * or a refusal reading "No Group Equbs on this Equb are eligible yet" — which
 * on an Equb with twelve waiting families tells the operator nothing they can
 * act on.
 *
 * It now shows the pool first: who is in, who is held back, by name, with
 * what they owe. That single change turns the draw from something that
 * happens to the admin into something they can see coming.
 */
class ListEqubDraws extends ListRecords
{
    protected static string $resource = EqubDrawResource::class;

    public function getSubheading(): ?string
    {
        return __('filament.equb_draw.subheading');
    }

    protected function getHeaderWidgets(): array
    {
        return [
            DrawOverview::class,
        ];
    }

    public function getHeaderWidgetsColumns(): int|array
    {
        return 4;
    }

    protected function getHeaderActions(): array
    {
        return [
            // Next to the draw button on purpose. The rules and the act of
            // running a round under them belong on the same screen — an
            // admin who does not like what the pool looks like should be one
            // click from the settings that shaped it.
            Action::make('drawSettings')
                ->label(__('filament.draw_settings.nav'))
                ->icon('heroicon-o-adjustments-horizontal')
                ->color('gray')
                ->url(fn (): string => DrawSettings::getUrl())
                ->visible(fn (): bool => DrawSettings::canAccess()),

            Action::make('drawLottery')
                ->label(__('filament.lottery.draw_lottery'))
                ->icon('heroicon-o-sparkles')
                ->color('primary')
                ->modalHeading(__('filament.lottery.modal_heading'))
                ->modalDescription(__('filament.lottery.modal_description'))
                ->modalSubmitActionLabel(__('filament.lottery.run'))
                ->modalWidth('3xl')
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

                    // The pool, before anything is drawn. Rendered as markup
                    // rather than as fields because it is a briefing, not
                    // input — and because the part that matters most, the
                    // held-back families and what each of them owes, has no
                    // sensible shape as a form control.
                    Placeholder::make('pool_summary')
                        ->hiddenLabel()
                        ->visible(fn (Get $get): bool => filled($get('equb_group_id')))
                        ->content(fn (Get $get): HtmlString => $this->poolBriefing((int) $get('equb_group_id'))),

                    Radio::make('mode')
                        ->label(__('filament.lottery.mode'))
                        ->options([
                            'automatic' => __('filament.lottery.mode_automatic'),
                            'manual' => __('filament.lottery.mode_manual'),
                        ])
                        ->descriptions([
                            'automatic' => __('filament.lottery.mode_automatic_description'),
                            'manual' => __('filament.lottery.mode_manual_description'),
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
                        // A hand-picked winner is recorded as such on the
                        // draw, permanently. Worth saying at the point of
                        // choosing rather than discovering in an audit.
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
                        Notification::make()
                            ->title(__('filament.lottery.not_run'))
                            ->body($result['message'])
                            ->danger()
                            ->persistent()
                            ->send();

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

    /**
     * The pool as it stands right now, in plain language.
     *
     * Three blocks, in the order an operator needs them: the totals, the
     * families that are in, and — last and most important — the families that
     * are not, each with the members holding them back and what those members
     * owe. Someone can read that, ring two people, and run the round properly
     * instead of running a short one and wondering why.
     */
    protected function poolBriefing(int $parentId): HtmlString
    {
        $parent = EqubGroup::find($parentId);

        if (! $parent) {
            return new HtmlString('');
        }

        $screened = app(GroupEqubLotteryService::class)->screen($parent);
        $rules = app(EqubRules::class);

        if ($screened->isEmpty()) {
            return new HtmlString(
                "<p class='text-sm text-danger-600 dark:text-danger-400'>"
                .e(__('filament.lottery.no_pool')).'</p>'
            );
        }

        $eligible = $screened->where('eligible', true);
        $held = $screened->where('eligible', false);

        $members = (int) $eligible->sum(fn (array $r): int => $r['head_count']);
        $heldMembers = (int) $held->sum(fn (array $r): int => $r['head_count']);
        $perPerson = number_format($parent->contributionPerPerson(), 2);

        $chips = $eligible
            ->sortByDesc(fn (array $r): float => $r['weight'])
            ->map(fn (array $r): string => "<span class='inline-flex items-center gap-1 rounded-md bg-success-50 px-2 py-1 text-xs font-medium text-success-700 dark:bg-success-500/10 dark:text-success-400'>"
                .e($r['group']->name)
                ."<span class='opacity-60'>".(int) $r['head_count'].'</span></span>')
            ->implode(' ');

        $summary = "
            <div class='space-y-3 rounded-xl border border-gray-200 bg-gray-50 p-3 dark:border-white/10 dark:bg-white/5'>
                <div class='grid grid-cols-2 gap-3 sm:grid-cols-4'>
                    <div>
                        <p class='text-xs uppercase tracking-wide text-gray-500 dark:text-gray-400'>".e(__('filament.lottery.groups_waiting'))."</p>
                        <p class='text-lg font-semibold tabular-nums text-gray-950 dark:text-white'>{$eligible->count()}</p>
                    </div>
                    <div>
                        <p class='text-xs uppercase tracking-wide text-gray-500 dark:text-gray-400'>".e(__('filament.lottery.members_waiting'))."</p>
                        <p class='text-lg font-semibold tabular-nums text-gray-950 dark:text-white'>{$members}</p>
                    </div>
                    <div>
                        <p class='text-xs uppercase tracking-wide text-gray-500 dark:text-gray-400'>".e(__('filament.lottery.per_person'))."</p>
                        <p class='text-lg font-semibold tabular-nums text-gray-950 dark:text-white'>{$perPerson}</p>
                    </div>
                    <div>
                        <p class='text-xs uppercase tracking-wide text-gray-500 dark:text-gray-400'>".e(__('filament.lottery.held_back'))."</p>
                        <p class='text-lg font-semibold tabular-nums ".($held->isEmpty() ? 'text-gray-950 dark:text-white' : 'text-danger-600 dark:text-danger-400')."'>{$held->count()}</p>
                    </div>
                </div>
                <div class='flex flex-wrap gap-1'>{$chips}</div>
            </div>";

        if ($held->isEmpty()) {
            return new HtmlString($summary);
        }

        $rows = $held
            ->map(function (array $r): string {
                $blockers = $r['blockers']
                    ->take(3)
                    ->map(fn ($e): string => e($e->name).' ('.number_format($e->standing->arrears, 2).' ETB)')
                    ->implode(', ');

                return "<li class='flex flex-wrap items-baseline gap-x-2'>
                        <span class='font-medium text-gray-950 dark:text-white'>".e($r['group']->name)."</span>
                        <span class='text-xs text-gray-600 dark:text-gray-300'>".e((string) $r['reason'])."</span>"
                    .($blockers !== '' ? "<span class='text-xs text-danger-600 dark:text-danger-400'>{$blockers}</span>" : '')
                    .'</li>';
            })
            ->implode('');

        $rule = e(__('filament.lottery.held_back_rule', [
            'rounds' => $rules->blockAt(),
        ]));

        return new HtmlString($summary."
            <div class='mt-3 space-y-2 rounded-xl border border-danger-200 bg-danger-50 p-3 dark:border-danger-500/20 dark:bg-danger-500/10'>
                <p class='text-sm font-semibold text-danger-800 dark:text-danger-300'>"
                .e(__('filament.lottery.held_back_heading', ['count' => $held->count(), 'members' => $heldMembers]))."
                </p>
                <ul class='space-y-1 text-sm text-gray-700 dark:text-gray-300'>{$rows}</ul>
                <p class='text-xs text-danger-700 dark:text-danger-400'>{$rule}</p>
            </div>");
    }
}
