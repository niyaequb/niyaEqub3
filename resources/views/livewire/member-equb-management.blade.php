{{-- Layout: left 1/3, right 2/3 (explicit grid so partition works) --}}
<div class="grid w-full grid-cols-1 gap-6 lg:min-h-[560px] lg:grid-cols-4" wire:key="member-equb-{{ $member->id }}">
    {{-- Left: Equbs joined (1/3) --}}
    <aside class="fi-section fi-section-contained w-full min-w-0 rounded-xl lg:col-span-1">
        <header class="fi-section-header">
            <h2 class="fi-section-header-heading">Equbs joined</h2>
        </header>
        <div class="fi-section-content-ctn">
            <div class="fi-section-content flex flex-col gap-2">
                @forelse($this->memberships as $membership)
                    <button
                        type="button"
                        wire:click="selectMembership({{ $membership->id }})"
                        class="fi-btn relative grid w-full gap-0.5 rounded-lg px-4 py-3 text-start text-sm font-medium outline-none transition duration-75 hover:bg-gray-500/10 focus:ring-2 focus:ring-primary-500/50 dark:hover:bg-white/5 {{ $selectedMembershipId === $membership->id ? 'fi-btn-color-primary bg-primary-500/10 text-primary-600 ring-1 ring-primary-500/20 dark:bg-primary-500/20 dark:text-primary-400 dark:ring-primary-500/30' : 'fi-btn-color-gray text-gray-700 dark:text-gray-200' }}"
                    >
                        <span class="font-semibold">{{ $membership->equbGroup?->package?->name ?? 'Equb #'.$membership->equb_group_id }}</span>
                        <span class="text-xs opacity-80">{{ $membership->join_date?->format('M j, Y') }} · {{ number_format($membership->contribution_amount) }} ETB</span>
                    </button>
                @empty
                    <div class="fi-section-content rounded-xl border border-dashed border-gray-300 px-4 py-8 text-center text-sm text-gray-500 dark:border-gray-600 dark:text-gray-400">
                        No Equbs yet.
                    </div>
                @endforelse
            </div>
        </div>
    </aside>

    {{-- Right: Equb details + payment tabs + management (2/3) --}}
    <div class="fi-section fi-section-contained min-w-0 rounded-xl lg:col-span-3">
        @if($this->selectedMembership)
            @php $m = $this->selectedMembership; $g = $m->equbGroup; $pkg = $g?->package; @endphp
            <header class="fi-section-header">
                <h2 class="fi-section-header-heading">{{ $pkg?->name ?? 'Equb' }}</h2>
            </header>
            <div class="fi-section-content-ctn">
                <div class="fi-section-content space-y-6">
                    {{-- Details strip --}}
                    <div class="fi-section-content flex flex-wrap items-center gap-3 rounded-lg bg-gray-500/5 px-4 py-3 dark:bg-white/5">
                        <span class="text-sm text-gray-700 dark:text-gray-300"><strong>{{ number_format($m->contribution_amount) }} ETB</strong> per payment</span>
                        <span class="text-gray-400 dark:text-gray-500">·</span>
                        <span class="text-sm text-gray-700 dark:text-gray-300">Every <strong>{{ $m->contribution_frequency_days }}</strong> day(s)</span>
                        <span class="text-gray-400 dark:text-gray-500">·</span>
                        <span class="text-sm text-gray-700 dark:text-gray-300">Joined {{ $m->join_date?->format('M j, Y') }}</span>
                        <x-filament::badge :color="$m->status->value === 'active' ? 'success' : 'gray'">{{ $m->status->value }}</x-filament::badge>
                    </div>

                    {{-- Tabs: List | Calendar (Filament fi-tabs / fi-active for dark/light) --}}
                    <div class="fi-section-content">
                        <nav class="fi-tabs flex max-w-full gap-x-1 overflow-x-auto rounded-xl bg-white p-2 shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10" role="tablist">
                            <button
                                type="button"
                                role="tab"
                                aria-selected="{{ $paymentViewMode === 'list' ? 'true' : 'false' }}"
                                wire:click="setPaymentViewMode('list')"
                                class="fi-tabs-item {{ $paymentViewMode === 'list' ? 'fi-active' : '' }} flex flex-1 items-center justify-center gap-x-2 rounded-lg px-3 py-2 text-sm font-medium outline-none transition duration-75 hover:bg-gray-50 focus-visible:bg-gray-50 dark:hover:bg-white/5 dark:focus-visible:bg-white/5 sm:flex-none"
                            >
                                <span class="fi-tabs-item-label {{ $paymentViewMode === 'list' ? 'text-primary-700 dark:text-primary-400' : 'text-gray-500 dark:text-gray-400' }}">List</span>
                            </button>
                            <button
                                type="button"
                                role="tab"
                                aria-selected="{{ $paymentViewMode === 'calendar' ? 'true' : 'false' }}"
                                wire:click="setPaymentViewMode('calendar')"
                                class="fi-tabs-item {{ $paymentViewMode === 'calendar' ? 'fi-active' : '' }} flex flex-1 items-center justify-center gap-x-2 rounded-lg px-3 py-2 text-sm font-medium outline-none transition duration-75 hover:bg-gray-50 focus-visible:bg-gray-50 dark:hover:bg-white/5 dark:focus-visible:bg-white/5 sm:flex-none"
                            >
                                <span class="fi-tabs-item-label {{ $paymentViewMode === 'calendar' ? 'text-primary-700 dark:text-primary-400' : 'text-gray-500 dark:text-gray-400' }}">Calendar</span>
                            </button>
                        </nav>
                    </div>

                    {{-- Tab content: List --}}
                    @if($paymentViewMode === 'list')
                        <div class="fi-section-content overflow-hidden rounded-xl border border-gray-200 dark:border-white/10">
                            <div class="overflow-x-auto">
                                <table class="fi-ta-table w-full text-sm">
                                    <thead class="divide-x divide-gray-200 bg-gray-500/5 dark:divide-white/10 dark:bg-white/5">
                                        <tr class="divide-x divide-gray-200 dark:divide-white/10">
                                            <th class="fi-ta-header-cell px-4 py-3 text-start text-xs font-semibold uppercase tracking-wider text-gray-500 dark:text-gray-400">Date</th>
                                            <th class="fi-ta-header-cell px-4 py-3 text-start text-xs font-semibold uppercase tracking-wider text-gray-500 dark:text-gray-400">Amount</th>
                                            <th class="fi-ta-header-cell px-4 py-3 text-start text-xs font-semibold uppercase tracking-wider text-gray-500 dark:text-gray-400">Method</th>
                                            <th class="fi-ta-header-cell px-4 py-3 text-start text-xs font-semibold uppercase tracking-wider text-gray-500 dark:text-gray-400">Status</th>
                                            <th class="fi-ta-header-cell px-4 py-3 text-end text-xs font-semibold uppercase tracking-wider text-gray-500 dark:text-gray-400">Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody class="divide-y divide-gray-200 dark:divide-white/5">
                                        @forelse($m->payments as $payment)
                                            <tr class="fi-ta-row divide-x divide-gray-200 dark:divide-white/10">
                                                <td class="fi-ta-cell px-4 py-3 text-gray-950 dark:text-white">{{ $payment->payment_date?->format('M j, Y') }}</td>
                                                <td class="fi-ta-cell px-4 py-3 text-gray-700 dark:text-gray-300">{{ number_format($payment->amount) }} ETB</td>
                                                <td class="fi-ta-cell px-4 py-3 text-gray-600 dark:text-gray-400">{{ $payment->payment_method?->value }}</td>
                                                <td class="fi-ta-cell px-4 py-3">
                                                    <x-filament::badge :color="$payment->status->value === 'paid' ? 'success' : ($payment->status->value === 'failed' ? 'danger' : 'warning')">
                                                        {{ $payment->status->value }}
                                                    </x-filament::badge>
                                                </td>
                                                <td class="fi-ta-cell px-4 py-3 text-end">
                                                    <button type="button" wire:click.stop="openPaymentModal({{ $payment->id }})" class="fi-link text-primary-600 hover:underline dark:text-primary-400">View</button>
                                                </td>
                                            </tr>
                                        @empty
                                            <tr>
                                                <td colspan="5" class="fi-ta-cell px-4 py-12 text-center text-gray-500 dark:text-gray-400">No payments yet.</td>
                                            </tr>
                                        @endforelse
                                    </tbody>
                                </table>
                            </div>
                        </div>
                        <div class="fi-section-content">
                            <button type="button" wire:click.stop="openPaymentModal()" class="fi-btn fi-btn-color-primary fi-btn-size-sm inline-flex items-center gap-1.5 text-sm font-medium">
                                {{-- <x-filament::icon icon="heroicon-o-plus-circle" class="size-4" /> --}}
                                Record payment manually
                            </button>
                        </div>
                    @else
                        {{-- Tab content: FullCalendar --}}
                        <div class="fi-section-content" wire:ignore>
                            <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/fullcalendar@6.1.15/index.global.min.css" />
                            <div
                                x-data="memberEqubCalendar({
                                    events: @js($this->calendarEventsForFullCalendar),
                                    onDateClick: (dateStr, paymentId) => {
                                        const id = this.$el.closest('[wire\\:id]')?.getAttribute('wire:id');
                                        const c = id && window.Livewire ? window.Livewire.find(id) : null;
                                        if (c && typeof c.openPaymentModal === 'function') {
                                            c.openPaymentModal(paymentId ?? null, dateStr);
                                        }
                                    },
                                    onPaymentSaved: () => {
                                        const id = this.$el.closest('[wire\\:id]')?.getAttribute('wire:id');
                                        const c = id && window.Livewire ? window.Livewire.find(id) : null;
                                        if (c && typeof c.getCalendarEventsForFullCalendar === 'function') {
                                            c.getCalendarEventsForFullCalendar().then(evs => {
                                                if (window._memberEqubCalendarInstance && Array.isArray(evs)) {
                                                    window._memberEqubCalendarInstance.removeAllEvents();
                                                    window._memberEqubCalendarInstance.addEvents(evs);
                                                }
                                            });
                                        }
                                    }
                                })"
                                x-init="init()"
                                @payment-saved.window="onPaymentSaved()"
                            >
                                <div id="member-equb-fullcalendar" class="h-[500px] min-h-[320px] max-h-[560px] rounded-xl border border-gray-200 dark:border-white/10 overflow-hidden"></div>
                                <p class="mt-3 text-xs text-gray-500 dark:text-gray-400">
                                    <span class="inline-block size-3 rounded-full bg-green-500 align-middle"></span> Paid
                                    <span class="ml-4 inline-block size-3 rounded-full bg-red-500 align-middle"></span> Unpaid
                                    <span class="ml-4 inline-block size-3 rounded-full bg-sky-500 align-middle"></span> Future due
                                </p>
                            </div>
                        </div>
                    @endif
                </div>
            </div>
        @else
            <div class="fi-section-content-ctn">
                <div class="fi-section-content flex flex-col items-center justify-center rounded-xl border-2 border-dashed border-gray-300 py-16 text-center dark:border-gray-600">
                    <x-filament::icon icon="heroicon-o-squares-2x2" class="fi-section-content mx-auto size-12 text-gray-400 dark:text-gray-500" />
                    <p class="mt-3 text-sm font-medium text-gray-700 dark:text-gray-300">Select an Equb</p>
                    <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">Choose an Equb from the list on the left to view payments and the calendar.</p>
                </div>
            </div>
        @endif
    </div>
