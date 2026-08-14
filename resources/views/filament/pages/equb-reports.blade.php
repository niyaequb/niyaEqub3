<x-filament-panels::page>
    @php
        $report = $this->report();
        $meta = $report['meta'];
        $summary = $report['summary'];
        $previous = $report['previous'];
        $growth = $report['growth'];
        $fingerprint = $this->filtersFingerprint();

        $money = fn ($v) => number_format((float) $v, 2);

        // Growth badge. null means "no baseline" — the previous period had
        // nothing to compare against, so a percentage would be invented.
        $trend = function (?float $value, bool $higherIsBetter = true): array {
            if ($value === null) {
                return ['label' => __('filament.equb_report.new_activity'), 'class' => 'text-primary-600 dark:text-primary-400', 'icon' => 'heroicon-m-sparkles'];
            }
            if (abs($value) < 0.05) {
                return ['label' => __('filament.equb_report.no_change'), 'class' => 'text-gray-500 dark:text-gray-400', 'icon' => 'heroicon-m-minus-small'];
            }
            $up = $value > 0;
            $good = $higherIsBetter ? $up : ! $up;
            return [
                'label' => ($up ? '+' : '') . number_format($value, 1) . '%',
                'class' => $good ? 'text-success-600 dark:text-success-400' : 'text-danger-600 dark:text-danger-400',
                'icon' => $up ? 'heroicon-m-arrow-trending-up' : 'heroicon-m-arrow-trending-down',
            ];
        };

        $periods = [
            'daily' => __('filament.equb_report.period_daily'),
            'weekly' => __('filament.equb_report.period_weekly'),
            'monthly' => __('filament.equb_report.period_monthly'),
            'custom' => __('filament.equb_report.period_custom'),
        ];
        $activePeriod = $this->filters['period'] ?? 'daily';
    @endphp

    <div class="space-y-6">

        {{-- ============================================================ --}}
        {{-- Period switcher                                              --}}
        {{-- ============================================================ --}}
        <div class="flex flex-wrap items-center justify-between gap-3">
            <div class="fi-tabs flex gap-1 rounded-xl bg-gray-100 p-1 dark:bg-white/5">
                @foreach ($periods as $key => $label)
                    <button
                        type="button"
                        wire:click="setPeriod('{{ $key }}')"
                        @class([
                            'rounded-lg px-4 py-2 text-sm font-medium transition',
                            'bg-white text-primary-600 shadow-sm dark:bg-gray-800 dark:text-primary-400' => $activePeriod === $key,
                            'text-gray-500 hover:text-gray-700 dark:text-gray-400 dark:hover:text-gray-200' => $activePeriod !== $key,
                        ])
                    >
                        {{ $label }}
                    </button>
                @endforeach
            </div>

            <div class="flex items-center gap-3">
                @if ($meta['has_filters'])
                    <button
                        type="button"
                        wire:click="resetFilters"
                        class="inline-flex items-center gap-1 text-sm font-medium text-gray-500 hover:text-danger-600 dark:text-gray-400"
                    >
                        <x-filament::icon icon="heroicon-m-x-mark" class="h-4 w-4" />
                        {{ __('filament.equb_report.clear_filters') }}
                    </button>
                @endif
                <span class="text-sm text-gray-500 dark:text-gray-400" wire:loading.class="opacity-100" wire:loading.remove.class="opacity-0">
                    <span wire:loading wire:target="filters,setPeriod,resetFilters">{{ __('filament.equb_report.updating') }}…</span>
                </span>
            </div>
        </div>

        {{-- ============================================================ --}}
        {{-- Filters                                                      --}}
        {{-- ============================================================ --}}
        <x-filament::section
            :heading="__('filament.equb_report.filters')"
            icon="heroicon-o-funnel"
            collapsible
        >
            {{ $this->filtersForm }}
        </x-filament::section>

        {{-- ============================================================ --}}
        {{-- Headline figures                                             --}}
        {{-- ============================================================ --}}
        @php
            $cards = [
                [
                    'label' => __('filament.equb_report.collected'),
                    'value' => $money($summary['collected']) . ' ETB',
                    'trend' => $trend($growth['collected']),
                    'hint' => __('filament.equb_report.vs_previous') . ': ' . $money($previous['collected']) . ' ETB',
                    'accent' => 'text-success-600 dark:text-success-400',
                ],
                [
                    'label' => __('filament.equb_report.outstanding'),
                    'value' => $money($summary['outstanding']) . ' ETB',
                    // Rising unpaid balances are bad news, so the arrow colour flips.
                    'trend' => $trend($growth['outstanding'], higherIsBetter: false),
                    'hint' => number_format($summary['pending_count']) . ' ' . __('filament.equb_report.pending_payments'),
                    'accent' => 'text-warning-600 dark:text-warning-400',
                ],
                [
                    'label' => __('filament.equb_report.transactions'),
                    'value' => number_format($summary['transactions']),
                    'trend' => $trend($growth['transactions']),
                    'hint' => number_format($summary['paid_count']) . ' ' . __('filament.equb_report.settled')
                        . ' · ' . number_format($summary['failed_count']) . ' ' . __('filament.equb_report.failed'),
                    'accent' => 'text-gray-950 dark:text-white',
                ],
                [
                    'label' => __('filament.equb_report.paying_members'),
                    'value' => number_format($summary['members']),
                    'trend' => $trend($growth['members']),
                    'hint' => number_format($summary['groups']) . ' ' . __('filament.equb_report.active_groups'),
                    'accent' => 'text-gray-950 dark:text-white',
                ],
                [
                    'label' => __('filament.equb_report.average_payment'),
                    'value' => $money($summary['average_payment']) . ' ETB',
                    'trend' => $trend($growth['average_payment']),
                    'hint' => __('filament.equb_report.per_settled_payment'),
                    'accent' => 'text-gray-950 dark:text-white',
                ],
            ];
        @endphp

        <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-5">
            @foreach ($cards as $card)
                <x-filament::section class="!p-0">
                    <div class="p-4">
                        <p class="text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">
                            {{ $card['label'] }}
                        </p>
                        <p class="mt-1 text-xl font-semibold tracking-tight tabular-nums {{ $card['accent'] }}">
                            {{ $card['value'] }}
                        </p>
                        <p class="mt-1 flex items-center gap-1 text-xs font-medium {{ $card['trend']['class'] }}">
                            <x-filament::icon :icon="$card['trend']['icon']" class="h-3.5 w-3.5" />
                            {{ $card['trend']['label'] }}
                        </p>
                        <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">{{ $card['hint'] }}</p>
                    </div>
                </x-filament::section>
            @endforeach
        </div>

        {{-- Active filter summary, so the numbers above are never ambiguous. --}}
        @if ($meta['has_filters'])
            <div class="rounded-lg border border-primary-200 bg-primary-50 px-4 py-2.5 text-sm dark:border-primary-500/20 dark:bg-primary-500/10">
                <span class="font-semibold text-primary-800 dark:text-primary-300">
                    {{ __('filament.equb_report.filters_applied') }}:
                </span>
                <span class="text-primary-700 dark:text-primary-200">
                    {{ implode('  •  ', $meta['filters']) }}
                </span>
            </div>
        @endif

        {{-- ============================================================ --}}
        {{-- Charts                                                       --}}
        {{-- ============================================================ --}}
        {{--
            Keyed on the filter fingerprint: when a filter changes the key
            changes, Livewire remounts the chart and it redraws against the
            new window. Reactive props alone can leave a stale canvas under a
            fresh heading, which is the one failure mode worth paying a
            re-render to avoid.
        --}}
        <div wire:key="chart-trend-{{ $fingerprint }}">
            @livewire(\App\Filament\Widgets\Reports\RevenueTrendChart::class, ['pageFilters' => $this->filters], key('trend-' . $fingerprint))
        </div>

        <div class="grid gap-4 lg:grid-cols-2">
            <div wire:key="chart-type-{{ $fingerprint }}">
                @livewire(\App\Filament\Widgets\Reports\EqubTypeChart::class, ['pageFilters' => $this->filters], key('type-' . $fingerprint))
            </div>
            <div wire:key="chart-status-{{ $fingerprint }}">
                @livewire(\App\Filament\Widgets\Reports\PaymentStatusChart::class, ['pageFilters' => $this->filters], key('status-' . $fingerprint))
            </div>
        </div>

        @if ($report['by_group']->isNotEmpty())
            <div wire:key="chart-groups-{{ $fingerprint }}">
                @livewire(\App\Filament\Widgets\Reports\TopGroupsChart::class, ['pageFilters' => $this->filters], key('groups-' . $fingerprint))
            </div>
        @endif

        {{-- ============================================================ --}}
        {{-- Breakdown tables                                             --}}
        {{-- ============================================================ --}}
        <div class="grid gap-4 lg:grid-cols-2">

            {{-- By Equb group --}}
            <x-filament::section :heading="__('filament.equb_report.by_group')" icon="heroicon-o-user-group">
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead class="border-b border-gray-200 text-left text-xs uppercase tracking-wide text-gray-500 dark:border-gray-700 dark:text-gray-400">
                            <tr>
                                <th class="py-2 pr-3 font-medium">{{ __('filament.equb_report.equb_group') }}</th>
                                <th class="py-2 pr-3 text-right font-medium">{{ __('filament.equb_report.collected') }}</th>
                                <th class="py-2 text-right font-medium">{{ __('filament.equb_report.outstanding') }}</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                            @forelse ($report['by_group'] as $row)
                                <tr>
                                    <td class="py-2.5 pr-3">
                                        <div class="font-medium text-gray-950 dark:text-white">{{ $row['label'] }}</div>
                                        <div class="text-xs text-gray-500 dark:text-gray-400">
                                            {{ $row['members'] }} {{ __('filament.equb_report.members') }} ·
                                            {{ $row['transactions'] }} {{ __('filament.equb_report.count') }}
                                        </div>
                                    </td>
                                    <td class="py-2.5 pr-3 text-right tabular-nums font-medium">{{ $money($row['collected']) }}</td>
                                    <td class="py-2.5 text-right tabular-nums {{ $row['outstanding'] > 0 ? 'text-warning-600 dark:text-warning-400' : 'text-gray-400' }}">
                                        {{ $money($row['outstanding']) }}
                                    </td>
                                </tr>
                            @empty
                                <tr><td colspan="3" class="py-8 text-center text-gray-500 dark:text-gray-400">{{ __('filament.equb_report.no_data') }}</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </x-filament::section>

            {{-- By Group Equb — member-created family groups, kept distinct
                 from the platform Equb rollup above. --}}
            <x-filament::section
                :heading="__('filament.equb_report.by_group_equb')"
                :description="__('filament.equb_report.by_group_equb_description')"
                icon="heroicon-o-users"
            >
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead class="border-b border-gray-200 text-left text-xs uppercase tracking-wide text-gray-500 dark:border-gray-700 dark:text-gray-400">
                            <tr>
                                <th class="py-2 pr-3 font-medium">{{ __('filament.equb_report.group_equb') }}</th>
                                <th class="py-2 pr-3 text-right font-medium">{{ __('filament.equb_report.collected') }}</th>
                                <th class="py-2 text-right font-medium">{{ __('filament.equb_report.outstanding') }}</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                            @forelse ($report['by_group_equb'] as $row)
                                <tr>
                                    <td class="py-2.5 pr-3">
                                        <div class="font-medium text-gray-950 dark:text-white">{{ $row['label'] }}</div>
                                        <div class="text-xs text-gray-500 dark:text-gray-400">
                                            {{ __('filament.equb_report.inside_equb') }}: {{ $row['parent'] }} ·
                                            {{ $row['members'] }} {{ __('filament.equb_report.members') }} ·
                                            {{ $row['transactions'] }} {{ __('filament.equb_report.count') }}
                                        </div>
                                    </td>
                                    <td class="py-2.5 pr-3 text-right tabular-nums font-medium">{{ $money($row['collected']) }}</td>
                                    <td class="py-2.5 text-right tabular-nums {{ $row['outstanding'] > 0 ? 'text-warning-600 dark:text-warning-400' : 'text-gray-400' }}">
                                        {{ $money($row['outstanding']) }}
                                    </td>
                                </tr>
                            @empty
                                <tr><td colspan="3" class="py-8 text-center text-gray-500 dark:text-gray-400">{{ __('filament.equb_report.no_group_equbs') }}</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </x-filament::section>

            {{-- By package --}}
            <x-filament::section :heading="__('filament.equb_report.by_package')" icon="heroicon-o-archive-box">
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead class="border-b border-gray-200 text-left text-xs uppercase tracking-wide text-gray-500 dark:border-gray-700 dark:text-gray-400">
                            <tr>
                                <th class="py-2 pr-3 font-medium">{{ __('filament.equb_report.package') }}</th>
                                <th class="py-2 pr-3 text-right font-medium">{{ __('filament.equb_report.collected') }}</th>
                                <th class="py-2 text-right font-medium">{{ __('filament.equb_report.count') }}</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                            @forelse ($report['by_package'] as $row)
                                <tr>
                                    <td class="py-2.5 pr-3 font-medium text-gray-950 dark:text-white">{{ $row['label'] }}</td>
                                    <td class="py-2.5 pr-3 text-right tabular-nums">{{ $money($row['collected']) }}</td>
                                    <td class="py-2.5 text-right tabular-nums">{{ number_format($row['transactions']) }}</td>
                                </tr>
                            @empty
                                <tr><td colspan="3" class="py-8 text-center text-gray-500 dark:text-gray-400">{{ __('filament.equb_report.no_data') }}</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </x-filament::section>

            {{-- Top members --}}
            <x-filament::section :heading="__('filament.equb_report.top_members')" icon="heroicon-o-trophy">
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead class="border-b border-gray-200 text-left text-xs uppercase tracking-wide text-gray-500 dark:border-gray-700 dark:text-gray-400">
                            <tr>
                                <th class="py-2 pr-3 font-medium">{{ __('filament.equb_report.member') }}</th>
                                <th class="py-2 pr-3 text-right font-medium">{{ __('filament.equb_report.collected') }}</th>
                                <th class="py-2 text-right font-medium">{{ __('filament.equb_report.count') }}</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                            @forelse ($report['top_members'] as $row)
                                <tr>
                                    <td class="py-2.5 pr-3">
                                        <div class="font-medium text-gray-950 dark:text-white">{{ $row['name'] }}</div>
                                        <div class="text-xs text-gray-500 dark:text-gray-400">{{ $row['phone'] }}</div>
                                    </td>
                                    <td class="py-2.5 pr-3 text-right tabular-nums font-medium">{{ $money($row['collected']) }}</td>
                                    <td class="py-2.5 text-right tabular-nums">{{ number_format($row['transactions']) }}</td>
                                </tr>
                            @empty
                                <tr><td colspan="3" class="py-8 text-center text-gray-500 dark:text-gray-400">{{ __('filament.equb_report.no_data') }}</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </x-filament::section>
        </div>

        {{-- ============================================================ --}}
        {{-- Transaction detail                                           --}}
        {{-- ============================================================ --}}
        <x-filament::section
            :heading="__('filament.equb_report.transaction_detail')"
            :description="trans_choice('filament.equb_report.showing_transactions', $report['details']->count(), ['count' => number_format($report['details']->count()), 'total' => number_format($summary['transactions'])])"
            icon="heroicon-o-list-bullet"
            collapsible
        >
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead class="border-b border-gray-200 text-left text-xs uppercase tracking-wide text-gray-500 dark:border-gray-700 dark:text-gray-400">
                        <tr>
                            <th class="py-2 pr-3 font-medium">#</th>
                            <th class="py-2 pr-3 font-medium">{{ __('filament.equb_report.date') }}</th>
                            <th class="py-2 pr-3 font-medium">{{ __('filament.equb_report.time') }}</th>
                            <th class="py-2 pr-3 font-medium">{{ __('filament.equb_report.member') }}</th>
                            <th class="py-2 pr-3 font-medium">{{ __('filament.equb_report.equb_group') }}</th>
                            <th class="py-2 pr-3 font-medium">{{ __('filament.equb_report.group_equb') }}</th>
                            <th class="py-2 pr-3 font-medium">{{ __('filament.equb_report.method') }}</th>
                            <th class="py-2 pr-3 font-medium">{{ __('filament.equb_report.status') }}</th>
                            <th class="py-2 pr-3 font-medium">{{ __('filament.equb_report.reference') }}</th>
                            <th class="py-2 text-right font-medium">{{ __('filament.equb_report.amount') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                        @forelse ($report['details'] as $row)
                            @php $paidAt = \Illuminate\Support\Carbon::parse($row['payment_date']); @endphp
                            <tr>
                                <td class="py-2.5 pr-3 tabular-nums text-gray-500 dark:text-gray-400">{{ $row['id'] }}</td>
                                {{-- Exact date and time of the payment, never rounded
                                     to a period bucket — this is what gets checked
                                     against a cash book. --}}
                                <td class="py-2.5 pr-3 whitespace-nowrap tabular-nums text-gray-600 dark:text-gray-300">
                                    {{ $paidAt->format('d/m/Y') }}
                                </td>
                                <td class="py-2.5 pr-3 whitespace-nowrap tabular-nums text-gray-600 dark:text-gray-300">
                                    {{ $paidAt->format('H:i:s') }}
                                </td>
                                <td class="py-2.5 pr-3">
                                    <div class="font-medium text-gray-950 dark:text-white">{{ $row['member_name'] }}</div>
                                    <div class="text-xs text-gray-500 dark:text-gray-400">{{ $row['member_phone'] }}</div>
                                </td>
                                <td class="py-2.5 pr-3">
                                    <div class="text-gray-600 dark:text-gray-300">{{ $row['group_name'] }}</div>
                                    @if ($row['package_name'])
                                        <div class="text-xs text-gray-500 dark:text-gray-400">{{ $row['package_name'] }}</div>
                                    @endif
                                </td>
                                {{-- Blank on a single Equb payment rather than
                                     repeating the Equb name, so the two routes
                                     read apart at a glance down the column. --}}
                                <td class="py-2.5 pr-3">
                                    @if ($row['group_equb_name'])
                                        <span class="font-medium text-gray-950 dark:text-white">{{ $row['group_equb_name'] }}</span>
                                    @else
                                        <span class="text-xs text-gray-400 dark:text-gray-500">{{ __('filament.equb_report.individual_equb') }}</span>
                                    @endif
                                </td>
                                <td class="py-2.5 pr-3">
                                    <x-filament::badge color="gray" size="sm">{{ ucfirst($row['payment_method']) }}</x-filament::badge>
                                </td>
                                <td class="py-2.5 pr-3">
                                    <x-filament::badge
                                        size="sm"
                                        :color="match ($row['status']) { 'paid' => 'success', 'pending' => 'warning', 'failed' => 'danger', default => 'gray' }"
                                    >
                                        {{ ucfirst($row['status']) }}
                                    </x-filament::badge>
                                </td>
                                <td class="py-2.5 pr-3 font-mono text-xs text-gray-500 dark:text-gray-400">{{ $row['reference'] ?: '—' }}</td>
                                <td class="py-2.5 text-right tabular-nums font-medium">{{ $money($row['amount']) }}</td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="10" class="py-10 text-center text-gray-500 dark:text-gray-400">
                                    {{ __('filament.equb_report.no_transactions') }}
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            @if ($summary['transactions'] > $report['details']->count())
                <p class="mt-3 text-xs text-gray-500 dark:text-gray-400">
                    {{ __('filament.equb_report.detail_truncated') }}
                </p>
            @endif
        </x-filament::section>

        {{-- ============================================================ --}}
        {{-- Scheduled printing                                           --}}
        {{-- ============================================================ --}}
        @php $schedules = $this->schedules(); $jobs = $this->recentPrintJobs(); @endphp

        <div class="grid gap-4 lg:grid-cols-2">
            <x-filament::section
                :heading="__('filament.equb_report.print_schedules')"
                :description="__('filament.equb_report.print_schedules_description')"
                icon="heroicon-o-clock"
                collapsible
                collapsed
            >
                {{-- A schedule that looks perfect still prints nothing if the
                     task scheduler is not running, and the two states are
                     indistinguishable from the list alone. --}}
                @unless ($this->schedulerIsRunning())
                    <div class="mb-3 rounded-lg border border-danger-200 bg-danger-50 px-3 py-2.5 text-xs text-danger-800 dark:border-danger-500/30 dark:bg-danger-500/10 dark:text-danger-300">
                        <x-filament::icon icon="heroicon-m-exclamation-triangle" class="mr-1 inline h-4 w-4 align-text-bottom" />
                        <span class="font-semibold">{{ __('filament.print_agent.scheduler_down') }}</span>
                        {{ __('filament.equb_report.scheduler_hint') }}
                        <code class="rounded bg-white/70 px-1 py-0.5 font-mono dark:bg-black/30">php artisan schedule:work</code>
                    </div>
                @endunless

                <div class="divide-y divide-gray-100 dark:divide-gray-800">
                    @forelse ($schedules as $schedule)
                        <div class="flex flex-wrap items-start justify-between gap-3 py-3 first:pt-0">
                            <div class="min-w-0">
                                <div class="flex items-center gap-2">
                                    <span class="font-medium text-gray-950 dark:text-white">{{ $schedule->name }}</span>
                                    <x-filament::badge size="sm" :color="$schedule->is_active ? 'success' : 'gray'">
                                        {{ $schedule->is_active ? __('filament.equb_report.active') : __('filament.equb_report.paused') }}
                                    </x-filament::badge>
                                </div>
                                <div class="mt-0.5 text-xs text-gray-500 dark:text-gray-400">
                                    {{ ucfirst($schedule->period) }} · {{ $schedule->frequencyLabel() }} ·
                                    {{ match ($schedule->delivery) {
                                        'network' => __('filament.equb_report.delivery_network') . ' (' . $schedule->printer_host . ')',
                                        'none' => __('filament.equb_report.delivery_none'),
                                        default => __('filament.equb_report.delivery_agent'),
                                    } }}
                                </div>
                                <div class="mt-0.5 text-xs text-gray-500 dark:text-gray-400">
                                    @if ($schedule->next_run_at && $schedule->is_active)
                                        {{ __('filament.equb_report.next_run') }}:
                                        {{ $schedule->next_run_at->timezone($schedule->timezone)->translatedFormat('D, j M Y') }}
                                        {{ __('filament.equb_report.at') }}
                                        {{ $schedule->next_run_at->timezone($schedule->timezone)->format('g:i A') }}
                                    @endif
                                    @if ($schedule->last_run_at)
                                        · {{ __('filament.equb_report.last_run') }}:
                                        {{ $schedule->last_run_at->timezone($schedule->timezone)->translatedFormat('j M') }}
                                        {{ $schedule->last_run_at->timezone($schedule->timezone)->format('g:i A') }}
                                        <span @class([
                                            'font-medium',
                                            'text-danger-600 dark:text-danger-400' => $schedule->last_status === 'failed',
                                            'text-success-600 dark:text-success-400' => in_array($schedule->last_status, ['printed', 'queued'], true),
                                        ])>({{ $schedule->last_status }})</span>
                                    @endif
                                </div>
                                @if ($schedule->last_error)
                                    <div class="mt-1 text-xs text-danger-600 dark:text-danger-400">{{ $schedule->last_error }}</div>
                                @endif
                            </div>

                            <div class="flex shrink-0 items-center gap-1">
                                <x-filament::icon-button
                                    icon="heroicon-m-play"
                                    color="gray"
                                    size="sm"
                                    :label="__('filament.equb_report.run_now')"
                                    wire:click="runScheduleNow({{ $schedule->id }})"
                                    wire:loading.attr="disabled"
                                />
                                <x-filament::icon-button
                                    :icon="$schedule->is_active ? 'heroicon-m-pause' : 'heroicon-m-power'"
                                    color="gray"
                                    size="sm"
                                    :label="$schedule->is_active ? __('filament.equb_report.pause') : __('filament.equb_report.resume')"
                                    wire:click="toggleSchedule({{ $schedule->id }})"
                                />
                                <x-filament::icon-button
                                    icon="heroicon-m-trash"
                                    color="danger"
                                    size="sm"
                                    :label="__('filament.equb_report.delete')"
                                    wire:click="deleteSchedule({{ $schedule->id }})"
                                    wire:confirm="{{ __('filament.equb_report.confirm_delete_schedule') }}"
                                />
                            </div>
                        </div>
                    @empty
                        <p class="py-6 text-center text-sm text-gray-500 dark:text-gray-400">
                            {{ __('filament.equb_report.no_schedules') }}
                        </p>
                    @endforelse
                </div>
            </x-filament::section>

            <x-filament::section
                :heading="__('filament.equb_report.print_queue')"
                :description="__('filament.equb_report.print_queue_description')"
                icon="heroicon-o-printer"
                collapsible
                collapsed
            >
                <div class="divide-y divide-gray-100 dark:divide-gray-800">
                    @forelse ($jobs as $job)
                        <div class="flex items-start justify-between gap-3 py-3 first:pt-0">
                            <div class="min-w-0">
                                <div class="flex items-center gap-2">
                                    <span class="truncate font-medium text-gray-950 dark:text-white">{{ $job->title }}</span>
                                    <x-filament::badge size="sm" :color="$job->statusColor()">{{ $job->status }}</x-filament::badge>
                                </div>
                                <div class="mt-0.5 text-xs text-gray-500 dark:text-gray-400">
                                    {{ $job->created_at->translatedFormat('d M, H:i') }} ·
                                    {{ strtoupper($job->format) }} · {{ $job->paper }} ·
                                    {{ $job->copies }}× ·
                                    {{ $job->source === 'schedule' ? __('filament.equb_report.from_schedule') : __('filament.equb_report.manual') }}
                                </div>
                                @if ($job->error)
                                    <div class="mt-1 text-xs text-danger-600 dark:text-danger-400">{{ $job->error }}</div>
                                @endif
                            </div>
                            @if (in_array($job->status, ['failed', 'printed'], true))
                                <x-filament::icon-button
                                    icon="heroicon-m-arrow-path"
                                    color="gray"
                                    size="sm"
                                    :label="__('filament.equb_report.requeue')"
                                    wire:click="retryPrintJob({{ $job->id }})"
                                />
                            @endif
                        </div>
                    @empty
                        <p class="py-6 text-center text-sm text-gray-500 dark:text-gray-400">
                            {{ __('filament.equb_report.no_print_jobs') }}
                        </p>
                    @endforelse
                </div>

                <div class="mt-3 rounded-lg bg-gray-50 px-3 py-2.5 text-xs text-gray-600 dark:bg-white/5 dark:text-gray-400">
                    <x-filament::icon icon="heroicon-m-information-circle" class="mr-1 inline h-4 w-4 align-text-bottom" />
                    {{ __('filament.equb_report.agent_hint') }}
                    <a href="{{ \App\Filament\Pages\PrintAgent::getUrl() }}" class="font-medium text-primary-600 hover:underline dark:text-primary-400">
                        {{ __('filament.print_agent.title') }}
                    </a>
                </div>
            </x-filament::section>
        </div>

    </div>
</x-filament-panels::page>
