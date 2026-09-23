<x-filament-panels::page>
    @php
        $money = fn ($v) => number_format((float) $v, 2);
        $tiles = $this->tiles();
        $bands = $this->bands();
        $debtors = $this->debtors();
        $receivables = $this->receivables();
        $meta = $this->report()['meta'];
        $activePeriod = $this->filters['period'] ?? 'daily';
        $narrowed = $this->bucket !== null || $this->onlyNeverPaid;

        $accent = fn (string $a) => match ($a) {
            'success' => 'text-success-600 dark:text-success-400',
            'warning' => 'text-warning-600 dark:text-warning-400',
            'danger' => 'text-danger-600 dark:text-danger-400',
            'primary' => 'text-primary-600 dark:text-primary-400',
            default => 'text-gray-950 dark:text-white',
        };

        $statusColour = fn (string $s) => match ($s) {
            'current' => 'primary',
            'warned' => 'warning',
            default => 'danger',
        };
    @endphp

    <div class="space-y-6">

        {{-- ============================================================ --}}
        {{-- Where this came from, and the moment it is measured at       --}}
        {{-- ============================================================ --}}
        <div class="flex flex-wrap items-center justify-between gap-3">
            <a
                href="{{ $this->parentUrl() }}"
                class="inline-flex items-center gap-1.5 text-sm font-medium text-gray-500 transition hover:text-primary-600 dark:text-gray-400 dark:hover:text-primary-400"
            >
                <x-filament::icon icon="heroicon-m-arrow-left" class="h-4 w-4" />
                {{ __('filament.equb_report.back_to_report') }}
            </a>

            <div class="fi-tabs flex gap-1 rounded-xl bg-gray-100 p-1 dark:bg-white/5">
                @foreach ($this->periods() as $key => $label)
                    <button
                        type="button"
                        wire:click="setPeriod('{{ $key }}')"
                        @class([
                            'rounded-lg px-3.5 py-1.5 text-sm font-medium transition',
                            'bg-white text-primary-600 shadow-sm dark:bg-gray-800 dark:text-primary-400' => $activePeriod === $key,
                            'text-gray-500 hover:text-gray-700 dark:text-gray-400 dark:hover:text-gray-200' => $activePeriod !== $key,
                        ])
                    >
                        {{ $label }}
                    </button>
                @endforeach
            </div>
        </div>

        <div class="rounded-xl border border-gray-200 bg-gray-50 px-4 py-3 text-sm text-gray-700 dark:border-white/10 dark:bg-white/5 dark:text-gray-300">
            {{ $this->explainer() }}
        </div>

        {{-- ============================================================ --}}
        {{-- Headline position                                            --}}
        {{-- ============================================================ --}}
        <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
            @foreach ($tiles as $tile)
                <x-filament::section class="!p-0">
                    <div class="p-4">
                        <div class="flex items-start justify-between gap-2">
                            <p class="text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">{{ $tile['label'] }}</p>
                            <x-filament::icon :icon="$tile['icon']" class="h-4 w-4 text-gray-400 dark:text-gray-500" />
                        </div>
                        <p class="mt-1.5 text-2xl font-semibold tracking-tight tabular-nums {{ $accent($tile['accent']) }}">
                            {{ $tile['value'] }}
                        </p>
                        <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">{{ $tile['sub'] }}</p>
                    </div>
                </x-filament::section>
            @endforeach
        </div>

        {{-- ============================================================ --}}
        {{-- Ageing — and the filter for the worklist below               --}}
        {{-- ============================================================ --}}
        <x-filament::section
            :heading="__('filament.equb_report.ageing')"
            :description="__('filament.equb_report.ageing_description')"
            icon="heroicon-o-clock"
        >
            <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                @foreach ($bands as $band)
                    {{-- Each band is a button. Somebody working through the
                         book wants "show me the 90-day accounts", not a chart
                         they then have to translate into a filter. --}}
                    <button
                        type="button"
                        wire:click="selectBand('{{ $band['key'] }}')"
                        @class([
                            'rounded-xl border p-3 text-left transition',
                            'border-primary-500 bg-primary-50 dark:border-primary-400 dark:bg-primary-500/10' => $this->bucket === $band['key'],
                            'border-gray-200 hover:border-gray-300 hover:bg-gray-50 dark:border-white/10 dark:hover:border-white/20 dark:hover:bg-white/5' => $this->bucket !== $band['key'],
                        ])
                    >
                        <p class="text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">{{ $band['label'] }}</p>
                        <p class="mt-1 text-lg font-semibold tabular-nums {{ $band['severity'] === 'danger' ? 'text-danger-600 dark:text-danger-400' : 'text-warning-600 dark:text-warning-400' }}">
                            {{ $money($band['amount']) }}
                        </p>
                        <div class="mt-1.5 flex items-center justify-between text-xs text-gray-500 dark:text-gray-400">
                            <span>{{ trans_choice('filament.equb_report.accounts', $band['count'], ['count' => number_format($band['count'])]) }}</span>
                            <span class="tabular-nums">{{ number_format($band['share'], 1) }}%</span>
                        </div>
                        <div class="mt-2 h-1.5 w-full overflow-hidden rounded-full bg-gray-100 dark:bg-white/10">
                            <div
                                class="h-full rounded-full {{ $band['severity'] === 'danger' ? 'bg-danger-500' : 'bg-warning-500' }}"
                                style="width: {{ min(100, $band['share']) }}%"
                            ></div>
                        </div>
                    </button>
                @endforeach
            </div>

            <div class="mt-4 flex flex-wrap items-center gap-2">
                <button
                    type="button"
                    wire:click="toggleNeverPaid"
                    @class([
                        'inline-flex items-center gap-1.5 rounded-lg px-3 py-1.5 text-sm font-medium transition',
                        'bg-danger-600 text-white' => $this->onlyNeverPaid,
                        'bg-danger-50 text-danger-700 hover:bg-danger-100 dark:bg-danger-500/10 dark:text-danger-400' => ! $this->onlyNeverPaid,
                    ])
                >
                    <x-filament::icon icon="heroicon-m-user-minus" class="h-4 w-4" />
                    {{ __('filament.equb_report.show_never_paid', ['count' => number_format($receivables['never_paid_count'])]) }}
                </button>

                @if ($narrowed)
                    <button
                        type="button"
                        wire:click="clearNarrowing"
                        class="inline-flex items-center gap-1 text-sm font-medium text-gray-500 hover:text-danger-600 dark:text-gray-400"
                    >
                        <x-filament::icon icon="heroicon-m-x-mark" class="h-4 w-4" />
                        {{ __('filament.equb_report.show_all_debtors') }}
                    </button>
                @endif
            </div>
        </x-filament::section>

        {{-- ============================================================ --}}
        {{-- The worklist                                                 --}}
        {{-- ============================================================ --}}
        <x-filament::section
            :heading="__('filament.equb_report.who_owes')"
            :description="trans_choice('filament.equb_report.who_owes_description', $debtors->count(), [
                'count' => number_format($debtors->count()),
                'total' => number_format($receivables['members_in_arrears']),
            ])"
            icon="heroicon-o-phone-arrow-up-right"
        >
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead class="border-b border-gray-200 text-left text-xs font-medium uppercase tracking-wide text-gray-500 dark:border-white/10 dark:text-gray-400">
                        <tr>
                            <th class="py-2 pr-3">{{ __('filament.equb_report.member') }}</th>
                            <th class="py-2 pr-3">{{ __('filament.equb_report.equb_group') }}</th>
                            <th class="py-2 pr-3 text-right">{{ __('filament.equb_report.rounds') }}</th>
                            <th class="py-2 pr-3 text-right">{{ __('filament.equb_report.paid') }}</th>
                            <th class="py-2 pr-3 text-right">{{ __('filament.equb_report.outstanding') }}</th>
                            <th class="py-2 pr-3 text-right">{{ __('filament.equb_report.days_overdue') }}</th>
                            <th class="py-2 pr-3">{{ __('filament.equb_report.status') }}</th>
                            <th class="py-2">{{ __('filament.equb_report.last_payment') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 dark:divide-white/5">
                        @forelse ($debtors as $row)
                            <tr @class(['bg-danger-50/60 dark:bg-danger-500/5' => $row['critical']])>
                                <td class="py-2.5 pr-3">
                                    <div class="flex items-center gap-2">
                                        <span class="font-medium text-gray-950 dark:text-white">{{ $row['name'] }}</span>
                                        @if ($row['critical'])
                                            {{-- Already collected a payout and stopped paying. The
                                                 money is gone; this row is the only route back. --}}
                                            <x-filament::badge color="danger" size="sm">
                                                {{ __('filament.equb_report.won_and_owes') }}
                                            </x-filament::badge>
                                        @endif
                                        @if ($row['admin_blocked'])
                                            <x-filament::badge color="gray" size="sm">
                                                {{ __('filament.equb_report.blocked_by_admin') }}
                                            </x-filament::badge>
                                        @endif
                                    </div>
                                    <div class="text-xs text-gray-500 dark:text-gray-400">
                                        {{ $row['phone'] ?: '—' }}
                                        @if ($row['held_for'])
                                            · {{ __('filament.equb_report.holding_place_for', ['name' => $row['held_for']]) }}
                                        @endif
                                    </div>
                                </td>
                                <td class="py-2.5 pr-3">
                                    <div class="text-gray-600 dark:text-gray-300">{{ $row['equb'] }}</div>
                                    @if ($row['group'])
                                        <div class="text-xs text-gray-500 dark:text-gray-400">{{ $row['group'] }}</div>
                                    @endif
                                </td>
                                <td class="py-2.5 pr-3 text-right tabular-nums text-gray-600 dark:text-gray-300">
                                    {{ $row['rounds_paid'] }} / {{ $row['rounds_due'] }}
                                    <div class="text-xs text-gray-500 dark:text-gray-400">
                                        {{ trans_choice('filament.equb_report.missed', $row['missed_rounds'], ['count' => $row['missed_rounds']]) }}
                                    </div>
                                </td>
                                <td class="py-2.5 pr-3 text-right tabular-nums text-success-600 dark:text-success-400">{{ $money($row['paid']) }}</td>
                                <td class="py-2.5 pr-3 text-right font-semibold tabular-nums text-danger-600 dark:text-danger-400">{{ $money($row['arrears']) }}</td>
                                <td class="py-2.5 pr-3 text-right tabular-nums text-gray-600 dark:text-gray-300">{{ number_format($row['days_overdue']) }}</td>
                                <td class="py-2.5 pr-3">
                                    <x-filament::badge :color="$statusColour($row['status'])" size="sm">
                                        {{ __('filament.equb_standing.' . $row['status']) }}
                                    </x-filament::badge>
                                </td>
                                <td class="py-2.5 text-xs text-gray-500 dark:text-gray-400">
                                    {{ $row['last_paid_at'] ? \Illuminate\Support\Carbon::parse($row['last_paid_at'])->format('d M Y') : __('filament.equb_report.never') }}
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="8" class="py-12 text-center text-gray-500 dark:text-gray-400">
                                    {{ __('filament.equb_report.nobody_owes') }}
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            @if ($receivables['members_in_arrears'] > $debtors->count())
                <p class="mt-3 text-xs text-gray-500 dark:text-gray-400">
                    {{ __('filament.equb_report.debtors_truncated', [
                        'shown' => number_format($debtors->count()),
                        'total' => number_format($receivables['members_in_arrears']),
                    ]) }}
                </p>
            @endif
        </x-filament::section>

        {{-- ============================================================ --}}
        {{-- Rolled up                                                    --}}
        {{-- ============================================================ --}}
        @foreach ($this->panels() as $panel)
            <x-filament::section
                :heading="$panel['heading']"
                :description="$panel['description'] ?? null"
                :icon="$panel['icon'] ?? null"
                :collapsible="$panel['collapsible'] ?? false"
            >
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead class="border-b border-gray-200 text-left text-xs font-medium uppercase tracking-wide text-gray-500 dark:border-white/10 dark:text-gray-400">
                            <tr>
                                @foreach ($panel['columns'] as $column)
                                    <th @class(['whitespace-nowrap py-2 pr-3', 'text-right' => ($column['align'] ?? 'left') === 'right'])>
                                        {{ $column['label'] }}
                                    </th>
                                @endforeach
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100 dark:divide-white/5">
                            @forelse ($panel['rows'] as $row)
                                <tr>
                                    @foreach ($panel['columns'] as $column)
                                        @php $value = $row[$column['key']] ?? null; @endphp
                                        <td @class(['py-2.5 pr-3', 'text-right tabular-nums' => ($column['align'] ?? 'left') === 'right'])>
                                            <span @class([
                                                'font-medium text-gray-950 dark:text-white' => $column['strong'] ?? false,
                                                'font-medium text-danger-600 dark:text-danger-400' => ($column['danger'] ?? false) && (float) $value > 0,
                                            ])>
                                                @switch($column['type'] ?? 'text')
                                                    @case('money') {{ $money($value) }} @break
                                                    @case('number') {{ number_format((float) $value) }} @break
                                                    @case('percent') {{ number_format((float) $value, 1) }}% @break
                                                    @default {{ $value }}
                                                @endswitch
                                            </span>
                                        </td>
                                    @endforeach
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="{{ count($panel['columns']) }}" class="py-10 text-center text-gray-500 dark:text-gray-400">
                                        {{ __('filament.equb_report.no_data') }}
                                    </td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </x-filament::section>
        @endforeach
    </div>
</x-filament-panels::page>
