<?php

namespace App\Filament\Pages;

use App\Filament\Resources\EqubDraws\EqubDrawResource;
use App\Models\EqubMembership;
use App\Services\Equb\EqubLotteryEngine;
use App\Services\Equb\EqubRules;
use App\Support\Equb\MembershipStanding;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Support\Facades\Auth;

/**
 * The rules the draw runs under, in one place, with their consequences shown.
 *
 * WHY THIS PAGE HAS A SIMULATOR
 *
 * A settings page full of numbers — "advance bonus per round: 0.25", "loyalty
 * cap: 0.60" — is not manageable. Nobody can tell from those figures whether
 * the punctual member now wins twice as often as the late one or barely more,
 * and the only way to find out is to run a real draw with real money and see
 * who it lands on.
 *
 * So the numbers are shown next to five members: one who pays ahead, one who
 * is always on time, one who joined last month, one a round behind and one two
 * rounds behind. Their odds recompute as the fields are edited — before
 * anything is saved — so a change is read as "the punctual member goes from
 * 24% to 31%" rather than as "a number went up".
 *
 * NOTHING HERE TAKES EFFECT UNTIL IT IS SAVED
 *
 * The preview is scored by a throwaway copy of the rules built from the form
 * state (EqubRules::overriding). The live engine is untouched until Save,
 * which writes through SettingsService so the cached copy is dropped in the
 * same breath — a saved rule that quietly does nothing for an hour is worse
 * than one that cannot be changed at all.
 */
class DrawSettings extends Page implements HasForms
{
    use InteractsWithForms;

    protected string $view = 'filament.pages.draw-settings';

    protected static ?string $slug = 'equb-draws/settings';

    protected static ?int $navigationSort = 8;

    /** @var array<string, mixed> */
    public array $data = [];

    public static function getNavigationIcon(): ?string
    {
        return 'heroicon-o-adjustments-horizontal';
    }

    public static function getNavigationLabel(): string
    {
        return __('filament.draw_settings.nav');
    }

    public function getTitle(): string
    {
        return __('filament.draw_settings.title');
    }

    public function getSubheading(): ?string
    {
        return __('filament.draw_settings.subheading');
    }

    public static function getNavigationGroup(): ?string
    {
        return __('filament.nav.equb');
    }

    // -----------------------------------------------------------------
    // Access — the same people who may run a draw may change its rules
    // -----------------------------------------------------------------

    public static function shouldRegisterNavigation(): bool
    {
        return static::canAccess();
    }

    public static function canAccess(): bool
    {
        return Auth::check() && (
            Auth::user()->hasRole('Super Admin')
            || Auth::user()->can('equb-draws.settings')
            || Auth::user()->can('equb-draws.create')
        );
    }

    public function mount(): void
    {
        $this->form->fill(app(EqubRules::class)->editable());
    }

