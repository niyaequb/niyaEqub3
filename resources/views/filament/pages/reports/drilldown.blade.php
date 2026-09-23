<x-filament-panels::page>
    @php
        $money = fn ($v) => number_format((float) $v, 2);
        $tiles = $this->tiles();
        $panels = $this->panels();
        $chart = $this->chart();
        $explainer = $this->explainer();
        $meta = $this->report()['meta'];
        $activePeriod = $this->filters['period'] ?? 'daily';

        $accent = fn (string $a) => match ($a) {
            'success' => 'text-success-600 dark:text-success-400',
            'warning' => 'text-warning-600 dark:text-warning-400',
            'danger' => 'text-danger-600 dark:text-danger-400',
            'primary' => 'text-primary-600 dark:text-primary-400',
            default => 'text-gray-950 dark:text-white',
        };

        $cell = function (array $column, array $row) use ($money) {
            $value = $row[$column['key']] ?? null;

            return match ($column['type'] ?? 'text') {
                'money' => $money($value),
                'number' => number_format((float) $value),
                'percent' => number_format((float) $value, 1) . '%',
                default => $value,
            };
        };
    @endphp

    <div class="space-y-6">

        {{-- ============================================================ --}}
        {{-- Where this came from, and the window it covers               --}}
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

        @if ($explainer)
            {{-- Says what this page is counting. Every money figure in an Equb
                 is ambiguous until somebody states whose money it is. --}}
            <div class="rounded-xl border border-gray-200 bg-gray-50 px-4 py-3 text-sm text-gray-700 dark:border-white/10 dark:bg-white/5 dark:text-gray-300">
                {{ $explainer }}
            </div>
        @endif

        {{-- ============================================================ --}}
        {{-- Headline figures                                             --}}
        {{-- ============================================================ --}}
        @if ($tiles !== [])
            <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                @foreach ($tiles as $tile)
                    <x-filament::section class="!p-0">
                        <div class="p-4">
                            <div class="flex items-start justify-between gap-2">
                                <p class="text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">
                                    {{ $tile['label'] }}
                                </p>
                                @if (! empty($tile['icon']))
                                    <x-filament::icon :icon="$tile['icon']" class="h-4 w-4 text-gray-400 dark:text-gray-500" />
                                @endif
                            </div>
                            <p class="mt-1.5 text-2xl font-semibold tracking-tight tabular-nums {{ $accent($tile['accent'] ?? 'gray') }}">
                                {{ $tile['value'] }}
                            </p>
                            @if (! empty($tile['sub']))
                                <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">{{ $tile['sub'] }}</p>
                            @endif
                        </div>
                    </x-filament::section>
                @endforeach
            </div>
        @endif

        @if ($meta['has_filters'])
            <div class="rounded-lg border border-primary-200 bg-primary-50 px-4 py-2.5 text-sm dark:border-primary-500/20 dark:bg-primary-500/10">
                <span class="font-semibold text-primary-800 dark:text-primary-300">
                    {{ __('filament.equb_report.filters_applied') }}:
                </span>
                <span class="text-primary-700 dark:text-primary-200">{{ implode('  •  ', $meta['filters']) }}</span>
            </div>
        @endif

        {{-- ============================================================ --}}
        {{-- Trend                                                        --}}
        {{-- ============================================================ --}}
        @if ($chart && ! empty($chart['rows']))
            @php
                $seriesKeys = array_keys($chart['series']);
                $peak = collect($chart['rows'])
                    ->map(fn ($r) => collect($seriesKeys)->sum(fn ($k) => (float) ($r[$k] ?? 0)))
                    ->max() ?: 1;
                $palette = ['bg-primary-500', 'bg-success-500', 'bg-warning-500', 'bg-danger-500'];
            @endphp

            <x-filament::section :heading="$chart['label']" icon="heroicon-o-chart-bar">
                {{-- Drawn with divs rather than a charting library: this is a
                     stacked bar over at most a few dozen buckets, and a JS
                     dependency to render it would cost more than it returns. --}}
                <div class="flex items-end gap-1 overflow-x-auto pb-2" style="height: 12rem;">
                    @foreach ($chart['rows'] as $row)
                        @php $rowTotal = collect($seriesKeys)->sum(fn ($k) => (float) ($row[$k] ?? 0)); @endphp
                        <div class="group flex min-w-[1.25rem] flex-1 flex-col items-center justify-end gap-1" style="height: 100%;">
                            <div
                                class="relative flex w-full flex-col-reverse justify-start rounded-t transition"
                                style="height: {{ $peak > 0 ? max(1, ($rowTotal / $peak) * 100) : 1 }}%;"
                                title="{{ $row['label'] }} — {{ $money($rowTotal) }}"
                            >
                                @foreach ($seriesKeys as $i => $key)
                                    @php $value = (float) ($row[$key] ?? 0); @endphp
                                    @if ($value > 0)
                                        <div
                                            class="{{ $palette[$i % count($palette)] }} w-full first:rounded-t"
                                            style="height: {{ $rowTotal > 0 ? ($value / $rowTotal) * 100 : 0 }}%;"
                                        ></div>
                                    @endif
                                @endforeach
                            </div>
                            <span class="w-full truncate text-center text-[0.625rem] text-gray-400 dark:text-gray-500">
                                {{ $row['label'] }}
                            </span>
                        </div>
                    @endforeach
                </div>

                <div class="mt-3 flex flex-wrap gap-4 border-t border-gray-100 pt-3 text-xs dark:border-white/5">
                    @foreach ($chart['series'] as $i => $label)
                        <span class="inline-flex items-center gap-1.5 text-gray-600 dark:text-gray-300">
                            <span class="h-2.5 w-2.5 rounded-sm {{ $palette[$loop->index % count($palette)] }}"></span>
                            {{ $label }}
                        </span>
                    @endforeach
                </div>
            </x-filament::section>
        @endif

        {{-- ============================================================ --}}
        {{-- Detail panels                                                --}}
        {{-- ============================================================ --}}
        @foreach ($panels as $panel)
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
                                    <th @class([
                                        'whitespace-nowrap py-2 pr-3',
                                        'text-right' => ($column['align'] ?? 'left') === 'right',
                                    ])>{{ $column['label'] }}</th>
                                @endforeach
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100 dark:divide-white/5">
                            @forelse ($panel['rows'] as $row)
                                <tr @class(['bg-danger-50/50 dark:bg-danger-500/5' => $row['_critical'] ?? false])>
                                    @foreach ($panel['columns'] as $column)
                                        <td @class([
                                            'py-2.5 pr-3',
                                            'text-right tabular-nums' => ($column['align'] ?? 'left') === 'right',
                                        ])>
                                            @if (($column['type'] ?? 'text') === 'badge')
                                                <x-filament::badge :color="$row[$column['key'] . '_color'] ?? 'gray'" size="sm">
                                                    {{ $row[$column['key']] }}
                                                </x-filament::badge>
                                            @else
                                                <span @class([
                                                    'font-medium text-gray-950 dark:text-white' => $column['strong'] ?? false,
                                                    'text-danger-600 dark:text-danger-400' => ($column['danger'] ?? false) && (float) ($row[$column['key']] ?? 0) > 0,
                                                    'text-success-600 dark:text-success-400' => $column['good'] ?? false,
                                                ])>{{ $cell($column, $row) }}</span>

                                                @if (! empty($column['sub']) && ! empty($row[$column['sub']]))
                                                    <div class="text-xs text-gray-500 dark:text-gray-400">{{ $row[$column['sub']] }}</div>
                                                @endif
                                            @endif
                                        </td>
                                    @endforeach
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="{{ count($panel['columns']) }}" class="py-10 text-center text-gray-500 dark:text-gray-400">
                                        {{ $panel['empty'] ?? __('filament.equb_report.no_data') }}
                                    </td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>

                @if (! empty($panel['footnote']))
                    <p class="mt-3 text-xs text-gray-500 dark:text-gray-400">{{ $panel['footnote'] }}</p>
                @endif
            </x-filament::section>
        @endforeach
    </div>
</x-filament-panels::page>
