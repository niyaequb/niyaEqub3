<x-filament-panels::page>
    @php
        $money = fn ($v) => number_format((float) $v, 2);
        $totals = $this->totals();
        $day = $this->day();
        $drift = $this->drift();
        $exceptions = $this->exceptions();
        $date = $this->businessDate();
        $importing = $this->import !== [];

        $variance = (float) $totals['variance'];
        $balanced = abs($variance) < 0.01;
    @endphp

    <div class="space-y-6">

        {{-- ============================================================ --}}
        {{-- A signed-off day that has moved since                        --}}
        {{--                                                              --}}
        {{-- The single most important thing this page can say, so it     --}}
        {{-- sits above everything else. A late settlement or a           --}}
        {{-- re-imported statement can change a day somebody already put  --}}
        {{-- their name to, and that must never be applied silently.      --}}
        {{-- ============================================================ --}}
        @if ($drift)
            <div class="rounded-xl border border-danger-300 bg-danger-50 p-4 dark:border-danger-500/30 dark:bg-danger-500/10">
                <p class="flex items-center gap-2 font-semibold text-danger-800 dark:text-danger-300">
                    <x-filament::icon icon="heroicon-m-exclamation-triangle" class="h-5 w-5" />
                    {{ __('filament.reconciliation.drift_heading') }}
                </p>
                <p class="mt-1 text-sm text-danger-700 dark:text-danger-200">
                    {{ __('filament.reconciliation.drift_body', [
                        'signed_ours' => $money($drift['signed_ours']),
                        'signed_bank' => $money($drift['signed_bank']),
                        'now_ours' => $money($drift['now_ours']),
                        'now_bank' => $money($drift['now_bank']),
                    ]) }}
                </p>
            </div>
        @endif

        {{-- ============================================================ --}}
        {{-- Which bank, which day                                        --}}
        {{-- ============================================================ --}}
        <div class="flex flex-wrap items-center justify-between gap-3">
            <div class="flex flex-wrap items-center gap-2">
                <select
                    wire:model.live="gateway"
                    class="fi-input rounded-lg border-gray-300 bg-white py-1.5 pe-8 ps-3 text-sm shadow-sm dark:border-white/10 dark:bg-white/5 dark:text-white"
                >
                    @foreach ($this->gateways() as $slug => $name)
                        <option value="{{ $slug }}">{{ $name }}</option>
                    @endforeach
                </select>

                <div class="flex items-center gap-1 rounded-lg bg-gray-100 p-1 dark:bg-white/5">
                    <button type="button" wire:click="shiftDay(-1)" class="rounded p-1.5 text-gray-500 transition hover:bg-white hover:text-gray-900 dark:hover:bg-gray-800 dark:hover:text-white">
                        <x-filament::icon icon="heroicon-m-chevron-left" class="h-4 w-4" />
                    </button>

                    <input
                        type="date"
                        wire:model.live="date"
                        class="fi-input border-0 bg-transparent py-1 text-sm font-medium text-gray-950 focus:ring-0 dark:text-white"
                    />

                    <button type="button" wire:click="shiftDay(1)" class="rounded p-1.5 text-gray-500 transition hover:bg-white hover:text-gray-900 dark:hover:bg-gray-800 dark:hover:text-white">
                        <x-filament::icon icon="heroicon-m-chevron-right" class="h-4 w-4" />
                    </button>
                </div>

                <span class="text-sm text-gray-500 dark:text-gray-400">
                    {{ $date->translatedFormat('l, d F Y') }}
                </span>
            </div>

            @if ($day)
                <x-filament::badge :color="$day->statusColor()" size="lg">
                    {{ $day->statusLabel() }}
                    @if ($day->isSignedOff() && $day->signedOffBy)
                        — {{ $day->signedOffBy->name }}, {{ $day->signed_off_at?->format('d M, H:i') }}
                    @endif
                </x-filament::badge>
            @endif
        </div>

        {{-- ============================================================ --}}
        {{-- The statement mapping panel                                  --}}
        {{--                                                              --}}
        {{-- Shown only while a file has been read and not yet imported.  --}}
        {{-- Nothing has been written at this point.                      --}}
        {{-- ============================================================ --}}
        @if ($importing)
            @php
                $columns = $this->columnOptions();
                $labels = $this->fieldLabels();
                $required = $this->requiredFields();
                $confidence = $this->import['confidence'] ?? [];
            @endphp

            <x-filament::section
                :heading="__('filament.reconciliation.mapping_heading')"
                :description="__('filament.reconciliation.mapping_description', [
                    'file' => $this->import['name'],
                    'rows' => number_format($this->import['total_rows']),
                ])"
                icon="heroicon-o-table-cells"
            >
                @if (! empty($this->import['missing']))
                    <div class="mb-4 rounded-lg border border-danger-300 bg-danger-50 px-3 py-2 text-sm text-danger-800 dark:border-danger-500/30 dark:bg-danger-500/10 dark:text-danger-300">
                        {{ __('filament.reconciliation.mapping_missing', [
                            'fields' => collect($this->import['missing'])->map(fn ($f) => $labels[$f] ?? $f)->implode(', '),
                        ]) }}
                    </div>
                @endif

                <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                    @foreach ($labels as $field => $label)
                        <div>
                            <label class="flex items-center gap-1.5 text-xs font-medium text-gray-600 dark:text-gray-300">
                                {{ $label }}
                                @if (in_array($field, $required, true))
                                    <span class="text-danger-600 dark:text-danger-400">*</span>
                                @endif
                                @if (($confidence[$field] ?? null) === 'guessed')
                                    {{-- Said out loud: a guessed column is the
                                         one worth checking against the sample
                                         row below before importing. --}}
                                    <span class="rounded bg-warning-100 px-1 py-0.5 text-[0.625rem] font-medium text-warning-800 dark:bg-warning-500/20 dark:text-warning-300">
                                        {{ __('filament.reconciliation.guessed') }}
                                    </span>
                                @elseif (($confidence[$field] ?? null) === 'saved')
                                    <span class="rounded bg-success-100 px-1 py-0.5 text-[0.625rem] font-medium text-success-800 dark:bg-success-500/20 dark:text-success-300">
                                        {{ __('filament.reconciliation.remembered') }}
                                    </span>
                                @endif
                            </label>

                            <select
                                wire:model.live="mapping.{{ $field }}"
                                class="fi-input mt-1 w-full rounded-lg border-gray-300 bg-white py-1.5 text-sm shadow-sm dark:border-white/10 dark:bg-white/5 dark:text-white"
                            >
                                <option value="">{{ __('filament.reconciliation.not_present') }}</option>
                                @foreach ($columns as $index => $header)
                                    <option value="{{ $index }}">{{ $header }}</option>
                                @endforeach
                            </select>
                        </div>
                    @endforeach
                </div>

                {{-- The proof. Values from their own file, read through the
                     mapping above — the only way to catch a plausible mapping
                     that is reading the wrong column. --}}
                <div class="mt-5">
                    <p class="mb-2 text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">
                        {{ __('filament.reconciliation.mapping_preview') }}
                    </p>
                    <div class="overflow-x-auto rounded-lg border border-gray-200 dark:border-white/10">
                        <table class="w-full text-sm">
                            <thead class="bg-gray-50 text-left text-xs uppercase tracking-wide text-gray-500 dark:bg-white/5 dark:text-gray-400">
                                <tr>
                                    <th class="px-3 py-2">{{ __('filament.reconciliation.field_posted_at') }}</th>
                                    <th class="px-3 py-2 text-right">{{ __('filament.reconciliation.field_amount') }}</th>
                                    <th class="px-3 py-2">{{ __('filament.reconciliation.field_external_ref') }}</th>
                                    <th class="px-3 py-2">{{ __('filament.reconciliation.field_payer_name') }}</th>
                                    <th class="px-3 py-2">{{ __('filament.reconciliation.field_narrative') }}</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-100 dark:divide-white/5">
                                @foreach ($this->import['preview'] as $row)
                                    <tr>
                                        <td class="px-3 py-2 whitespace-nowrap {{ $row['posted_at'] ? '' : 'text-danger-600 dark:text-danger-400' }}">
                                            {{ $row['posted_at']?->format('d M Y, H:i') ?? __('filament.reconciliation.unreadable') }}
                                        </td>
                                        <td class="px-3 py-2 text-right tabular-nums {{ $row['amount'] === null ? 'text-danger-600 dark:text-danger-400' : 'font-medium' }}">
                                            {{ $row['amount'] === null ? __('filament.reconciliation.unreadable') : $money($row['amount']) }}
                                            @if ($row['direction'] === 'debit')
                                                <span class="text-xs text-gray-500">({{ __('filament.reconciliation.debit') }})</span>
                                            @endif
                                        </td>
                                        <td class="px-3 py-2 font-mono text-xs text-gray-600 dark:text-gray-300">{{ $row['external_ref'] ?: '—' }}</td>
                                        <td class="px-3 py-2">{{ $row['payer_name'] ?: '—' }}</td>
                                        <td class="max-w-xs truncate px-3 py-2 text-xs text-gray-500 dark:text-gray-400">{{ $row['narrative'] ?: '—' }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </div>

                <div class="mt-4 flex flex-wrap items-center gap-3">
                    <x-filament::button
                        wire:click="confirmImport"
                        icon="heroicon-m-check"
                        :disabled="! empty($this->import['missing'])"
                    >
                        {{ __('filament.reconciliation.import_confirm', ['rows' => number_format($this->import['total_rows'])]) }}
                    </x-filament::button>

                    <x-filament::button wire:click="cancelImport" color="gray" icon="heroicon-m-x-mark">
                        {{ __('filament.reconciliation.cancel') }}
                    </x-filament::button>

                    <span class="text-sm text-gray-500 dark:text-gray-400">
                        <span wire:loading wire:target="confirmImport">{{ __('filament.reconciliation.importing') }}…</span>
                    </span>
                </div>
            </x-filament::section>
        @endif

        {{-- ============================================================ --}}
        {{-- The two sides, and the gap                                   --}}
        {{-- ============================================================ --}}
        <div class="grid gap-4 lg:grid-cols-3">
            <x-filament::section class="!p-0">
                <div class="p-4">
                    <p class="text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">
                        {{ __('filament.reconciliation.our_records') }}
                    </p>
                    <p class="mt-1.5 text-2xl font-semibold tabular-nums text-gray-950 dark:text-white">
                        {{ $money($totals['our_amount']) }} <span class="text-base font-normal text-gray-400">ETB</span>
                    </p>
                    <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                        {{ trans_choice('filament.reconciliation.n_contributions', $totals['our_count'], ['count' => number_format($totals['our_count'])]) }}
                    </p>
                    @if ($totals['unverified_count'] > 0)
                        {{-- Credited on somebody's word. Called out here rather
                             than left in a filter, because it is the first
                             thing an auditor asks about. --}}
                        <p class="mt-2 rounded-lg bg-warning-50 px-2 py-1.5 text-xs text-warning-800 dark:bg-warning-500/10 dark:text-warning-300">
                            {{ __('filament.reconciliation.of_which_unverified', [
                                'count' => number_format($totals['unverified_count']),
                                'amount' => $money($totals['unverified_amount']),
                            ]) }}
                        </p>
                    @endif
                </div>
            </x-filament::section>

            <x-filament::section class="!p-0">
                <div class="p-4">
                    <p class="text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">
                        {{ __('filament.reconciliation.the_bank') }}
                    </p>
                    @if ($totals['statement_loaded'])
                        <p class="mt-1.5 text-2xl font-semibold tabular-nums text-gray-950 dark:text-white">
                            {{ $money($totals['bank_amount']) }} <span class="text-base font-normal text-gray-400">ETB</span>
                        </p>
                        <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                            {{ trans_choice('filament.reconciliation.n_credits', $totals['bank_count'], ['count' => number_format($totals['bank_count'])]) }}
                        </p>
                    @else
                        <p class="mt-1.5 text-lg font-medium text-gray-400 dark:text-gray-500">
                            {{ __('filament.reconciliation.no_statement') }}
                        </p>
                        <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                            {{ __('filament.reconciliation.no_statement_hint') }}
                        </p>
                    @endif
                </div>
            </x-filament::section>

            <x-filament::section class="!p-0">
                <div @class([
                    'p-4 rounded-xl',
                    'bg-success-50/60 dark:bg-success-500/5' => $totals['statement_loaded'] && $balanced,
                    'bg-danger-50/60 dark:bg-danger-500/5' => $totals['statement_loaded'] && ! $balanced,
                ])>
                    <p class="text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">
                        {{ __('filament.reconciliation.difference') }}
                    </p>
                    <p @class([
                        'mt-1.5 text-2xl font-semibold tabular-nums',
                        'text-gray-400 dark:text-gray-500' => ! $totals['statement_loaded'],
                        'text-success-600 dark:text-success-400' => $totals['statement_loaded'] && $balanced,
                        'text-danger-600 dark:text-danger-400' => $totals['statement_loaded'] && ! $balanced,
                    ])>
                        {{ $totals['statement_loaded'] ? ($variance > 0 ? '+' : '') . $money($variance) : '—' }}
                        @if ($totals['statement_loaded'])
                            <span class="text-base font-normal text-gray-400">ETB</span>
                        @endif
                    </p>
                    <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                        @if (! $totals['statement_loaded'])
                            {{ __('filament.reconciliation.difference_pending') }}
                        @elseif ($balanced)
                            {{ __('filament.reconciliation.difference_none') }}
                        @elseif ($variance > 0)
                            {{-- Positive means the bank holds more than we
                                 credited: somebody paid and is still owed. --}}
                            {{ __('filament.reconciliation.difference_bank_higher') }}
                        @else
                            {{ __('filament.reconciliation.difference_ours_higher') }}
                        @endif
                    </p>
                </div>
            </x-filament::section>
        </div>

        {{-- ============================================================ --}}
        {{-- Exception queues                                             --}}
        {{-- ============================================================ --}}
        <x-filament::section
            :heading="__('filament.reconciliation.exceptions')"
            :description="__('filament.reconciliation.exceptions_description')"
            icon="heroicon-o-flag"
        >
            <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                @foreach ($exceptions as $exception)
                    <button
                        type="button"
                        wire:click="openQueue('{{ $exception['key'] }}')"
                        @class([
                            'rounded-xl border p-3 text-left transition',
                            'border-primary-500 bg-primary-50 dark:border-primary-400 dark:bg-primary-500/10' => $this->queue === $exception['key'],
                            'border-gray-200 hover:border-gray-300 hover:bg-gray-50 dark:border-white/10 dark:hover:border-white/20 dark:hover:bg-white/5' => $this->queue !== $exception['key'],
                            'opacity-60' => $exception['count'] === 0,
                        ])
                    >
                        <div class="flex items-start justify-between gap-2">
                            <p class="text-xs font-medium text-gray-600 dark:text-gray-300">{{ $exception['label'] }}</p>
                            @if ($exception['count'] > 0 && $exception['severity'] === 'danger')
                                <span class="h-2 w-2 shrink-0 rounded-full bg-danger-500"></span>
                            @endif
                        </div>
                        <p @class([
                            'mt-1 text-xl font-semibold tabular-nums',
                            'text-gray-400 dark:text-gray-500' => $exception['count'] === 0,
                            'text-danger-600 dark:text-danger-400' => $exception['count'] > 0 && $exception['severity'] === 'danger',
                            'text-warning-600 dark:text-warning-400' => $exception['count'] > 0 && $exception['severity'] === 'warning',
                            'text-gray-950 dark:text-white' => $exception['count'] > 0 && $exception['severity'] === 'gray',
                        ])>
                            {{ number_format($exception['count']) }}
                        </p>
                        <p class="text-xs tabular-nums text-gray-500 dark:text-gray-400">{{ $money($exception['amount']) }} ETB</p>
                        <p class="mt-1.5 text-[0.6875rem] leading-snug text-gray-500 dark:text-gray-400">{{ $exception['description'] }}</p>
                    </button>
                @endforeach
            </div>
        </x-filament::section>

        {{-- ============================================================ --}}
        {{-- The open queue                                               --}}
        {{-- ============================================================ --}}
        @if ($this->queue)
            @php
                $rows = $this->queueRows();
                $isBankSide = $this->queueDirection() === 'bank';
            @endphp

            <x-filament::section
                :heading="__('filament.reconciliation.ex_' . $this->queue)"
                :description="__('filament.reconciliation.ex_' . $this->queue . '_description')"
                icon="heroicon-o-queue-list"
            >
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead class="border-b border-gray-200 text-left text-xs font-medium uppercase tracking-wide text-gray-500 dark:border-white/10 dark:text-gray-400">
                            <tr>
                                <th class="py-2 pr-3">{{ __('filament.reconciliation.when') }}</th>
                                <th class="py-2 pr-3">{{ __('filament.reconciliation.who') }}</th>
                                <th class="py-2 pr-3">{{ __('filament.reconciliation.reference') }}</th>
                                <th class="py-2 pr-3 text-right">{{ __('filament.reconciliation.amount') }}</th>
                                <th class="py-2 pr-3">{{ __('filament.reconciliation.detail') }}</th>
                                <th class="py-2 text-right">{{ __('filament.reconciliation.what_now') }}</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100 dark:divide-white/5">
                            @forelse ($rows as $row)
                                <tr>
                                    <td class="py-2.5 pr-3 whitespace-nowrap text-gray-600 dark:text-gray-300">{{ $row['when'] ?: '—' }}</td>
                                    <td class="py-2.5 pr-3">
                                        <div class="font-medium text-gray-950 dark:text-white">{{ $row['who'] }}</div>
                                        @if (! empty($row['phone']))
                                            <div class="text-xs text-gray-500 dark:text-gray-400">{{ $row['phone'] }}</div>
                                        @endif
                                        @if (! empty($row['group']))
                                            <div class="text-xs text-gray-500 dark:text-gray-400">{{ $row['group'] }}</div>
                                        @endif
                                    </td>
                                    <td class="py-2.5 pr-3 font-mono text-xs text-gray-500 dark:text-gray-400">{{ $row['reference'] ?: '—' }}</td>
                                    <td class="py-2.5 pr-3 text-right font-medium tabular-nums">{{ $money($row['amount']) }}</td>
                                    <td class="max-w-sm py-2.5 pr-3 text-xs text-gray-500 dark:text-gray-400">
                                        {{ $row['note'] ?: ($row['narrative'] ?? '—') }}
                                        @if (! empty($row['confidence']))
                                            <span class="ml-1 rounded bg-gray-100 px-1 py-0.5 text-[0.625rem] font-medium text-gray-600 dark:bg-white/10 dark:text-gray-300">
                                                {{ $row['rule'] }} · {{ $row['confidence'] }}%
                                            </span>
                                        @endif
                                    </td>
                                    <td class="py-2.5 text-right">
                                        <div class="flex items-center justify-end gap-2">
                                            @if ($isBankSide)
                                                @if (empty($row['payment_id']))
                                                    {{ ($this->matchLineAction)(['line' => $row['id']]) }}
                                                    {{ ($this->ignoreLineAction)(['line' => $row['id']]) }}
                                                @else
                                                    {{ ($this->unlinkLineAction)(['line' => $row['id']]) }}
                                                @endif
                                            @else
                                                {{ ($this->askBankAction)(['payment' => $row['id']]) }}
                                                {{ ($this->acceptPaymentAction)(['payment' => $row['id']]) }}
                                            @endif
                                        </div>
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="6" class="py-10 text-center text-gray-500 dark:text-gray-400">
                                        {{ __('filament.reconciliation.queue_clear') }}
                                    </td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </x-filament::section>
        @endif

        {{-- ============================================================ --}}
        {{-- The last fortnight, and the statements behind it             --}}
        {{-- ============================================================ --}}
        <div class="grid gap-4 lg:grid-cols-2">
            <x-filament::section
                :heading="__('filament.reconciliation.recent_days')"
                icon="heroicon-o-calendar-days"
            >
                @php $days = $this->recentDays(); @endphp

                @if ($days->isEmpty())
                    <p class="py-6 text-center text-sm text-gray-500 dark:text-gray-400">
                        {{ __('filament.reconciliation.no_days_yet') }}
                    </p>
                @else
                    <div class="space-y-1.5">
                        @foreach ($days->reverse() as $row)
                            <button
                                type="button"
                                wire:click="$set('date', '{{ $row->business_date->toDateString() }}')"
                                class="flex w-full items-center justify-between gap-3 rounded-lg px-2 py-1.5 text-left text-sm transition hover:bg-gray-50 dark:hover:bg-white/5"
                            >
                                <span class="w-28 shrink-0 text-gray-600 dark:text-gray-300">
                                    {{ $row->business_date->translatedFormat('D, d M') }}
                                </span>
                                <span class="flex-1 tabular-nums text-gray-500 dark:text-gray-400">
                                    {{ $money($row->our_amount) }} / {{ $row->statement_loaded ? $money($row->bank_amount) : '—' }}
                                </span>
                                <span @class([
                                    'tabular-nums font-medium',
                                    'text-success-600 dark:text-success-400' => $row->balances(),
                                    'text-danger-600 dark:text-danger-400' => ! $row->balances(),
                                ])>
                                    {{ $row->statement_loaded ? $money($row->variance) : '' }}
                                </span>
                                <x-filament::badge :color="$row->statusColor()" size="sm">
                                    {{ $row->statusLabel() }}
                                </x-filament::badge>
                            </button>
                        @endforeach
                    </div>
                @endif
            </x-filament::section>

            <x-filament::section
                :heading="__('filament.reconciliation.statements')"
                icon="heroicon-o-document-text"
            >
                @php $statements = $this->statements(); @endphp

                @if ($statements->isEmpty())
                    <p class="py-6 text-center text-sm text-gray-500 dark:text-gray-400">
                        {{ __('filament.reconciliation.no_statements') }}
                    </p>
                @else
                    <div class="space-y-3">
                        @foreach ($statements as $statement)
                            <div class="rounded-lg border border-gray-200 p-3 dark:border-white/10">
                                <div class="flex flex-wrap items-baseline justify-between gap-2">
                                    <span class="font-medium text-gray-950 dark:text-white">{{ $statement->original_filename }}</span>
                                    <span class="text-xs text-gray-500 dark:text-gray-400">{{ $statement->periodLabel() }}</span>
                                </div>
                                <div class="mt-1 flex flex-wrap gap-x-4 gap-y-1 text-xs text-gray-500 dark:text-gray-400">
                                    <span>{{ __('filament.reconciliation.imported_n', ['n' => number_format($statement->imported_count)]) }}</span>
                                    @if ($statement->duplicate_count > 0)
                                        <span>{{ __('filament.reconciliation.duplicates_n', ['n' => number_format($statement->duplicate_count)]) }}</span>
                                    @endif
                                    @if ($statement->skipped_count > 0)
                                        <span class="text-warning-600 dark:text-warning-400">
                                            {{ __('filament.reconciliation.skipped_n', ['n' => number_format($statement->skipped_count)]) }}
                                        </span>
                                    @endif
                                    <span class="tabular-nums">{{ $money($statement->total_credit) }} ETB</span>
                                    @if ($statement->importer)
                                        <span>{{ $statement->importer->name }}</span>
                                    @endif
                                </div>
                                @if ($statement->notes)
                                    <p class="mt-1 text-xs text-warning-700 dark:text-warning-400">{{ $statement->notes }}</p>
                                @endif
                                @unless ($statement->linesBalance())
                                    {{-- The file no longer adds up to what it
                                         said on import: rows have been removed
                                         or edited since. --}}
                                    <p class="mt-1 text-xs font-medium text-danger-700 dark:text-danger-400">
                                        {{ __('filament.reconciliation.statement_drifted') }}
                                    </p>
                                @endunless
                            </div>
                        @endforeach
                    </div>
                @endif
            </x-filament::section>
        </div>
    </div>
</x-filament-panels::page>
