<x-filament-panels::page>
    @php
        $money = fn ($v) => number_format((float) $v, 2);
        $fee = $this->feeExample();
        $members = $this->previewMembers();
        $dirty = $this->isDirty();

        $statusColour = fn (string $s) => match ($s) {
            'ahead' => 'success',
            'current' => 'primary',
            'warned' => 'warning',
            default => 'danger',
        };

        $barColour = fn (array $row) => $row['blocked']
            ? 'bg-danger-500'
            : match ($row['status']) {
                'ahead' => 'bg-success-500',
                'warned' => 'bg-warning-500',
                default => 'bg-primary-500',
            };

        $widest = collect($members)->max('odds') ?: 1;
    @endphp

    <div class="space-y-6">

        {{-- ============================================================ --}}
        {{-- Unsaved changes                                              --}}
        {{--                                                              --}}
        {{-- The preview below moves the moment a field is touched, which --}}
        {{-- makes it easy to believe the change is already in force. It  --}}
        {{-- is not, and on a page that decides who receives money that   --}}
        {{-- is worth stating plainly rather than implying.               --}}
        {{-- ============================================================ --}}
        @if ($dirty)
            <div class="flex flex-wrap items-center justify-between gap-3 rounded-xl border border-warning-300 bg-warning-50 px-4 py-3 dark:border-warning-500/30 dark:bg-warning-500/10">
                <p class="flex items-center gap-2 text-sm font-medium text-warning-800 dark:text-warning-300">
                    <x-filament::icon icon="heroicon-m-exclamation-triangle" class="h-5 w-5" />
                    {{ __('filament.draw_settings.unsaved') }}
                </p>
                <x-filament::button wire:click="save" size="sm" icon="heroicon-m-check">
                    {{ __('filament.draw_settings.save') }}
                </x-filament::button>
            </div>
        @endif

        {{-- ============================================================ --}}
        {{-- The simulator                                                --}}
        {{-- ============================================================ --}}
        <x-filament::section
            :heading="__('filament.draw_settings.simulator')"
            :description="__('filament.draw_settings.simulator_description')"
            icon="heroicon-o-beaker"
        >
            <div class="space-y-3">
                @foreach ($members as $row)
                    <div class="flex flex-col gap-2 sm:flex-row sm:items-center sm:gap-4">
                        <div class="w-full sm:w-64 sm:shrink-0">
                            <div class="flex items-center gap-2">
                                <span class="text-sm font-medium text-gray-950 dark:text-white">{{ $row['name'] }}</span>
                                <x-filament::badge :color="$statusColour($row['status'])" size="sm">
                                    {{ __('filament.equb_standing.' . $row['status']) }}
                                </x-filament::badge>
                            </div>
                            <p class="text-xs text-gray-500 dark:text-gray-400">{{ $row['note'] }}</p>
                        </div>

                        <div class="flex flex-1 items-center gap-3">
                            <div class="h-7 flex-1 overflow-hidden rounded-lg bg-gray-100 dark:bg-white/5">
                                {{-- Scaled against the widest bar rather than
                                     against 100%, or the whole chart sits in
                                     the left fifth and the differences that
                                     matter become invisible. --}}
                                <div
                                    class="flex h-full items-center justify-end rounded-lg px-2 transition-all duration-300 {{ $barColour($row) }}"
                                    style="width: {{ $row['odds'] > 0 ? max(6, ($row['odds'] / $widest) * 100) : 0 }}%"
                                >
                                    @if ($row['odds'] > 0)
                                        <span class="text-xs font-semibold tabular-nums text-white">{{ number_format($row['odds'], 1) }}%</span>
                                    @endif
                                </div>
                            </div>

                            <div class="w-28 shrink-0 text-right">
                                @if ($row['blocked'])
                                    <span class="text-xs font-medium text-danger-600 dark:text-danger-400">
                                        {{ __('filament.draw_settings.out_of_draw') }}
                                    </span>
                                @else
                                    <span class="text-xs text-gray-500 dark:text-gray-400">
                                        {{ __('filament.draw_settings.weight_of', ['weight' => number_format($row['weight'], 3)]) }}
                                    </span>
                                @endif
                            </div>
                        </div>
                    </div>
                @endforeach
            </div>

            <p class="mt-4 border-t border-gray-100 pt-3 text-xs text-gray-500 dark:border-white/5 dark:text-gray-400">
                {{ __('filament.draw_settings.simulator_note') }}
            </p>
        </x-filament::section>

        {{-- ============================================================ --}}
        {{-- Two sentences that say what the numbers mean                 --}}
        {{-- ============================================================ --}}
        <div class="grid gap-4 lg:grid-cols-3">
            <x-filament::section class="!p-0">
                <div class="p-4">
                    <p class="text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">
                        {{ __('filament.draw_settings.on_1000') }}
                    </p>
                    <div class="mt-2 space-y-1.5 text-sm">
                        <div class="flex items-center justify-between">
                            <span class="text-gray-600 dark:text-gray-300">{{ __('filament.equb_report.collected') }}</span>
                            <span class="font-medium tabular-nums text-gray-950 dark:text-white">{{ $money($fee['collected']) }}</span>
                        </div>
                        <div class="flex items-center justify-between">
                            <span class="text-gray-600 dark:text-gray-300">{{ __('filament.equb_report.members_money') }}</span>
                            <span class="font-medium tabular-nums text-primary-600 dark:text-primary-400">{{ $money($fee['members']) }}</span>
                        </div>
                        <div class="flex items-center justify-between border-t border-gray-100 pt-1.5 dark:border-white/5">
                            <span class="font-medium text-gray-950 dark:text-white">{{ __('filament.equb_report.service_fee') }}</span>
                            <span class="font-semibold tabular-nums text-success-600 dark:text-success-400">{{ $money($fee['fee']) }}</span>
                        </div>
                    </div>
                    <p class="mt-2 text-xs text-gray-500 dark:text-gray-400">
                        {{ $fee['percent'] }}% · {{ $fee['model'] }}
                    </p>
                </div>
            </x-filament::section>

            <x-filament::section class="!p-0">
                <div class="p-4">
                    <p class="text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">
                        {{ __('filament.draw_settings.what_happens') }}
                    </p>
                    <p class="mt-2 text-sm leading-relaxed text-gray-700 dark:text-gray-300">
                        {{ $this->arrearsSentence() }}
                    </p>
                </div>
            </x-filament::section>

            <x-filament::section class="!p-0">
                <div class="p-4">
                    <p class="text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">
                        {{ __('filament.draw_settings.integrity') }}
                    </p>
                    <p class="mt-2 text-sm leading-relaxed text-gray-700 dark:text-gray-300">
                        {{ $this->integritySentence() }}
                    </p>
                </div>
            </x-filament::section>
        </div>

        {{-- ============================================================ --}}
        {{-- The rules themselves                                         --}}
        {{-- ============================================================ --}}
        <form wire:submit="save">
            {{ $this->form }}

            <div class="mt-6 flex flex-wrap items-center gap-3">
                <x-filament::button type="submit" icon="heroicon-m-check">
                    {{ __('filament.draw_settings.save') }}
                </x-filament::button>

                <x-filament::button
                    type="button"
                    color="gray"
                    icon="heroicon-m-arrow-uturn-left"
                    wire:click="restoreDefaults"
                >
                    {{ __('filament.draw_settings.restore') }}
                </x-filament::button>

                <span class="text-sm text-gray-500 dark:text-gray-400">
                    <span wire:loading wire:target="data,save,restoreDefaults">{{ __('filament.equb_report.updating') }}…</span>
                </span>
            </div>
        </form>
    </div>
</x-filament-panels::page>
