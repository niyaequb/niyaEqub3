<?php

namespace App\Filament\Resources\MemberEqubGroups;

use App\Enums\EqubDrawType;
use App\Enums\EqubDurationType;
use App\Enums\EqubDurationUnit;
use App\Enums\EqubGroupModerationStatus;
use App\Enums\EqubGroupStatus;
use App\Enums\EqubGroupVisibility;
use App\Enums\WinnerSelectionMode;
use App\Models\EqubGroup;
use App\Models\EqubPackage;
use App\Models\Member;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Illuminate\Support\HtmlString;

/**
 * Admin-side creation of a Group Equb.
 *
 * Nothing about money is typed in here: the contribution per person comes from
 * the parent Equb, and the totals follow the head-count. Winner selection is not
 * here either — draws are run from the Equb Draws page.
 */
class MemberEqubGroupForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('parent_equb_group_id')
                ->label(__('filament.member_equb_group.parent_group'))
                ->options(fn (): array => static::parentOptions())
                ->searchable()
                ->preload()
                ->required()
                ->live()
                ->native(false)
                ->helperText(__('filament.member_equb_group.parent_group_helper'))
                // Nothing suitable in the list? Build a new Equb from scratch
                // without leaving this page.
                ->createOptionModalHeading(__('filament.member_equb_group.new_equb_heading'))
                ->createOptionForm([
                    TextInput::make('name')
                        ->label(__('filament.member_equb_group.new_equb_name'))
                        ->required()
                        ->maxLength(191),

                    Select::make('equb_package_id')
                        ->label(__('filament.member_equb_group.package'))
                        ->options(fn (): array => EqubPackage::query()
                            ->where('is_active', true)
                            ->pluck('name', 'id')
                            ->toArray())
                        ->searchable()
                        ->preload()
                        ->native(false)
                        ->live()
                        ->afterStateUpdated(function ($state, $set): void {
                            $package = $state ? EqubPackage::find($state) : null;

                            if ($package) {
                                $set('fixed_contribution_amount', $package->fixed_contribution_amount);
                                $set('contribution_frequency_days', $package->contribution_frequency_days);
                            }
                        })
                        ->helperText(__('filament.member_equb_group.new_equb_package_helper')),

                    TextInput::make('fixed_contribution_amount')
                        ->label(__('filament.member_equb_group.new_equb_amount'))
                        ->numeric()
                        ->minValue(1)
                        ->required()
                        ->helperText(__('filament.member_equb_group.new_equb_amount_helper')),

                    TextInput::make('contribution_frequency_days')
                        ->label(__('filament.member_equb_group.new_equb_frequency'))
                        ->numeric()
                        ->minValue(1)
                        ->default(30)
                        ->required(),

                    TextInput::make('duration_value')
                        ->label(__('filament.member_equb_group.new_equb_rounds'))
                        ->numeric()
                        ->minValue(1)
                        ->default(12)
                        ->required()
                        ->helperText(__('filament.member_equb_group.new_equb_rounds_helper')),
                ])
                ->createOptionUsing(fn (array $data): int => static::createParent($data)),

            TextInput::make('name')
                ->label(__('filament.member_equb_group.group_name'))
                ->placeholder(__('filament.member_equb_group.group_name_placeholder'))
                ->required()
                ->maxLength(191)
                ->helperText(__('filament.member_equb_group.group_name_helper')),

            Select::make('owner_member_id')
                ->label(__('filament.member_equb_group.owner'))
                ->searchable()
                ->native(false)
                ->required()
                ->getSearchResultsUsing(fn (string $search): array => static::searchMembers($search))
                ->getOptionLabelUsing(fn ($value): ?string => static::memberLabel($value))
                ->helperText(__('filament.member_equb_group.owner_helper')),

            Select::make('member_ids')
                ->label(__('filament.member_equb_group.add_members'))
                ->multiple()
                ->searchable()
                ->native(false)
                ->dehydrated(true)
                ->getSearchResultsUsing(fn (string $search): array => static::searchMembers($search))
                ->getOptionLabelsUsing(fn (array $values): array => collect($values)
                    ->mapWithKeys(fn ($id): array => [$id => static::memberLabel($id) ?? "#{$id}"])
                    ->toArray())
                ->live()
                ->helperText(__('filament.member_equb_group.add_members_helper')),

            Textarea::make('description')
                ->label(__('filament.member_equb_group.description'))
                ->rows(2)
                ->maxLength(500)
                ->columnSpanFull(),

            // Money, derived. Never an input.
            Placeholder::make('money_preview')
                ->hiddenLabel()
                ->columnSpanFull()
                ->content(fn (Get $get): HtmlString => static::moneyPanel($get)),

            Toggle::make('allow_member_invites')
                ->label(__('filament.member_equb_group.allow_invites'))
                ->default(false)
                ->columnSpanFull(),
        ])->columns(2);
    }

    /**
     * Parent Equbs a group can join. Matches MemberEqubGroupService: anything
     * except a finished or cancelled Equb.
     *
     * @return array<int, string>
     */
    protected static function parentOptions(): array
    {
        return EqubGroup::query()
            ->whereNull('owner_member_id')
            ->whereNotIn('status', [
                EqubGroupStatus::Completed->value,
                EqubGroupStatus::Cancelled->value,
            ])
            ->orderBy('name')
            ->get(['id', 'name', 'status', 'fixed_contribution_amount'])
            ->mapWithKeys(fn (EqubGroup $g): array => [
                $g->id => $g->name
                    .' · '.number_format((float) $g->fixed_contribution_amount, 2).' ETB'
                    .' · '.($g->status?->value ?? ''),
            ])
            ->toArray();
    }

    /** Build a brand-new platform Equb inline. */
    protected static function createParent(array $data): int
    {
        $package = ! empty($data['equb_package_id'])
            ? EqubPackage::find($data['equb_package_id'])
            : null;

        return EqubGroup::create([
            'equb_package_id' => $package?->id,
            'name' => $data['name'],
            'visibility' => EqubGroupVisibility::Public,
            'moderation_status' => EqubGroupModerationStatus::Approved,
            'approved_at' => now(),
            'fixed_contribution_amount' => $data['fixed_contribution_amount'],
            'contribution_frequency_days' => $data['contribution_frequency_days'],
            'duration_type' => $package?->duration_type ?? EqubDurationType::Fixed,
            'duration_value' => $data['duration_value'],
            'duration_unit' => $package?->duration_unit ?? EqubDurationUnit::Days,
            'terms_content' => $package?->terms_content,
            'registration_open_at' => now(),
            'status' => EqubGroupStatus::Registration,
            'draw_type' => EqubDrawType::Manual,
            'winner_selection_mode' => WinnerSelectionMode::Manual,
            'current_members_count' => 0,
        ])->id;
    }

    protected static function moneyPanel(Get $get): HtmlString
    {
        $parent = EqubGroup::with('package')->find($get('parent_equb_group_id'));

        if (! $parent) {
            return new HtmlString(
                "<p class='text-sm text-gray-500 dark:text-gray-400'>"
                .e(__('filament.member_equb_group.pick_parent_first')).'</p>'
            );
        }

        $perPerson = $parent->contributionPerPerson();
        // The owner counts as a member, plus everyone being invited.
        $heads = 1 + count((array) $get('member_ids'));
        $rounds = max(1, $parent->totalRounds());

        $roundTotal = $perPerson * $heads;
        $fullTotal = $roundTotal * $rounds;

        $f = fn (float $v): string => number_format($v, 2);

        $cell = fn (string $label, string $value, string $tone = 'text-gray-900 dark:text-white'): string => "
            <div>
                <span class='text-xs uppercase tracking-wider text-gray-500 dark:text-gray-400'>"
                    .e($label)."</span>
                <p class='mt-1 text-lg font-bold {$tone}'>{$value}</p>
            </div>";

        return new HtmlString("
            <div class='grid grid-cols-2 gap-4 rounded-xl border border-gray-200 bg-gray-50 p-4 sm:grid-cols-4 dark:border-gray-700 dark:bg-gray-800/50'>
                ".$cell(__('filament.member_equb_group.per_person'), $f($perPerson).' ETB')."
                ".$cell(__('filament.member_equb_group.members_count'), (string) $heads)."
                ".$cell(__('filament.member_equb_group.round_total'), $f($roundTotal).' ETB', 'text-primary-600 dark:text-primary-400')."
                ".$cell(__('filament.member_equb_group.full_total')." ({$rounds})", $f($fullTotal).' ETB')."
            </div>
        ");
    }

    /**
     * Partial match on phone or name — the same behaviour the app's add-member
     * autocomplete uses.
     *
     * @return array<int, string>
     */
    protected static function searchMembers(string $search): array
    {
        $search = trim($search);

        if ($search === '') {
            return [];
        }

        return Member::query()
            ->with('user:id,phone,name')
            ->where(function ($q) use ($search) {
                $q->whereHas('user', fn ($u) => $u
                    ->where('phone', 'like', "%{$search}%")
                    ->orWhere('name', 'like', "%{$search}%"))
                    ->orWhere('full_name', 'like', "%{$search}%");
            })
            ->limit(40)
            ->get()
            ->mapWithKeys(fn (Member $m): array => [$m->id => static::label($m)])
            ->toArray();
    }

    protected static function memberLabel($value): ?string
    {
        $member = Member::with('user:id,phone,name')->find($value);

        return $member ? static::label($member) : null;
    }

    protected static function label(Member $member): string
    {
        $phone = $member->user?->phone ?? '—';
        $name = $member->full_name ?? $member->user?->name ?? 'Member';

        return trim("{$name} — {$phone}");
    }
}