    // -----------------------------------------------------------------
    // The form
    // -----------------------------------------------------------------

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([

                /*
                 * The fee.
                 *
                 * The one setting on this page that is about money rather
                 * than fairness, and the one most likely to be changed by
                 * somebody who has not read the rest.
                 */
                Section::make(__('filament.draw_settings.fee'))
                    ->description(__('filament.draw_settings.fee_description'))
                    ->icon('heroicon-o-receipt-percent')
                    ->columns(2)
                    ->schema([
                        TextInput::make('service_fee_percent')
                            ->label(__('filament.draw_settings.fee_percent'))
                            ->helperText(__('filament.draw_settings.fee_percent_helper'))
                            ->numeric()
                            ->suffix('%')
                            ->minValue(0)
                            ->maxValue(100)
                            ->step(0.01)
                            ->required()
                            ->live(debounce: 500),

                        Select::make('service_fee_model')
                            ->label(__('filament.draw_settings.fee_model'))
                            ->helperText(__('filament.draw_settings.fee_model_helper'))
                            ->options([
                                'deducted' => __('filament.equb_report.fee_model_deducted'),
                                'added' => __('filament.equb_report.fee_model_added'),
                                'payout' => __('filament.equb_report.fee_model_payout'),
                            ])
                            ->native(false)
                            ->required()
                            ->live(),
                    ]),

                /*
                 * Arrears.
                 *
                 * Ordered warn, block, suspend because that is the order they
                 * happen in, and each is validated against the one before it —
                 * a block threshold below the warning threshold would mean a
                 * member is removed from the draw before anyone has told them
                 * they are behind.
                 */
                Section::make(__('filament.draw_settings.arrears'))
                    ->description(__('filament.draw_settings.arrears_description'))
                    ->icon('heroicon-o-exclamation-triangle')
                    ->columns(4)
                    ->schema([
                        TextInput::make('arrears_grace_days')
                            ->label(__('filament.draw_settings.grace_days'))
                            ->helperText(__('filament.draw_settings.grace_days_helper'))
                            ->numeric()
                            ->minValue(0)
                            ->maxValue(90)
                            ->required()
                            ->live(debounce: 500),

                        TextInput::make('arrears_warn_at')
                            ->label(__('filament.draw_settings.warn_at'))
                            ->helperText(__('filament.draw_settings.warn_at_helper'))
                            ->numeric()
                            ->minValue(1)
                            ->maxValue(24)
                            ->required()
                            ->live(debounce: 500),

                        TextInput::make('arrears_block_at')
                            ->label(__('filament.draw_settings.block_at'))
                            ->helperText(__('filament.draw_settings.block_at_helper'))
                            ->numeric()
                            ->minValue(1)
                            ->maxValue(24)
                            ->required()
                            ->gte('arrears_warn_at')
                            ->validationMessages([
                                'gte' => __('filament.draw_settings.block_after_warn'),
                            ])
                            ->live(debounce: 500),

                        TextInput::make('arrears_suspend_at')
                            ->label(__('filament.draw_settings.suspend_at'))
                            ->helperText(__('filament.draw_settings.suspend_at_helper'))
                            ->numeric()
                            ->minValue(1)
                            ->maxValue(36)
                            ->required()
                            ->gte('arrears_block_at')
                            ->validationMessages([
                                'gte' => __('filament.draw_settings.suspend_after_block'),
                            ])
                            ->live(debounce: 500),
                    ]),

                /*
                 * The odds.
                 *
                 * Each bonus is a rate and a ceiling, side by side, because
                 * the ceiling is what stops one behaviour from owning the
                 * draw and reading the rate without it is misleading.
                 */
                Section::make(__('filament.draw_settings.odds'))
                    ->description(__('filament.draw_settings.odds_description'))
                    ->icon('heroicon-o-scale')
                    ->schema([
                        Grid::make(['default' => 1, 'sm' => 2, 'lg' => 4])->schema([
                            TextInput::make('lottery_advance_per_round')
                                ->label(__('filament.draw_settings.advance_per_round'))
                                ->helperText(__('filament.draw_settings.advance_per_round_helper'))
                                ->numeric()->step(0.01)->minValue(0)->maxValue(5)
                                ->required()->live(debounce: 500),

                            TextInput::make('lottery_advance_max')
                                ->label(__('filament.draw_settings.advance_max'))
                                ->helperText(__('filament.draw_settings.cap_helper'))
                                ->numeric()->step(0.01)->minValue(0)->maxValue(10)
                                ->required()->live(debounce: 500),

                            TextInput::make('lottery_streak_per_round')
                                ->label(__('filament.draw_settings.streak_per_round'))
                                ->helperText(__('filament.draw_settings.streak_per_round_helper'))
                                ->numeric()->step(0.01)->minValue(0)->maxValue(5)
                                ->required()->live(debounce: 500),

                            TextInput::make('lottery_streak_max')
                                ->label(__('filament.draw_settings.streak_max'))
                                ->helperText(__('filament.draw_settings.cap_helper'))
                                ->numeric()->step(0.01)->minValue(0)->maxValue(10)
                                ->required()->live(debounce: 500),

                            TextInput::make('lottery_loyalty_per_round')
                                ->label(__('filament.draw_settings.loyalty_per_round'))
                                ->helperText(__('filament.draw_settings.loyalty_per_round_helper'))
                                ->numeric()->step(0.01)->minValue(0)->maxValue(5)
                                ->required()->live(debounce: 500),

                            TextInput::make('lottery_loyalty_max')
                                ->label(__('filament.draw_settings.loyalty_max'))
                                ->helperText(__('filament.draw_settings.cap_helper'))
                                ->numeric()->step(0.01)->minValue(0)->maxValue(10)
                                ->required()->live(debounce: 500),

                            TextInput::make('lottery_clean_bonus')
                                ->label(__('filament.draw_settings.clean_bonus'))
                                ->helperText(__('filament.draw_settings.clean_bonus_helper'))
                                ->numeric()->step(0.01)->minValue(0)->maxValue(5)
                                ->required()->live(debounce: 500),

                            TextInput::make('lottery_late_penalty')
                                ->label(__('filament.draw_settings.late_penalty'))
                                ->helperText(__('filament.draw_settings.late_penalty_helper'))
                                ->numeric()->step(0.05)->minValue(0)->maxValue(1)
                                ->required()->live(debounce: 500),
                        ]),
                    ]),

                /*
                 * Anti-fraud.
                 *
                 * Three different shapes of abuse, and the settings that stop
                 * each one. Written as limits rather than as switches, because
                 * the right answer for a twelve-member family Equb is not the
                 * right answer for one with four thousand members.
                 */
                Section::make(__('filament.draw_settings.integrity'))
                    ->description(__('filament.draw_settings.integrity_description'))
                    ->icon('heroicon-o-shield-check')
                    ->columns(3)
                    ->schema([
                        TextInput::make('lottery_min_paid_rounds')
                            ->label(__('filament.draw_settings.min_paid_rounds'))
                            ->helperText(__('filament.draw_settings.min_paid_rounds_helper'))
                            ->numeric()->minValue(0)->maxValue(24)
                            ->required()->live(debounce: 500),

                        TextInput::make('lottery_min_days')
                            ->label(__('filament.draw_settings.min_days'))
                            ->helperText(__('filament.draw_settings.min_days_helper'))
                            ->numeric()->minValue(0)->maxValue(365)
                            ->required()->live(debounce: 500),

                        TextInput::make('lottery_max_payer_share')
                            ->label(__('filament.draw_settings.max_payer_share'))
                            ->helperText(__('filament.draw_settings.max_payer_share_helper'))
                            ->numeric()->step(0.05)->minValue(0.01)->maxValue(1)
                            ->required()->live(debounce: 500),

                        TextInput::make('lottery_group_max_arrears')
                            ->label(__('filament.draw_settings.group_max_arrears'))
                            ->helperText(__('filament.draw_settings.group_max_arrears_helper'))
                            ->numeric()->minValue(0)->maxValue(50)
                            ->required()->live(debounce: 500),

                        TextInput::make('lottery_group_min_members')
                            ->label(__('filament.draw_settings.group_min_members'))
                            ->helperText(__('filament.draw_settings.group_min_members_helper'))
                            ->numeric()->minValue(1)->maxValue(100)
                            ->required()->live(debounce: 500),
                    ]),
            ])
            ->statePath('data');
    }

    // -----------------------------------------------------------------
    // Actions
    // -----------------------------------------------------------------

    protected function getHeaderActions(): array
    {
        return [
            Action::make('save')
                ->label(__('filament.draw_settings.save'))
                ->icon('heroicon-o-check')
                ->color('primary')
                ->action('save'),

            Action::make('restoreDefaults')
                ->label(__('filament.draw_settings.restore'))
                ->icon('heroicon-o-arrow-uturn-left')
                ->color('gray')
                ->requiresConfirmation()
                ->modalHeading(__('filament.draw_settings.restore_heading'))
                ->modalDescription(__('filament.draw_settings.restore_description'))
                ->action('restoreDefaults'),

            Action::make('backToDraws')
                ->label(__('filament.draw_settings.back'))
                ->icon('heroicon-o-ticket')
                ->color('gray')
                ->url(fn (): string => EqubDrawResource::getUrl('index')),
        ];
    }

    public function save(): void
    {
        $data = $this->form->getState();

        app(EqubRules::class)->apply($data);

        Notification::make()
            ->title(__('filament.draw_settings.saved'))
            ->body(__('filament.draw_settings.saved_body'))
            ->success()
            ->send();
    }

    /**
     * Put the form back to what config ships, without saving.
     *
     * Deliberately not written straight to the database: the admin sees the
     * defaults in the form, watches the preview change, and then decides
     * whether to keep them. A reset that saves on the spot is the one action
     * on this page nobody can undo.
     */
    public function restoreDefaults(): void
    {
        $this->form->fill(app(EqubRules::class)->defaults()->editable());

        Notification::make()
            ->title(__('filament.draw_settings.restored'))
            ->body(__('filament.draw_settings.restored_body'))
            ->warning()
            ->send();
    }

    // -----------------------------------------------------------------
    // The preview
    // -----------------------------------------------------------------

    /** Rules as the form currently describes them, saved or not. */
    protected function previewRules(): EqubRules
    {
        return app(EqubRules::class)->overriding($this->data);
    }

    public function isDirty(): bool
    {
        $saved = app(EqubRules::class)->editable();

        foreach ($saved as $field => $value) {
            $current = $this->data[$field] ?? null;

            if ($current === null) {
                continue;
            }

            // Loose comparison on purpose: a form returns "0.25" where the
            // rules return 0.25, and a page that claims unsaved changes on
            // every render trains people to ignore the warning.
            if (is_numeric($value) && is_numeric($current)) {
                if (abs((float) $value - (float) $current) > 0.0001) {
                    return true;
                }

                continue;
            }

            if ((string) $value !== (string) $current) {
                return true;
            }
        }

        return false;
    }

    /**
     * What the fee rules mean on a round of a thousand birr.
     *
     * @return array<string, float|string>
     */
    public function feeExample(): array
    {
        $rules = $this->previewRules();
        $collected = 1000.0;
        $fee = $rules->feeOn($collected);

        return [
            'collected' => $collected,
            'fee' => $fee,
            'members' => round($collected - $fee, 2),
            'percent' => $rules->feePercent(),
            'model' => __('filament.equb_report.fee_model_'.$rules->feeModel()),
        ];
    }

    /**
     * Five members, scored under the rules as currently typed.
     *
     * The archetypes are fixed rather than sampled from live data. Real
     * members change between page loads, so the row that moved would be
     * ambiguous — was that the setting, or did somebody pay? Fixed archetypes
     * make every movement attributable to the change just made.
     *
     * @return array<int, array<string, mixed>>
     */
    public function previewMembers(): array
    {
        $rules = $this->previewRules();
        $engine = new EqubLotteryEngine($rules, app(\App\Services\Equb\EqubStandingService::class));
        $membership = new EqubMembership;

        $archetypes = [
            [
                'key' => 'ahead',
                'name' => __('filament.draw_settings.member_ahead'),
                'note' => __('filament.draw_settings.member_ahead_note'),
                'standing' => $this->standing(advanceRounds: 4, streak: 9, clean: true, waited: 9, missed: 0),
            ],
            [
                'key' => 'punctual',
                'name' => __('filament.draw_settings.member_punctual'),
                'note' => __('filament.draw_settings.member_punctual_note'),
                'standing' => $this->standing(advanceRounds: 0, streak: 9, clean: true, waited: 9, missed: 0),
            ],
            [
                'key' => 'new',
                'name' => __('filament.draw_settings.member_new'),
                'note' => __('filament.draw_settings.member_new_note'),
                'standing' => $this->standing(advanceRounds: 0, streak: 1, clean: true, waited: 1, missed: 0),
            ],
            [
                'key' => 'behind_one',
                'name' => __('filament.draw_settings.member_behind_one'),
                'note' => __('filament.draw_settings.member_behind_one_note'),
                'standing' => $this->standing(advanceRounds: 0, streak: 0, clean: false, waited: 9, missed: 1),
            ],
            [
                'key' => 'behind_two',
                'name' => __('filament.draw_settings.member_behind_two'),
                'note' => __('filament.draw_settings.member_behind_two_note'),
                'standing' => $this->standing(advanceRounds: 0, streak: 0, clean: false, waited: 9, missed: 2),
            ],
        ];

        // Weight first, then odds — a share is only knowable once the whole
        // pool is in, which is exactly how the real draw computes it.
        $rows = [];
        $total = 0.0;

        foreach ($archetypes as $archetype) {
            /** @var MembershipStanding $standing */
            $standing = $archetype['standing'];
            $blocked = $standing->isBlocked();
            $weight = $blocked ? 0.0 : $engine->weightFor($membership, $standing);
            $total += $weight;

            $rows[] = [
                ...$archetype,
                'status' => $standing->status,
                'blocked' => $blocked,
                'weight' => round($weight, 3),
            ];
        }

        return array_map(function (array $row) use ($total): array {
            $row['odds'] = $total > 0 ? round(($row['weight'] / $total) * 100, 1) : 0.0;
            unset($row['standing']);

            return $row;
        }, $rows);
    }

    /**
     * A standing built by hand, for the preview only.
     *
     * Nine rounds due on a monthly Equb of 1,000 ETB, which is a shape most
     * of these Equbs actually take — the absolute figures barely matter here,
     * only that every archetype is measured against the same schedule.
     */
    protected function standing(int $advanceRounds, int $streak, bool $clean, int $waited, int $missed): MembershipStanding
    {
        $contribution = 1000.0;
        $roundsDue = 9;
        $rules = $this->previewRules();

        $status = match (true) {
            $missed >= $rules->suspendAt() => MembershipStanding::SUSPENDED,
            $missed >= $rules->blockAt() => MembershipStanding::BLOCKED,
            $missed >= $rules->warnAt() => MembershipStanding::WARNED,
            $advanceRounds > 0 => MembershipStanding::AHEAD,
            default => MembershipStanding::CURRENT,
        };

        return new MembershipStanding(
            membershipId: 0,
            payerMemberId: 0,
            displayName: '',
            contribution: $contribution,
            frequencyDays: 30,
            totalRounds: 12,
            roundsDue: $roundsDue,
            joinedAt: null,
            paidAmount: ($roundsDue - $missed + $advanceRounds) * $contribution,
            paidRounds: $roundsDue - $missed + $advanceRounds,
            lastPaidAt: null,
            expectedToDate: $roundsDue * $contribution,
            arrears: $missed * $contribution,
            advanceAmount: $advanceRounds * $contribution,
            missedRounds: $missed,
            advanceRounds: $advanceRounds,
            daysOverdue: $missed * 30,
            onTimeStreak: $streak,
            lateCount: $clean ? 0 : 1,
            cleanRecord: $clean,
            roundsWaited: $waited,
            hasWon: false,
            status: $status,
        );
    }

    /**
     * The arrears rules as a sentence, because four numbers in four boxes do
     * not tell anyone what actually happens to a member who stops paying.
     */
    public function arrearsSentence(): string
    {
        $rules = $this->previewRules();

        return __('filament.draw_settings.arrears_sentence', [
            'grace' => $rules->graceDays(),
            'warn' => $rules->warnAt(),
            'block' => $rules->blockAt(),
            'suspend' => $rules->suspendAt(),
            'penalty' => (int) round($rules->latePenalty() * 100),
        ]);
    }

    /** The payer cap as a sentence, with the worked figure. */
    public function integritySentence(): string
    {
        $rules = $this->previewRules();

        return __('filament.draw_settings.integrity_sentence', [
            'share' => (int) round($rules->maxSharePerPayer() * 100),
            'rounds' => $rules->minPaidRounds(),
            'members' => $rules->groupMaxMembersInArrears(),
        ]);
    }
}
