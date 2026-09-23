<x-filament-panels::page>
    @php
        $station = $this->station();
        $master = $this->masterEnabled();
        $checks = $this->diagnostics();
        $failing = collect($checks)->reject(fn ($c) => $c['ok']);
        $queue = $this->queuedJobs();
        $depth = $this->queueDepth();
        $retrying = $this->retryingCount();
        $history = $this->history();
        $schedules = $this->schedules();
        $stations = $this->stations();
        $state = $station?->state($master);
    @endphp

    <div class="space-y-6">

        {{-- ============================================================ --}}
        {{-- Master switch                                                --}}
        {{-- ============================================================ --}}
        {{--
            Stated at the top and in full. A system that has been switched off
            deliberately and one that is broken look identical from the
            printer, and the difference between them is the whole of the
            morning somebody would otherwise spend finding out.
        --}}
        @unless ($master)
            <div class="rounded-xl border border-warning-300 bg-warning-50 p-5 dark:border-warning-500/30 dark:bg-warning-500/10">
                <div class="flex items-start gap-3">
                    <x-filament::icon icon="heroicon-o-no-symbol" class="mt-0.5 h-6 w-6 shrink-0 text-warning-600 dark:text-warning-400" />
                    <div class="min-w-0">
                        <p class="text-base font-semibold text-warning-800 dark:text-warning-200">
                            {{ __('filament.print_agent.master_off') }}
                        </p>
                        <p class="mt-1 text-sm text-warning-700 dark:text-warning-300">
                            {{ __('filament.print_agent.master_off_body') }}
                        </p>
                        <dl class="mt-3 grid gap-x-8 gap-y-1 text-sm sm:grid-cols-3">
                            <div>
                                <dt class="text-xs uppercase tracking-wide text-warning-600 dark:text-warning-400">{{ __('filament.print_agent.turned_off_by') }}</dt>
                                <dd class="font-medium text-warning-900 dark:text-warning-100">{{ $this->agentService()->disabledBy() ?: __('filament.print_agent.somebody') }}</dd>
                            </div>
                            <div>
                                <dt class="text-xs uppercase tracking-wide text-warning-600 dark:text-warning-400">{{ __('filament.print_agent.turned_off_when') }}</dt>
                                <dd class="font-medium text-warning-900 dark:text-warning-100">{{ $this->agentService()->disabledAt()?->diffForHumans() ?: __('filament.print_agent.earlier') }}</dd>
                            </div>
                            <div class="sm:col-span-1">
                                <dt class="text-xs uppercase tracking-wide text-warning-600 dark:text-warning-400">{{ __('filament.print_agent.turned_off_why') }}</dt>
                                <dd class="font-medium text-warning-900 dark:text-warning-100">{{ $this->agentService()->disabledReason() ?: __('filament.print_agent.no_reason_given') }}</dd>
                            </div>
                        </dl>
                    </div>
                </div>
            </div>
        @endunless

        {{-- ============================================================ --}}
        {{-- This station                                                 --}}
        {{-- ============================================================ --}}
        <div
            @if ($this->stationId)
                wire:poll.keep-alive.{{ $this->pollMs() }}ms="tick"
            @endif
        >
            @if (! $this->stationId)
                {{-- Before the browser has said which desk it is. --}}
                <div class="flex items-center gap-3 rounded-xl border border-gray-200 bg-gray-50 p-5 dark:border-white/10 dark:bg-white/5">
                    <x-filament::loading-indicator class="h-5 w-5 text-gray-400" />
                    <div>
                        <p class="font-medium text-gray-800 dark:text-gray-100">{{ __('filament.print_agent.connecting') }}</p>
                        <p class="text-sm text-gray-500 dark:text-gray-400">{{ __('filament.print_agent.connecting_body') }}</p>
                    </div>
                </div>
            @else
                <div @class([
                    'rounded-xl border p-5 transition',
                    'border-success-300 bg-success-50 dark:border-success-500/30 dark:bg-success-500/10' => $state === \App\Models\PrintStation::STATE_IDLE,
                    'border-info-300 bg-info-50 dark:border-info-500/30 dark:bg-info-500/10' => $state === \App\Models\PrintStation::STATE_PRINTING,
                    'border-warning-300 bg-warning-50 dark:border-warning-500/30 dark:bg-warning-500/10' => in_array($state, [\App\Models\PrintStation::STATE_DISABLED, \App\Models\PrintStation::STATE_BLOCKED], true),
                    'border-gray-200 bg-gray-50 dark:border-white/10 dark:bg-white/5' => $state === \App\Models\PrintStation::STATE_OFFLINE,
                ])>
                    <div class="flex flex-wrap items-start justify-between gap-5">
                        <div class="flex items-start gap-4">
                            <span class="relative mt-1.5 flex h-3 w-3 shrink-0">
                                @if (in_array($state, [\App\Models\PrintStation::STATE_IDLE, \App\Models\PrintStation::STATE_PRINTING], true))
                                    <span class="absolute inline-flex h-full w-full animate-ping rounded-full opacity-75 {{ $state === \App\Models\PrintStation::STATE_PRINTING ? 'bg-info-400' : 'bg-success-400' }}"></span>
                                    <span class="relative inline-flex h-3 w-3 rounded-full {{ $state === \App\Models\PrintStation::STATE_PRINTING ? 'bg-info-500' : 'bg-success-500' }}"></span>
                                @else
                                    <span class="relative inline-flex h-3 w-3 rounded-full bg-gray-400"></span>
                                @endif
                            </span>

                            <div class="min-w-0">
                                <div class="flex flex-wrap items-center gap-2">
                                    <p class="text-lg font-semibold text-gray-950 dark:text-white">{{ $station->name }}</p>
                                    <x-filament::badge size="sm" :color="$station->stateColor($state)">
                                        {{ __('filament.print_agent.state_'.$state) }}
                                    </x-filament::badge>
                                    <x-filament::badge size="sm" color="gray">{{ $station->paperLabel() }}</x-filament::badge>
                                </div>

                                <p class="mt-1 text-sm text-gray-600 dark:text-gray-400">
                                    {{ __('filament.print_agent.state_'.$state.'_help') }}
                                </p>

                                @if ($station->location || $station->printer_hint)
                                    <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                                        {{ collect([$station->location, $station->printer_hint])->filter()->join(' · ') }}
                                    </p>
                                @endif

                                {{-- Silent printing, stated as a fact rather than as advice. --}}
                                <div class="mt-3 flex flex-wrap items-center gap-2 text-xs">
                                    @if ($station->silent_ready)
                                        <span class="inline-flex items-center gap-1 rounded-full bg-success-100 px-2 py-1 font-medium text-success-800 dark:bg-success-500/20 dark:text-success-300">
                                            <x-filament::icon icon="heroicon-m-check-circle" class="h-3.5 w-3.5" />
                                            {{ __('filament.print_agent.silent_on') }}
                                        </span>
                                        <span class="text-gray-500 dark:text-gray-400">
                                            {{ __('filament.print_agent.silent_measured', [
                                                'ms' => (int) $station->silent_probe_ms,
                                                'when' => $station->silent_checked_at?->diffForHumans() ?? '—',
                                            ]) }}
                                        </span>
                                    @else
                                        <span class="inline-flex items-center gap-1 rounded-full bg-warning-100 px-2 py-1 font-medium text-warning-800 dark:bg-warning-500/20 dark:text-warning-300">
                                            <x-filament::icon icon="heroicon-m-exclamation-triangle" class="h-3.5 w-3.5" />
                                            {{ $station->silent_checked_at ? __('filament.print_agent.silent_off') : __('filament.print_agent.silent_unknown') }}
                                        </span>
                                        <span class="text-gray-500 dark:text-gray-400">{{ __('filament.print_agent.silent_why') }}</span>
                                    @endif
                                </div>
                            </div>
                        </div>

                        <div class="flex flex-col items-end gap-3">
                            <div class="flex items-center gap-6 text-sm">
                                <div class="text-right">
                                    <p class="text-xs uppercase tracking-wide text-gray-500 dark:text-gray-400">{{ __('filament.print_agent.waiting') }}</p>
                                    <p class="text-xl font-semibold tabular-nums text-gray-950 dark:text-white">{{ $depth }}</p>
                                </div>
                                <div class="text-right">
                                    <p class="text-xs uppercase tracking-wide text-gray-500 dark:text-gray-400">{{ __('filament.print_agent.printed_session') }}</p>
                                    <p class="text-xl font-semibold tabular-nums text-gray-950 dark:text-white">{{ $printedThisSession }}</p>
                                </div>
                                <div class="text-right">
                                    <p class="text-xs uppercase tracking-wide text-gray-500 dark:text-gray-400">{{ __('filament.print_agent.printed_total') }}</p>
                                    <p class="text-xl font-semibold tabular-nums text-gray-950 dark:text-white">{{ number_format($station->printed_count) }}</p>
                                </div>
                            </div>

                            <x-filament::button
                                size="sm"
                                :color="$station->is_enabled ? 'danger' : 'success'"
                                :icon="$station->is_enabled ? 'heroicon-m-pause-circle' : 'heroicon-m-play-circle'"
                                wire:click="toggleStation"
                            >
                                {{ $station->is_enabled
                                    ? __('filament.print_agent.pause_this_desk')
                                    : __('filament.print_agent.resume_this_desk') }}
                            </x-filament::button>
                        </div>
                    </div>

                    @if ($lastError)
                        <div class="mt-4 rounded-lg bg-danger-100 px-3 py-2 text-sm text-danger-800 dark:bg-danger-500/20 dark:text-danger-200">
                            {{ $lastError }}
                        </div>
                    @endif

                    @if ($activeJobTitle)
                        <div class="mt-4 flex items-center gap-3 rounded-lg border border-info-200 bg-white px-4 py-3 text-sm dark:border-info-500/30 dark:bg-black/20">
                            <x-filament::loading-indicator class="h-5 w-5 text-info-600 dark:text-info-400" />
                            <div class="min-w-0 flex-1">
                                <p class="font-medium text-info-800 dark:text-info-300">
                                    {{ trans_choice('filament.print_agent.printing_now', $activeCopies, ['count' => $activeCopies]) }}
                                </p>
                                <p class="truncate text-info-700 dark:text-info-200">{{ $activeJobTitle }}</p>
                            </div>
                            <x-filament::button size="sm" color="gray" wire:click="releaseActiveJob">
                                {{ __('filament.print_agent.cancel_job') }}
                            </x-filament::button>
                        </div>
                    @endif

                    @if ($probing)
                        <div class="mt-4 flex items-center gap-3 rounded-lg border border-info-200 bg-white px-4 py-3 text-sm dark:border-info-500/30 dark:bg-black/20">
                            <x-filament::loading-indicator class="h-5 w-5 text-info-600 dark:text-info-400" />
                            <p class="flex-1 text-info-800 dark:text-info-300">{{ __('filament.print_agent.probing') }}</p>
                            <x-filament::button size="sm" color="gray" wire:click="cancelProbe">
                                {{ __('filament.print_agent.cancel') }}
                            </x-filament::button>
                        </div>
                    @endif
                </div>
            @endif
        </div>

        {{-- ============================================================ --}}
        {{-- What is wrong, if anything                                   --}}
        {{-- ============================================================ --}}
        {{--
            Almost every "the printer doesn't work" is one of these, and five
            of the six are invisible from the printer itself. Failing checks
            are shown open; a healthy system collapses to one green line so the
            panel does not become wallpaper nobody reads.
        --}}
        @if ($failing->isNotEmpty())
            <div class="space-y-3">
                @foreach ($failing as $check)
                    <div @class([
                        'rounded-xl border p-4',
                        'border-danger-300 bg-danger-50 dark:border-danger-500/30 dark:bg-danger-500/10' => $check['severity'] === 'critical',
                        'border-warning-300 bg-warning-50 dark:border-warning-500/30 dark:bg-warning-500/10' => $check['severity'] === 'warning',
                        'border-gray-200 bg-gray-50 dark:border-white/10 dark:bg-white/5' => $check['severity'] === 'info',
                    ])>
                        <div class="flex items-start gap-3">
                            <x-filament::icon
                                :icon="$check['severity'] === 'critical' ? 'heroicon-o-exclamation-circle' : 'heroicon-o-information-circle'"
                                @class([
                                    'mt-0.5 h-5 w-5 shrink-0',
                                    'text-danger-600 dark:text-danger-400' => $check['severity'] === 'critical',
                                    'text-warning-600 dark:text-warning-400' => $check['severity'] === 'warning',
                                    'text-gray-500' => $check['severity'] === 'info',
                                ])
                            />
                            <div class="min-w-0 flex-1">
                                <p class="font-semibold text-gray-900 dark:text-gray-100">{{ $check['title'] }}</p>
                                <p class="mt-1 text-sm text-gray-700 dark:text-gray-300">{{ $check['body'] }}</p>

                                @if ($check['fix'])
                                    <div class="mt-2" x-data="{ copied: false }">
                                        <code
                                            x-ref="cmd"
                                            class="block overflow-x-auto rounded bg-white/80 px-2 py-1.5 font-mono text-xs text-gray-800 dark:bg-black/30 dark:text-gray-200"
                                        >{{ $check['fix'] }}</code>
                                        <button
                                            type="button"
                                            class="mt-1.5 inline-flex items-center gap-1 text-xs font-medium text-primary-600 hover:underline dark:text-primary-400"
                                            @click="navigator.clipboard.writeText($refs.cmd.textContent); copied = true; setTimeout(() => copied = false, 2000)"
                                        >
                                            <x-filament::icon icon="heroicon-m-clipboard-document" class="h-3.5 w-3.5" />
                                            <span x-text="copied ? @js(__('filament.print_agent.copied')) : @js(__('filament.print_agent.copy'))"></span>
                                        </button>
                                    </div>
                                @endif
                            </div>
                        </div>
                    </div>
                @endforeach
            </div>
        @else
            <div class="flex items-center gap-2 rounded-xl border border-success-200 bg-success-50 px-4 py-3 text-sm text-success-800 dark:border-success-500/30 dark:bg-success-500/10 dark:text-success-300">
                <x-filament::icon icon="heroicon-m-check-circle" class="h-5 w-5 shrink-0" />
                <span>{{ __('filament.print_agent.all_clear') }}</span>
            </div>
        @endif

        {{-- ============================================================ --}}
        {{-- Schedules                                                    --}}
        {{-- ============================================================ --}}
        <x-filament::section
            :heading="__('filament.print_agent.schedules')"
            :description="__('filament.print_agent.schedules_description')"
            icon="heroicon-o-calendar-days"
        >
            <div class="divide-y divide-gray-100 dark:divide-white/10">
                @forelse ($schedules as $schedule)
                    <div class="flex flex-wrap items-start justify-between gap-4 py-3 first:pt-0 last:pb-0">
                        <div class="min-w-0">
                            <div class="flex flex-wrap items-center gap-2">
                                <span class="font-medium text-gray-950 dark:text-white">{{ $schedule->name }}</span>
                                <x-filament::badge size="sm" :color="$schedule->statusColor()">
                                    @if ($schedule->isAutoPaused())
                                        {{ __('filament.print_agent.sched_auto_paused') }}
                                    @elseif (! $schedule->is_active)
                                        {{ __('filament.print_agent.sched_off') }}
                                    @else
                                        {{ __('filament.print_agent.sched_on') }}
                                    @endif
                                </x-filament::badge>
                            </div>

                            <p class="mt-0.5 text-sm text-gray-600 dark:text-gray-400">
                                {{ $schedule->frequencyLabel() }}
                                @if ($schedule->delivery === 'agent')
                                    ·
                                    {{ $schedule->targetStation
                                        ? __('filament.print_agent.sched_at', ['station' => $schedule->targetStation->name])
                                        : __('filament.print_agent.sched_any_station') }}
                                @elseif ($schedule->delivery === 'network')
                                    · {{ __('filament.equb_report.delivery_network') }}
                                @endif
                                · {{ $schedule->copies }}×
                            </p>

                            <p class="mt-0.5 text-xs text-gray-500 dark:text-gray-400">
                                @if ($schedule->is_active && $schedule->next_run_at)
                                    {{ __('filament.print_agent.sched_next', [
                                        'when' => $schedule->next_run_at->timezone($schedule->timezone)->translatedFormat('D j M, g:i A'),
                                    ]) }}
                                @endif
                                @if ($schedule->last_run_at)
                                    · {{ __('filament.print_agent.sched_last', [
                                        'when' => $schedule->last_run_at->diffForHumans(),
                                        'status' => __('filament.print_agent.run_'.($schedule->last_status ?: 'unknown')),
                                    ]) }}
                                @endif
                            </p>

                            @if ($schedule->isAutoPaused())
                                <p class="mt-1 rounded bg-danger-100 px-2 py-1 text-xs text-danger-800 dark:bg-danger-500/20 dark:text-danger-200">
                                    {{ $schedule->paused_reason }}
                                </p>
                            @elseif ($schedule->last_status === 'failed' && $schedule->last_error)
                                <p class="mt-1 text-xs text-danger-600 dark:text-danger-400">{{ $schedule->last_error }}</p>
                            @endif
                        </div>

                        <div class="flex shrink-0 items-center gap-2">
                            <x-filament::button size="xs" color="gray" wire:click="runScheduleNow({{ $schedule->id }})">
                                {{ __('filament.print_agent.run_now') }}
                            </x-filament::button>

                            {{ ($this->editScheduleAction)(['schedule' => $schedule->id]) }}

                            <x-filament::button
                                size="xs"
                                :color="$schedule->is_active ? 'warning' : 'success'"
                                wire:click="toggleSchedule({{ $schedule->id }})"
                            >
                                {{ $schedule->is_active ? __('filament.print_agent.turn_off') : __('filament.print_agent.turn_on') }}
                            </x-filament::button>

                            <x-filament::icon-button
                                icon="heroicon-m-trash"
                                color="danger"
                                size="sm"
                                :label="__('filament.print_agent.delete')"
                                wire:click="deleteSchedule({{ $schedule->id }})"
                                wire:confirm="{{ __('filament.print_agent.delete_schedule_confirm') }}"
                            />
                        </div>
                    </div>
                @empty
                    <div class="py-8 text-center">
                        <p class="text-sm text-gray-500 dark:text-gray-400">{{ __('filament.print_agent.no_schedules') }}</p>
                        <p class="mt-1 text-xs text-gray-400 dark:text-gray-500">{{ __('filament.print_agent.no_schedules_hint') }}</p>
                    </div>
                @endforelse
            </div>
        </x-filament::section>

        {{-- ============================================================ --}}
        {{-- Queue and history                                            --}}
        {{-- ============================================================ --}}
        <div class="grid gap-4 lg:grid-cols-2">
            <x-filament::section
                :heading="__('filament.print_agent.queue')"
                icon="heroicon-o-queue-list"
            >
                @if ($retrying > 0)
                    <x-slot name="afterHeader">
                        <x-filament::badge size="sm" color="warning">
                            {{ __('filament.print_agent.n_retrying', ['count' => $retrying]) }}
                        </x-filament::badge>
                    </x-slot>
                @endif

                <div class="divide-y divide-gray-100 dark:divide-white/10">
                    @forelse ($queue as $job)
                        <div class="flex items-start justify-between gap-3 py-3 first:pt-0 last:pb-0">
                            <div class="min-w-0">
                                <div class="flex flex-wrap items-center gap-2">
                                    <span class="truncate font-medium text-gray-950 dark:text-white">{{ $job->title }}</span>
                                    <x-filament::badge size="sm" :color="$job->statusColor()">{{ $job->statusLabel() }}</x-filament::badge>
                                </div>
                                <div class="mt-0.5 text-xs text-gray-500 dark:text-gray-400">
                                    {{ $job->created_at->translatedFormat('j M') }} {{ $job->created_at->format('g:i A') }}
                                    · {{ $job->paperLabel() }} · {{ $job->copies }}×
                                    @if ($job->targetStation)
                                        · {{ __('filament.print_agent.for_station', ['station' => $job->targetStation->name]) }}
                                    @endif
                                    @if ($job->claimed_by)
                                        · {{ __('filament.print_agent.claimed_by') }} {{ $job->claimed_by }}
                                    @endif
                                </div>
                                @if ($job->error)
                                    <p class="mt-1 text-xs text-danger-600 dark:text-danger-400">
                                        {{ $job->error }}
                                        @if ($job->attemptsLeft() > 0)
                                            · {{ __('filament.print_agent.attempts_left', ['count' => $job->attemptsLeft()]) }}
                                        @endif
                                    </p>
                                @endif
                            </div>

                            <div class="flex shrink-0 items-center gap-1">
                                @if ($this->stationId && $job->status === \App\Models\ReportPrintJob::STATUS_QUEUED)
                                    <x-filament::button size="xs" color="gray" wire:click="printJobHere({{ $job->id }})">
                                        {{ __('filament.print_agent.print_here') }}
                                    </x-filament::button>
                                @endif
                                <x-filament::icon-button
                                    icon="heroicon-m-x-mark"
                                    color="gray"
                                    size="sm"
                                    :label="__('filament.print_agent.cancel_job')"
                                    wire:click="cancelJob({{ $job->id }})"
                                />
                            </div>
                        </div>
                    @empty
                        <p class="py-8 text-center text-sm text-gray-500 dark:text-gray-400">
                            {{ __('filament.print_agent.queue_empty') }}
                        </p>
                    @endforelse
                </div>
            </x-filament::section>

            <x-filament::section :heading="__('filament.print_agent.recent')" icon="heroicon-o-clock">
                <div class="divide-y divide-gray-100 dark:divide-white/10">
                    @forelse ($history as $job)
                        <div class="flex items-start justify-between gap-3 py-3 first:pt-0 last:pb-0">
                            <div class="min-w-0">
                                <div class="flex flex-wrap items-center gap-2">
                                    <span class="truncate font-medium text-gray-950 dark:text-white">{{ $job->title }}</span>
                                    <x-filament::badge size="sm" :color="$job->statusColor()">{{ $job->statusLabel() }}</x-filament::badge>
                                    @if ($job->status === \App\Models\ReportPrintJob::STATUS_PRINTED && ! $job->printed_silently)
                                        <x-filament::badge size="sm" color="warning">
                                            {{ __('filament.print_agent.needed_a_click') }}
                                        </x-filament::badge>
                                    @endif
                                </div>
                                <div class="mt-0.5 text-xs text-gray-500 dark:text-gray-400">
                                    {{ ($job->printed_at ?? $job->updated_at)->translatedFormat('j M') }}
                                    {{ ($job->printed_at ?? $job->updated_at)->format('g:i A') }}
                                    @if ($job->station)
                                        · {{ $job->station->name }}
                                    @endif
                                </div>
                                @if ($job->error)
                                    <p class="mt-1 text-xs text-danger-600 dark:text-danger-400">{{ $job->error }}</p>
                                @endif
                            </div>

                            @if ($job->status === \App\Models\ReportPrintJob::STATUS_FAILED)
                                <x-filament::button size="xs" color="gray" wire:click="retryJob({{ $job->id }})">
                                    {{ __('filament.print_agent.retry') }}
                                </x-filament::button>
                            @endif
                        </div>
                    @empty
                        <p class="py-8 text-center text-sm text-gray-500 dark:text-gray-400">
                            {{ __('filament.print_agent.no_recent') }}
                        </p>
                    @endforelse
                </div>
            </x-filament::section>
        </div>

        {{-- ============================================================ --}}
        {{-- Every desk                                                   --}}
        {{-- ============================================================ --}}
        @if ($stations->count() > 1)
            <x-filament::section
                :heading="__('filament.print_agent.all_stations')"
                :description="__('filament.print_agent.all_stations_description')"
                icon="heroicon-o-computer-desktop"
                collapsible
            >
                <div class="divide-y divide-gray-100 dark:divide-white/10">
                    @foreach ($stations as $other)
                        @php $otherState = $other->state($master); @endphp
                        <div class="flex flex-wrap items-center justify-between gap-3 py-3 first:pt-0 last:pb-0">
                            <div class="min-w-0">
                                <div class="flex flex-wrap items-center gap-2">
                                    <span class="font-medium text-gray-950 dark:text-white">{{ $other->displayName() }}</span>
                                    <x-filament::badge size="sm" :color="$other->stateColor($otherState)">
                                        {{ __('filament.print_agent.state_'.$otherState) }}
                                    </x-filament::badge>
                                    @if ($other->id === $this->stationId)
                                        <x-filament::badge size="sm" color="primary">{{ __('filament.print_agent.this_computer') }}</x-filament::badge>
                                    @endif
                                    <x-filament::badge size="sm" color="gray">{{ $other->paperLabel() }}</x-filament::badge>
                                    @if ($other->silent_ready)
                                        <x-filament::badge size="sm" color="success">{{ __('filament.print_agent.silent_short') }}</x-filament::badge>
                                    @endif
                                </div>
                                <p class="mt-0.5 text-xs text-gray-500 dark:text-gray-400">
                                    {{ $other->last_seen_at
                                        ? __('filament.print_agent.last_seen', ['ago' => $other->lastSeenLabel()])
                                        : __('filament.print_agent.never_seen') }}
                                    · {{ __('filament.print_agent.printed_n', ['count' => number_format($other->printed_count)]) }}
                                    @if ($other->failed_count > 0)
                                        · {{ __('filament.print_agent.failed_n', ['count' => number_format($other->failed_count)]) }}
                                    @endif
                                </p>
                                @if ($other->last_error)
                                    <p class="mt-1 text-xs text-danger-600 dark:text-danger-400">{{ $other->last_error }}</p>
                                @endif
                            </div>

                            <div class="flex shrink-0 items-center gap-2">
                                <x-filament::button
                                    size="xs"
                                    :color="$other->is_enabled ? 'warning' : 'success'"
                                    wire:click="toggleStationById({{ $other->id }})"
                                >
                                    {{ $other->is_enabled ? __('filament.print_agent.turn_off') : __('filament.print_agent.turn_on') }}
                                </x-filament::button>

                                @if ($other->id !== $this->stationId && ! $other->isOnline())
                                    <x-filament::icon-button
                                        icon="heroicon-m-trash"
                                        color="danger"
                                        size="sm"
                                        :label="__('filament.print_agent.forget')"
                                        wire:click="forgetStation({{ $other->id }})"
                                        wire:confirm="{{ __('filament.print_agent.forget_confirm') }}"
                                    />
                                @endif
                            </div>
                        </div>
                    @endforeach
                </div>
            </x-filament::section>
        @endif

        {{-- ============================================================ --}}
        {{-- How this works                                               --}}
        {{-- ============================================================ --}}
        <x-filament::section
            :heading="__('filament.print_agent.how_it_works')"
            icon="heroicon-o-information-circle"
            collapsible
            collapsed
        >
            <ol class="ml-4 list-decimal space-y-2 text-sm text-gray-600 dark:text-gray-300">
                <li>{{ __('filament.print_agent.step_1') }}</li>
                <li>{{ __('filament.print_agent.step_2') }}</li>
                <li>{{ __('filament.print_agent.step_3') }}</li>
                <li>{{ __('filament.print_agent.step_4') }}</li>
            </ol>

            <div class="mt-4 rounded-lg border border-gray-200 bg-gray-50 p-3 text-sm text-gray-700 dark:border-white/10 dark:bg-white/5 dark:text-gray-300">
                <p class="font-medium text-gray-900 dark:text-gray-100">{{ __('filament.print_agent.silent_title') }}</p>
                <p class="mt-1">{{ __('filament.print_agent.silent_body') }}</p>

                <div class="mt-2" x-data="{ copied: false }">
                    <code
                        x-ref="cmd"
                        class="block overflow-x-auto rounded bg-white/80 px-2 py-1.5 font-mono text-xs text-gray-800 dark:bg-black/30 dark:text-gray-200"
                    >{{ $this->kioskCommand() }}</code>
                    <button
                        type="button"
                        class="mt-1.5 inline-flex items-center gap-1 text-xs font-medium text-primary-600 hover:underline dark:text-primary-400"
                        @click="navigator.clipboard.writeText($refs.cmd.textContent); copied = true; setTimeout(() => copied = false, 2000)"
                    >
                        <x-filament::icon icon="heroicon-m-clipboard-document" class="h-3.5 w-3.5" />
                        <span x-text="copied ? @js(__('filament.print_agent.copied')) : @js(__('filament.print_agent.copy'))"></span>
                    </button>
                </div>

                <p class="mt-2 text-xs text-gray-500 dark:text-gray-400">{{ __('filament.print_agent.silent_note') }}</p>
            </div>

            @if ($station)
                <p class="mt-3 text-xs text-gray-500 dark:text-gray-400">
                    {{ __('filament.print_agent.agent_id') }}:
                    <code class="font-mono">{{ $station->key }}</code>
                    @if ($station->browser)
                        · {{ $station->browser }}
                    @endif
                </p>
            @endif
        </x-filament::section>

        {{-- ============================================================ --}}
        {{-- The engine                                                   --}}
        {{-- ============================================================ --}}
        {{--
            Outside every polled region and carrying wire:ignore, so a Livewire
            re-render can never replace the frame while a print is in progress.

            Positioned off-screen rather than display:none: a hidden iframe is
            not laid out, and a document with no layout prints blank in Chrome
            and Edge.
        --}}
        <div
            wire:ignore
            x-data="{
                busy: false,
                guard: null,
                elapsed: 0,
                probing: false,

                /*
                 * Which desk this browser is.
                 *
                 * Held in the browser's own storage so the same machine
                 * rejoins as itself after a refresh or a reboot. The fallback
                 * chain matters: a locked-down or private-mode browser throws
                 * on localStorage, and a station that changes identity every
                 * reload is worse than useless — every one of them would look
                 * online forever.
                 */
                identify() {
                    let key = null;

                    try { key = window.localStorage.getItem('niya.print.station'); } catch (e) {}
                    if (!key) { try { key = window.sessionStorage.getItem('niya.print.station'); } catch (e) {} }

                    if (!key) {
                        key = 'st' + (
                            (window.crypto && window.crypto.randomUUID)
                                ? window.crypto.randomUUID().replace(/-/g, '')
                                : (Math.random().toString(36).slice(2) + Date.now().toString(36))
                        );
                        key = key.slice(0, 40);

                        try { window.localStorage.setItem('niya.print.station', key); } catch (e) {}
                        try { window.sessionStorage.setItem('niya.print.station', key); } catch (e) {}
                    }

                    $wire.connect(key, {
                        browser: (navigator.userAgent || '').slice(0, 120),
                        platform: (navigator.userAgentData && navigator.userAgentData.platform) || navigator.platform || '',
                    });
                },

                /* ---- printing a job ---------------------------------- */

                start(detail) {
                    if (this.busy) return;

                    const frame = this.$refs.frame;
                    if (!frame) return;

                    this.busy = true;
                    this.elapsed = 0;

                    // A document that never loads must not wedge the agent.
                    this.guard = setTimeout(() => {
                        this.finish(false, @js(__('filament.print_agent.err_timeout')));
                    }, {{ $this->loadTimeoutMs() }});

                    frame.onload = () => {
                        frame.onload = null;
                        this.spool(frame, Math.max(1, detail.copies || 1));
                    };

                    frame.onerror = () => this.finish(false, @js(__('filament.print_agent.err_load')));

                    frame.src = detail.url;
                },

                spool(frame, copies) {
                    let done = 0;

                    const one = () => {
                        if (!this.busy) return;

                        try {
                            frame.contentWindow.focus();

                            /*
                             * The measurement, and the reason this is timed at
                             * all. print() blocks for as long as the dialog is
                             * open; with --kiosk-printing there is no dialog
                             * and it returns in a few milliseconds. That gap is
                             * the only evidence a browser gives that silent
                             * printing is on, so it is taken on every job
                             * rather than only when somebody runs the check.
                             */
                            const started = performance.now();
                            frame.contentWindow.print();
                            const took = Math.round(performance.now() - started);

                            this.elapsed = Math.max(this.elapsed, took);
                        } catch (e) {
                            this.finish(false, @js(__('filament.print_agent.err_blocked')) + ' ' + (e.message || ''));
                            return;
                        }

                        if (++done >= copies) {
                            this.finish(true);
                            return;
                        }

                        /*
                         * A pause between copies. Under kiosk printing print()
                         * returns before the spooler has taken the document,
                         * and firing three in the same tick makes Chrome
                         * collapse them into one sheet.
                         */
                        setTimeout(one, 1200);
                    };

                    one();
                },

                finish(ok, reason) {
                    if (!this.busy) return;

                    this.busy = false;
                    clearTimeout(this.guard);

                    // The document holds member names, phone numbers and
                    // amounts. Don't leave it parked in a frame all day.
                    const frame = this.$refs.frame;
                    if (frame) {
                        frame.onload = null;
                        frame.onerror = null;
                        try { frame.src = 'about:blank'; } catch (e) {}
                    }

                    ok ? $wire.confirmPrinted(this.elapsed) : $wire.reportFailure(reason || '');
                },

                /* ---- the silent-printing check ----------------------- */

                probe() {
                    const frame = this.$refs.probe;
                    if (!frame || this.probing) return;

                    this.probing = true;

                    const guard = setTimeout(() => {
                        if (!this.probing) return;
                        this.probing = false;
                        $wire.cancelProbe();
                    }, 180000);

                    frame.onload = () => {
                        frame.onload = null;

                        try {
                            frame.contentWindow.focus();
                            const started = performance.now();
                            frame.contentWindow.print();
                            const took = Math.round(performance.now() - started);

                            clearTimeout(guard);
                            this.probing = false;
                            $wire.recordProbe(took);
                        } catch (e) {
                            clearTimeout(guard);
                            this.probing = false;
                            $wire.cancelProbe();
                        }
                    };

                    frame.srcdoc = @js($this->probeDocument());
                },
            }"
            x-init="identify()"
            x-on:print-job-ready.window="start($event.detail)"
            x-on:print-probe.window="probe()"
        >
            <iframe
                x-ref="frame"
                title="print-surface"
                aria-hidden="true"
                tabindex="-1"
                style="position: fixed; left: -10000px; top: 0; width: 794px; height: 1123px; border: 0;"
            ></iframe>

            <iframe
                x-ref="probe"
                title="print-probe"
                aria-hidden="true"
                tabindex="-1"
                style="position: fixed; left: -10000px; top: 0; width: 794px; height: 400px; border: 0;"
            ></iframe>
        </div>

    </div>
</x-filament-panels::page>
