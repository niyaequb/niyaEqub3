<x-filament-panels::page>
    {{-- The infolist: result, fairness figures, every winner. --}}
    {{ $this->content }}

    @php
        $money = fn ($v) => number_format((float) $v, 2);
        $eligible = $this->eligibleEntries();
        $excluded = $this->excludedEntries();
        $groups = $this->snapshotGroups();
        $winners = $this->winningMembershipIds();
        $rules = $this->rulesInForce();

        $statusColour = fn (string $s) => match ($s) {
            'ahead' => 'success',
            'current' => 'primary',
            'warned' => 'warning',
            default => 'danger',
        };
    @endphp

    @if ($groups->isNotEmpty())
        {{-- ============================================================ --}}
        {{-- Group Equb round: families, not individual places            --}}
        {{-- ============================================================ --}}
        <x-filament::section
            :heading="__('filament.equb_draw.groups_in_round')"
            :description="__('filament.equb_draw.groups_in_round_description')"
            icon="heroicon-o-users"
        >
            <div class="fi-ta-ctn overflow-x-auto">
                <table class="w-full text-sm">
                    <thead class="border-b border-gray-200 text-left text-xs font-medium uppercase tracking-wide text-gray-500 dark:border-white/10 dark:text-gray-400">
                        <tr>
                            <th class="py-2 pr-3">{{ __('filament.equb_draw.group') }}</th>
                            <th class="py-2 pr-3 text-right">{{ __('filament.equb_draw.members') }}</th>
                            <th class="py-2 pr-3 text-right">{{ __('filament.equb_draw.weight') }}</th>
                            <th class="py-2 pr-3">{{ __('filament.equb_draw.verdict') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 dark:divide-white/5">
                        @foreach ($groups as $row)
                            <tr @class(['bg-success-50/60 dark:bg-success-500/10' => $row['won'] ?? false])>
                                <td class="py-2.5 pr-3">
                                    <div class="flex items-center gap-2">
                                        <span class="font-medium text-gray-950 dark:text-white">{{ $row['name'] }}</span>
                                        @if ($row['won'] ?? false)
                                            <x-filament::badge color="success" size="sm">
                                                {{ __('filament.equb_draw.won') }}
                                            </x-filament::badge>
                                        @endif
                                    </div>
                                    @if (! empty($row['blockers']))
                                        <div class="mt-1 text-xs text-danger-600 dark:text-danger-400">
                                            @foreach (collect($row['blockers'])->take(4) as $blocker)
                                                <div>
                                                    {{ $blocker['name'] }} —
                                                    {{ __('filament.equb_draw.owes', ['amount' => $money($blocker['arrears'] ?? 0)]) }}
                                                    ({{ trans_choice('filament.equb_draw.missed_rounds', (int) ($blocker['missed_rounds'] ?? 0), ['count' => (int) ($blocker['missed_rounds'] ?? 0)]) }})
                                                </div>
                                            @endforeach
                                        </div>
                                    @endif
                                </td>
                                <td class="py-2.5 pr-3 text-right tabular-nums">{{ $row['head_count'] }}</td>
                                <td class="py-2.5 pr-3 text-right tabular-nums">{{ number_format((float) ($row['weight'] ?? 0), 3) }}</td>
                                <td class="py-2.5 pr-3">
                                    @if ($row['eligible'] ?? false)
                                        <x-filament::badge color="success" size="sm">{{ __('filament.equb_draw.in_the_draw') }}</x-filament::badge>
                                    @else
                                        <span class="text-xs text-gray-600 dark:text-gray-300">{{ $row['reason'] }}</span>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </x-filament::section>
    @endif

    @if ($eligible->isNotEmpty() || $excluded->isNotEmpty())
        <div class="grid gap-6 xl:grid-cols-2">

            {{-- ======================================================== --}}
            {{-- Who was in it                                            --}}
            {{-- ======================================================== --}}
            <x-filament::section
                :heading="__('filament.equb_draw.pool')"
                :description="trans_choice('filament.equb_draw.pool_description', $eligible->count(), ['count' => number_format($eligible->count())])"
                icon="heroicon-o-scale"
                collapsible
            >
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead class="border-b border-gray-200 text-left text-xs font-medium uppercase tracking-wide text-gray-500 dark:border-white/10 dark:text-gray-400">
                            <tr>
                                <th class="py-2 pr-3">{{ __('filament.equb_draw.entrant') }}</th>
                                <th class="py-2 pr-3 text-right">{{ __('filament.equb_draw.weight') }}</th>
                                <th class="py-2 text-right">{{ __('filament.equb_draw.odds') }}</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100 dark:divide-white/5">
                            @foreach ($eligible as $entry)
                                @php $isWinner = in_array($entry['membership_id'], $winners, true); @endphp
                                <tr @class(['bg-success-50/60 dark:bg-success-500/10' => $isWinner])>
                                    <td class="py-2.5 pr-3">
                                        <div class="flex items-center gap-2">
                                            <span @class(['text-gray-950 dark:text-white', 'font-semibold' => $isWinner, 'font-medium' => ! $isWinner])>
                                                {{ $entry['name'] }}
                                            </span>
                                            @if ($isWinner)
                                                <x-filament::badge color="success" size="sm">{{ __('filament.equb_draw.won') }}</x-filament::badge>
                                            @endif
                                            @if ($entry['capped'] ?? false)
                                                {{-- Worth saying out loud: this place was scaled down
                                                     because one person holds several of them. --}}
                                                <x-filament::badge color="gray" size="sm">{{ __('filament.equb_draw.capped') }}</x-filament::badge>
                                            @endif
                                        </div>
                                        @if (! empty($entry['reasons']))
                                            <div class="mt-0.5 text-xs text-gray-500 dark:text-gray-400">
                                                {{ implode(' · ', array_slice($entry['reasons'], 0, 3)) }}
                                            </div>
                                        @endif
                                    </td>
                                    <td class="py-2.5 pr-3 text-right tabular-nums text-gray-600 dark:text-gray-300">
                                        {{ number_format((float) $entry['weight'], 3) }}
                                    </td>
                                    <td class="py-2.5 text-right tabular-nums font-medium">
                                        {{ number_format((float) $entry['odds'], 2) }}%
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>

                @if ($this->record->eligibility_snapshot['truncated'] ?? false)
                    <p class="mt-3 text-xs text-gray-500 dark:text-gray-400">
                        {{ __('filament.equb_draw.snapshot_truncated') }}
                    </p>
                @endif
            </x-filament::section>

            {{-- ======================================================== --}}
            {{-- Who was kept out, and why                                --}}
            {{-- ======================================================== --}}
            <x-filament::section
                :heading="__('filament.equb_draw.excluded')"
                :description="trans_choice('filament.equb_draw.excluded_description', $excluded->count(), ['count' => number_format($excluded->count())])"
                icon="heroicon-o-no-symbol"
                collapsible
            >
                @if ($excluded->isEmpty())
                    <p class="py-6 text-center text-sm text-gray-500 dark:text-gray-400">
                        {{ __('filament.equb_draw.nobody_excluded') }}
                    </p>
                @else
                    <div class="overflow-x-auto">
                        <table class="w-full text-sm">
                            <thead class="border-b border-gray-200 text-left text-xs font-medium uppercase tracking-wide text-gray-500 dark:border-white/10 dark:text-gray-400">
                                <tr>
                                    <th class="py-2 pr-3">{{ __('filament.equb_draw.entrant') }}</th>
                                    <th class="py-2 pr-3">{{ __('filament.equb_draw.why') }}</th>
                                    <th class="py-2 text-right">{{ __('filament.equb_draw.owed') }}</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-100 dark:divide-white/5">
                                @foreach ($excluded as $entry)
                                    <tr>
                                        <td class="py-2.5 pr-3">
                                            <div class="font-medium text-gray-950 dark:text-white">{{ $entry['name'] }}</div>
                                            <x-filament::badge :color="$statusColour($entry['status'] ?? 'blocked')" size="sm">
                                                {{ __('filament.equb_standing.' . ($entry['status'] ?? 'blocked')) }}
                                            </x-filament::badge>
                                        </td>
                                        <td class="py-2.5 pr-3 text-xs text-gray-600 dark:text-gray-300">
                                            {{ collect($entry['reasons'] ?? [])->first() }}
                                        </td>
                                        <td class="py-2.5 text-right tabular-nums {{ ($entry['arrears'] ?? 0) > 0 ? 'text-danger-600 dark:text-danger-400 font-medium' : 'text-gray-400' }}">
                                            {{ $money($entry['arrears'] ?? 0) }}
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </x-filament::section>
        </div>
    @endif

    @if ($rules !== [])
        {{-- The rules as they were on the day. A round judged against today's
             settings would be judged against rules it never ran under. --}}
        <x-filament::section
            :heading="__('filament.equb_draw.rules_in_force')"
            :description="__('filament.equb_draw.rules_in_force_description')"
            icon="heroicon-o-adjustments-horizontal"
            collapsible
            collapsed
        >
            <dl class="grid grid-cols-2 gap-x-6 gap-y-3 text-sm sm:grid-cols-3 lg:grid-cols-4">
                @foreach ($rules as $key => $value)
                    <div>
                        <dt class="text-xs uppercase tracking-wide text-gray-500 dark:text-gray-400">
                            {{ __('filament.equb_rules.' . $key) }}
                        </dt>
                        <dd class="mt-0.5 font-medium tabular-nums text-gray-950 dark:text-white">
                            {{ is_bool($value) ? ($value ? '✓' : '✗') : $value }}
                        </dd>
                    </div>
                @endforeach
            </dl>
        </x-filament::section>
    @endif
</x-filament-panels::page>
