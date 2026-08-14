<x-filament-panels::page>
    @php
        $schedulerOk = $this->schedulerIsRunning();
        $lastSeen = $this->schedulerLastSeen();
        $waiting = $this->waitingJobs();
        $recent = $this->recentlyPrinted();
    @endphp

    <div class="space-y-6">

        {{-- ============================================================ --}}
        {{-- Scheduler health                                             --}}
        {{-- ============================================================ --}}
        {{--
            The single most common reason nothing prints: Laravel's scheduler
            is not running, so no job is ever queued for this page to collect.
            From the outside that looks identical to "no reports were due", so
            it is stated outright rather than left to be inferred.
        --}}
        @unless ($schedulerOk)
            <div class="rounded-xl border border-danger-300 bg-danger-50 p-4 dark:border-danger-500/30 dark:bg-danger-500/10">
                <div class="flex items-start gap-3">
                    <x-filament::icon icon="heroicon-o-exclamation-triangle" class="mt-0.5 h-5 w-5 shrink-0 text-danger-600 dark:text-danger-400" />
                    <div class="min-w-0">
                        <p class="font-semibold text-danger-800 dark:text-danger-300">
                            {{ __('filament.print_agent.scheduler_down') }}
                        </p>
                        <p class="mt-1 text-sm text-danger-700 dark:text-danger-200">
                            {{ $lastSeen
                                ? __('filament.print_agent.scheduler_last_seen', ['ago' => $lastSeen])
                                : __('filament.print_agent.scheduler_never') }}
                            {{ __('filament.print_agent.scheduler_fix') }}
                        </p>
                        <div class="mt-2" x-data="{ copied: false }">
                            <code class="block overflow-x-auto rounded bg-white/70 px-2 py-1.5 font-mono text-xs text-gray-800 dark:bg-black/30 dark:text-gray-200"
                                  x-ref="cmd">php artisan schedule:work</code>
                            <button
                                type="button"
                                class="mt-1.5 inline-flex items-center gap-1 text-xs font-medium text-danger-800 hover:underline dark:text-danger-300"
                                @click="navigator.clipboard.writeText($refs.cmd.textContent); copied = true; setTimeout(() => copied = false, 2000)"
                            >
                                <x-filament::icon icon="heroicon-m-clipboard-document" class="h-3.5 w-3.5" />
                                <span x-text="copied ? @js(__('filament.print_agent.copied')) : @js(__('filament.print_agent.copy'))"></span>
                            </button>
                        </div>
                        <p class="mt-2 text-xs text-danger-700 dark:text-danger-200">
                            {{ __('filament.print_agent.scheduler_note') }}
                        </p>
                    </div>
                </div>
            </div>
        @endunless

        {{-- ============================================================ --}}
        {{-- Status banner                                                --}}
        {{-- ============================================================ --}}
        <div @class([
            'rounded-xl border p-5 transition',
            'border-success-300 bg-success-50 dark:border-success-500/30 dark:bg-success-500/10' => $listening,
            'border-gray-200 bg-gray-50 dark:border-white/10 dark:bg-white/5' => ! $listening,
        ])>
            <div class="flex flex-wrap items-center justify-between gap-4">
                <div class="flex items-center gap-4">
                    <span class="relative flex h-3 w-3">
                        @if ($listening)
                            <span class="absolute inline-flex h-full w-full animate-ping rounded-full bg-success-400 opacity-75"></span>
                            <span class="relative inline-flex h-3 w-3 rounded-full bg-success-500"></span>
                        @else
                            <span class="relative inline-flex h-3 w-3 rounded-full bg-gray-400"></span>
                        @endif
                    </span>
                    <div>
                        <p @class([
                            'text-base font-semibold',
                            'text-success-800 dark:text-success-300' => $listening,
                            'text-gray-700 dark:text-gray-200' => ! $listening,
                        ])>
                            {{ $listening ? __('filament.print_agent.listening') : __('filament.print_agent.stopped') }}
                        </p>
                        <p class="mt-0.5 text-sm text-gray-600 dark:text-gray-400">
                            {{ $listening ? __('filament.print_agent.listening_help') : __('filament.print_agent.stopped_help') }}
                        </p>
                    </div>
                </div>

                <div class="flex items-center gap-6 text-sm">
                    <div>
                        <p class="text-xs uppercase tracking-wide text-gray-500 dark:text-gray-400">{{ __('filament.print_agent.waiting') }}</p>
                        <p class="text-xl font-semibold tabular-nums text-gray-950 dark:text-white">{{ $this->queuedCount() }}</p>
                    </div>
                    <div>
                        <p class="text-xs uppercase tracking-wide text-gray-500 dark:text-gray-400">{{ __('filament.print_agent.printed_session') }}</p>
                        <p class="text-xl font-semibold tabular-nums text-gray-950 dark:text-white">{{ $printedThisSession }}</p>
                    </div>
                    @if ($lastPrintedAt)
                        <div>
                            <p class="text-xs uppercase tracking-wide text-gray-500 dark:text-gray-400">{{ __('filament.print_agent.last_printed') }}</p>
                            <p class="text-xl font-semibold tabular-nums text-gray-950 dark:text-white">{{ $lastPrintedAt }}</p>
                        </div>
                    @endif
                </div>
            </div>

            @if ($lastError)
                <div class="mt-3 rounded-lg bg-danger-100 px-3 py-2 text-sm text-danger-800 dark:bg-danger-500/20 dark:text-danger-200">
                    {{ $lastError }}
                </div>
            @endif
        </div>

        {{-- ============================================================ --}}
        {{-- The engine                                                   --}}
        {{-- ============================================================ --}}
        {{--
            Polling is wire:poll rather than a JS timer, so Livewire pauses it
            when the tab is hidden and resumes on focus. The interval only
            exists while listening, so a tab parked on "stopped" is silent.
        --}}
        <div @if ($listening) wire:poll.10s="tick" @endif>
            @if ($activeJobTitle)
                <div class="flex items-center gap-3 rounded-lg border border-info-200 bg-info-50 px-4 py-3 text-sm dark:border-info-500/30 dark:bg-info-500/10">
                    <x-filament::loading-indicator class="h-5 w-5 text-info-600 dark:text-info-400" />
                    <div class="flex-1">
                        <p class="font-medium text-info-800 dark:text-info-300">
                            {{ __('filament.print_agent.printing_now') }}
                        </p>
                        <p class="text-info-700 dark:text-info-200">{{ $activeJobTitle }}</p>
                    </div>
                    <x-filament::button size="sm" color="gray" wire:click="releaseActiveJob">
                        {{ __('filament.print_agent.cancel_job') }}
                    </x-filament::button>
                </div>
            @endif
        </div>

        {{--
            The print surface lives outside the polled region and carries
            wire:ignore, so a Livewire re-render can never replace the iframe
            while a print dialog is open on top of it.

            It is positioned off-screen rather than display:none: a hidden
            iframe is not laid out, and a document with no layout prints blank
            in Chrome and Edge.
        --}}
        <div
            wire:ignore
            x-data="{
                busy: false,
                guard: null,

                start(detail) {
                    if (this.busy) return;

                    const frame = this.$refs.frame;
                    if (!frame) return;

                    this.busy = true;

                    // A document that never loads must not wedge the agent.
                    this.guard = setTimeout(() => {
                        if (!this.busy) return;
                        this.finish(false, 'Timed out while loading the report.');
                    }, 45000);

                    frame.onload = () => {
                        try {
                            frame.contentWindow.focus();

                            for (let i = 0; i < (detail.copies || 1); i++) {
                                // print() blocks until the dialog closes, so a
                                // second copy cannot start before the first ends.
                                frame.contentWindow.print();
                            }

                            this.finish(true);
                        } catch (e) {
                            this.finish(false, 'The browser blocked printing: ' + e.message);
                        }
                    };

                    frame.onerror = () => this.finish(false, 'The report could not be loaded.');

                    frame.src = detail.url;
                },

                finish(ok, reason) {
                    if (!this.busy) return;
                    this.busy = false;
                    clearTimeout(this.guard);

                    ok ? $wire.confirmPrinted() : $wire.reportFailure(reason);
                },
            }"
            x-on:print-job-ready.window="start($event.detail)"
        >
            <iframe
                x-ref="frame"
                title="print-surface"
                aria-hidden="true"
                tabindex="-1"
                style="position: fixed; left: -10000px; top: 0; width: 794px; height: 1123px; border: 0;"
            ></iframe>
        </div>

        {{-- ============================================================ --}}
        {{-- Queue                                                        --}}
        {{-- ============================================================ --}}
        <div class="grid gap-4 lg:grid-cols-2">
            <x-filament::section :heading="__('filament.print_agent.queue')" icon="heroicon-o-queue-list">
                <div class="divide-y divide-gray-100 dark:divide-gray-800">
                    @forelse ($waiting as $job)
                        <div class="flex items-start justify-between gap-3 py-3 first:pt-0">
                            <div class="min-w-0">
                                <div class="flex items-center gap-2">
                                    <span class="truncate font-medium text-gray-950 dark:text-white">{{ $job->title }}</span>
                                    <x-filament::badge size="sm" :color="$job->statusColor()">{{ $job->status }}</x-filament::badge>
                                </div>
                                <div class="mt-0.5 text-xs text-gray-500 dark:text-gray-400">
                                    {{ $job->created_at->translatedFormat('j M') }}
                                    {{ $job->created_at->format('g:i A') }} ·
                                    {{ $job->paper }} · {{ $job->copies }}×
                                    @if ($job->claimed_by)
                                        · {{ __('filament.print_agent.claimed_by') }} {{ $job->claimed_by }}
                                    @endif
                                </div>
                                @if (! empty($job->summary['collected']))
                                    <div class="mt-0.5 text-xs text-gray-500 dark:text-gray-400">
                                        {{ $job->summary['range_label'] ?? '' }} ·
                                        {{ number_format((float) $job->summary['collected'], 2) }} ETB
                                    </div>
                                @endif
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
                <div class="divide-y divide-gray-100 dark:divide-gray-800">
                    @forelse ($recent as $job)
                        <div class="py-3 first:pt-0">
                            <div class="flex items-center gap-2">
                                <span class="truncate font-medium text-gray-950 dark:text-white">{{ $job->title }}</span>
                                <x-filament::badge size="sm" :color="$job->statusColor()">{{ $job->status }}</x-filament::badge>
                            </div>
                            <div class="mt-0.5 text-xs text-gray-500 dark:text-gray-400">
                                {{ ($job->printed_at ?? $job->updated_at)->translatedFormat('j M') }}
                                {{ ($job->printed_at ?? $job->updated_at)->format('g:i A') }}
                            </div>
                            @if ($job->error)
                                <div class="mt-1 text-xs text-danger-600 dark:text-danger-400">{{ $job->error }}</div>
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
        {{-- How this works                                               --}}
        {{-- ============================================================ --}}
        <x-filament::section
            :heading="__('filament.print_agent.how_it_works')"
            icon="heroicon-o-information-circle"
            collapsible
        >
            <ol class="ml-4 list-decimal space-y-2 text-sm text-gray-600 dark:text-gray-300">
                <li>{{ __('filament.print_agent.step_1') }}</li>
                <li>{{ __('filament.print_agent.step_2') }}</li>
                <li>{{ __('filament.print_agent.step_3') }}</li>
                <li>{{ __('filament.print_agent.step_4') }}</li>
            </ol>

            <div class="mt-4 rounded-lg border border-warning-200 bg-warning-50 p-3 text-sm text-warning-800 dark:border-warning-500/30 dark:bg-warning-500/10 dark:text-warning-300">
                <p class="font-medium">{{ __('filament.print_agent.tip_title') }}</p>
                <p class="mt-1">{{ __('filament.print_agent.tip_body') }}</p>

                {{-- Without this flag the browser opens its print dialog and
                     waits for a click, which defeats a schedule that fires at
                     08:00 before anyone is at the desk. --}}
                <p class="mt-3 font-medium">{{ __('filament.print_agent.silent_title') }}</p>
                <p class="mt-1">{{ __('filament.print_agent.silent_body') }}</p>

                <div class="mt-2" x-data="{ copied: false }">
                    <code class="block overflow-x-auto rounded bg-white/70 px-2 py-1.5 font-mono text-xs text-gray-800 dark:bg-black/30 dark:text-gray-200"
                          x-ref="cmd">msedge.exe --kiosk-printing</code>
                    <button
                        type="button"
                        class="mt-1.5 inline-flex items-center gap-1 text-xs font-medium text-warning-800 hover:underline dark:text-warning-300"
                        @click="navigator.clipboard.writeText($refs.cmd.textContent); copied = true; setTimeout(() => copied = false, 2000)"
                    >
                        <x-filament::icon icon="heroicon-m-clipboard-document" class="h-3.5 w-3.5" />
                        <span x-text="copied ? @js(__('filament.print_agent.copied')) : @js(__('filament.print_agent.copy'))"></span>
                    </button>
                </div>

                <p class="mt-2 text-xs">{{ __('filament.print_agent.silent_note') }}</p>
            </div>

            <p class="mt-3 text-xs text-gray-500 dark:text-gray-400">
                {{ __('filament.print_agent.agent_id') }}: <code class="font-mono">{{ $agentId }}</code>
                @if ($schedulerOk && $lastSeen)
                    · {{ __('filament.print_agent.scheduler_ok', ['ago' => $lastSeen]) }}
                @endif
            </p>
        </x-filament::section>

    </div>
</x-filament-panels::page>