</div>

{{-- FullCalendar 6 from CDN + Alpine component (load once per page) --}}
@once
    <script src="https://cdn.jsdelivr.net/npm/fullcalendar@6.1.15/index.global.min.js"></script>
    <script>
        document.addEventListener('alpine:init', () => {
            Alpine.data('memberEqubCalendar', (config) => ({
                events: config.events || [],
                onDateClick: config.onDateClick || (() => {}),
                onPaymentSaved: config.onPaymentSaved || (() => {}),
                init() {
                    const el = document.getElementById('member-equb-fullcalendar');
                    if (!el || typeof FullCalendar === 'undefined') return;
                    const calendar = new FullCalendar.Calendar(el, {
                        initialView: 'dayGridMonth',
                        headerToolbar: { left: 'prev,next today', center: 'title', right: '' },
                        events: this.events,
                        dateClick: (info) => this.onDateClick(info.dateStr, null),
                        eventClick: (info) => {
                            info.jsEvent.preventDefault();
                            const ext = info.event.extendedProps || {};
                            this.onDateClick(ext.date || info.event.startStr, ext.paymentId ?? null);
                        },
                    });
                    calendar.render();
                    window._memberEqubCalendarInstance = calendar;
                },
            }));
        });
    </script>
@endonce

{{-- Modal: payment detail or manual pay form --}}
@if($showPaymentModal)
    <div class="fixed inset-0 z-50 flex items-center justify-center bg-gray-950/50 backdrop-blur-sm dark:bg-gray-950/80" wire:click.self="closePaymentModal">
        <div class="fi-modal-window w-full max-w-md rounded-2xl border border-gray-200 bg-white p-6 shadow-xl dark:border-white/10 dark:bg-gray-900 mx-4">
            @if($modalIsCreate)
                <h3 class="fi-modal-heading text-lg font-semibold text-gray-950 dark:text-white mb-4">Record payment</h3>
                <form wire:submit.prevent="saveManualPayment" class="space-y-4">
                    <div>
                        <label class="fi-fo-field-wrp-label inline-flex items-center gap-x-3 text-sm font-medium text-gray-950 dark:text-white">Amount</label>
                        <input type="number" step="0.01" wire:model="amount" class="fi-input mt-1 w-full rounded-lg border-gray-300 dark:border-white/10 dark:bg-white/5" placeholder="Amount" required />
                        @error('amount')<p class="fi-fo-field-wrp-error-message mt-1 text-sm text-danger-600 dark:text-danger-400">{{ $message }}</p>@enderror
                    </div>
                    <div>
                        <label class="fi-fo-field-wrp-label inline-flex items-center gap-x-3 text-sm font-medium text-gray-950 dark:text-white">Date</label>
                        <input type="date" wire:model="payment_date" class="fi-input mt-1 w-full rounded-lg border-gray-300 dark:border-white/10 dark:bg-white/5" required />
                        @error('payment_date')<p class="fi-fo-field-wrp-error-message mt-1 text-sm text-danger-600 dark:text-danger-400">{{ $message }}</p>@enderror
                    </div>
                    <div>
                        <label class="fi-fo-field-wrp-label inline-flex items-center gap-x-3 text-sm font-medium text-gray-950 dark:text-white">Method</label>
                        <select wire:model="payment_method" class="fi-input mt-1 w-full rounded-lg border-gray-300 dark:border-white/10 dark:bg-white/5">
                            <option value="manual">Manual</option>
                            <option value="offline">Offline</option>
                        </select>
                    </div>
                    <div class="flex justify-end gap-2 pt-2">
                        <x-filament::button color="gray" type="button" wire:click.stop="closePaymentModal">Cancel</x-filament::button>
                        <x-filament::button type="submit">Save</x-filament::button>
                    </div>
                </form>
            @elseif($this->paymentForModal)
                @php $pay = $this->paymentForModal; @endphp
                <h3 class="fi-modal-heading text-lg font-semibold text-gray-950 dark:text-white mb-4">Payment details</h3>
                <div class="space-y-2 text-sm text-gray-700 dark:text-gray-300">
                    <p><strong>Date:</strong> {{ $pay->payment_date?->format('M j, Y') }}</p>
                    <p><strong>Amount:</strong> {{ number_format($pay->amount) }} ETB</p>
                    <p><strong>Method:</strong> {{ $pay->payment_method?->value }}</p>
                    <p><strong>Status:</strong> <x-filament::badge :color="$pay->status->value === 'paid' ? 'success' : 'danger'">{{ $pay->status->value }}</x-filament::badge></p>
                    @if($pay->reference)<p><strong>Reference:</strong> {{ $pay->reference }}</p>@endif
                </div>
                <div class="mt-4 flex justify-end">
                    <x-filament::button type="button" wire:click.stop="closePaymentModal">Close</x-filament::button>
                </div>
            @endif
        </div>
    </div>
@endif
