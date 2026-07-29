<x-filament-panels::page>
    @php
        $member = $this->getRecord();
        $member->loadMissing('user');
    @endphp
    <div class="space-y-6">
        {{-- Member basic info --}}
        <x-filament::section>
            <x-slot name="heading">
                Member information
            </x-slot>
            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
                <div>
                    <p class="text-xs font-medium uppercase tracking-wider text-gray-500 dark:text-gray-400">Full name</p>
                    <p class="mt-1 text-sm font-medium text-gray-900 dark:text-white">{{ $member->full_name }}</p>
                </div>
                @if($member->user)
                    <div>
                        <p class="text-xs font-medium uppercase tracking-wider text-gray-500 dark:text-gray-400">Phone</p>
                        <p class="mt-1 text-sm font-medium text-gray-900 dark:text-white">{{ $member->user->phone ?? '—' }}</p>
                    </div>
                    <div>
                        <p class="text-xs font-medium uppercase tracking-wider text-gray-500 dark:text-gray-400">Email</p>
                        <p class="mt-1 text-sm font-medium text-gray-900 dark:text-white">{{ $member->user->email ?? '—' }}</p>
                    </div>
                @endif
                <div>
                    <p class="text-xs font-medium uppercase tracking-wider text-gray-500 dark:text-gray-400">Joined</p>
                    <p class="mt-1 text-sm font-medium text-gray-900 dark:text-white">{{ $member->registered_at?->format('M j, Y') ?? '—' }}</p>
                </div>
                @if($member->address)
                    <div class="sm:col-span-2 lg:col-span-4">
                        <p class="text-xs font-medium uppercase tracking-wider text-gray-500 dark:text-gray-400">Address</p>
                        <p class="mt-1 text-sm text-gray-900 dark:text-white">{{ $member->address }}</p>
                    </div>
                @endif
            </div>
        </x-filament::section>

        {{-- Equb management (single Livewire component = this page) --}}
        <div class="grid w-full grid-cols-1 gap-6 lg:min-h-[560px] lg:grid-cols-3" wire:key="member-equb-{{ $member->id }}">
            {{-- Left: Equbs joined (1/3) --}}
            <aside class="fi-section fi-section-contained w-full min-w-0 rounded-xl lg:col-span-1">
                <header class="fi-section-header">
                    <h2 class="fi-section-header-heading">Equbs joined</h2>
                </header>
                <div class="fi-section-content-ctn">
                    <div class="fi-section-content flex flex-col gap-2">
                        @forelse($this->equbMemberships as $membership)
                            <button
                                type="button"
                                wire:click="equbSelectMembership({{ $membership->id }})"
                                class="fi-btn relative grid w-full gap-0.5 rounded-lg px-4 py-3 text-start text-sm font-medium outline-none transition duration-75 hover:bg-gray-500/10 focus:ring-2 focus:ring-primary-500/50 dark:hover:bg-white/5 {{ $equbSelectedMembershipId === $membership->id ? 'fi-btn-color-primary bg-primary-500/10 text-primary-600 ring-1 ring-primary-500/20 dark:bg-primary-500/20 dark:text-primary-400 dark:ring-primary-500/30' : 'fi-btn-color-gray text-gray-700 dark:text-gray-200' }}"
                            >
                                <span class="font-semibold">{{ $membership->equbGroup?->package?->name ?? 'Equb #'.$membership->equb_group_id }}  · </span>
                                <span class="opacity-80">{{ $membership->join_date?->format('M j, Y') }} · {{ number_format($membership->contribution_amount) }} ETB</span>
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
            <div class="fi-section fi-section-contained min-w-0 rounded-xl lg:col-span-2">
                @if($this->equbSelectedMembership)
                    @php $m = $this->equbSelectedMembership; $g = $m->equbGroup; $pkg = $g?->package; @endphp
                    <header class="fi-section-header">
                        <h2 class="fi-section-header-heading">{{ $pkg?->name ?? 'Equb' }}</h2>
                    </header>
                    <div class="fi-section-content-ctn">
                        <div class="fi-section-content space-y-6">
                            <div class="fi-section-content flex flex-wrap items-center gap-3 rounded-lg bg-gray-500/5 px-4 py-3 dark:bg-white/5">
                                <span class="text-sm text-gray-700 dark:text-gray-300"><strong>{{ number_format($m->contribution_amount) }} ETB</strong> per payment</span>
                                <span class="text-gray-400 dark:text-gray-500">·</span>
                                <span class="text-sm text-gray-700 dark:text-gray-300">Every <strong>{{ $m->contribution_frequency_days }}</strong> day(s)</span>
                                <span class="text-gray-400 dark:text-gray-500">·</span>
                                <span class="text-sm text-gray-700 dark:text-gray-300">Joined {{ $m->join_date?->format('M j, Y') }}</span>
                                <x-filament::badge :color="$m->status->value === 'active' ? 'success' : 'gray'">{{ $m->status->value }}</x-filament::badge>
                            </div>

                            {{-- Tabs: List | Calendar --}}
                            <div class="fi-section-content">
                                <nav class="fi-tabs flex max-w-full gap-x-1 overflow-x-auto rounded-xl bg-white p-2 shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10" role="tablist">
                                    <button
                                        type="button"
                                        role="tab"
                                        aria-selected="{{ $equbPaymentViewMode === 'list' ? 'true' : 'false' }}"
                                        wire:click="setEqubPaymentViewMode('list')"
                                        wire:loading.attr="disabled"
                                        class="fi-tabs-item {{ $equbPaymentViewMode === 'list' ? 'fi-active' : '' }} flex flex-1 items-center justify-center gap-x-2 rounded-lg px-3 py-2 text-sm font-medium outline-none transition duration-75 hover:bg-gray-50 focus-visible:bg-gray-50 dark:hover:bg-white/5 dark:focus-visible:bg-white/5 sm:flex-none"
                                    >
                                        <span class="fi-tabs-item-label {{ $equbPaymentViewMode === 'list' ? 'text-primary-700 dark:text-primary-400' : 'text-gray-500 dark:text-gray-400' }}">List</span>
                                    </button>
                                    <button
                                        type="button"
                                        role="tab"
                                        aria-selected="{{ $equbPaymentViewMode === 'calendar' ? 'true' : 'false' }}"
                                        wire:click="setEqubPaymentViewMode('calendar')"
                                        wire:loading.attr="disabled"
                                        class="fi-tabs-item {{ $equbPaymentViewMode === 'calendar' ? 'fi-active' : '' }} flex flex-1 items-center justify-center gap-x-2 rounded-lg px-3 py-2 text-sm font-medium outline-none transition duration-75 hover:bg-gray-50 focus-visible:bg-gray-50 dark:hover:bg-white/5 dark:focus-visible:bg-white/5 sm:flex-none"
                                    >
                                        <span class="fi-tabs-item-label {{ $equbPaymentViewMode === 'calendar' ? 'text-primary-700 dark:text-primary-400' : 'text-gray-500 dark:text-gray-400' }}">Calendar</span>
                                    </button>
                                </nav>
                            </div>

                            @if($equbPaymentViewMode === 'list')
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
                                                            <button type="button" wire:click="equbOpenPaymentModal({{ $payment->id }})" wire:loading.attr="disabled" class="fi-link inline-flex items-center gap-1.5 text-primary-600 hover:underline dark:text-primary-400">
                                                                <span wire:loading.remove wire:target="equbOpenPaymentModal">View</span>
                                                                <span wire:loading wire:target="equbOpenPaymentModal" class="inline-flex"><svg class="animate-spin size-4" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path></svg></span>
                                                            </button>
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
                                    <button type="button" wire:click="equbOpenPaymentModal()" wire:loading.attr="disabled" class="fi-btn fi-btn-color-primary fi-btn-size-sm inline-flex items-center gap-1.5 text-sm font-medium">
                                        <span wire:loading.remove wire:target="equbOpenPaymentModal"><x-filament::icon icon="heroicon-o-plus-circle" class="size-4" /> Record payment manually</span>
                                        <span wire:loading wire:target="equbOpenPaymentModal" class="inline-flex"><svg class="animate-spin size-4" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path></svg></span>
                                    </button>
                                </div>
                            @else
                                {{-- FullCalendar (wire:ignore only on inner div so List tab can replace this block) --}}
                                <div class="fi-section-content">
                                    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/fullcalendar@6.1.15/index.global.min.css" />
                                    <div wire:ignore>
                                        <div
                                            x-data="memberEqubCalendar({
                                                events: @js($this->equbCalendarEventsForFullCalendar),
                                                onDateClick: (dateStr, paymentId) => { $wire.equbOpenPaymentModal(paymentId ?? null, dateStr); },
                                                onPaymentSaved: () => {
                                                    $wire.getEqubCalendarEventsForFullCalendar().then(evs => {
                                                        if (window._memberEqubCalendarInstance && Array.isArray(evs)) {
                                                            window._memberEqubCalendarInstance.removeAllEvents();
                                                            window._memberEqubCalendarInstance.addEvents(evs);
                                                        }
                                                    });
                                                }
                                            })"
                                            x-init="init()"
                                            @payment-saved.window="onPaymentSaved()"
                                        >
                                            <div id="member-equb-fullcalendar" class="equb-calendar h-[500px] min-h-[320px] max-h-[560px] rounded-xl border border-gray-200 dark:border-white/10 overflow-hidden"></div>
                                        </div>
                                    </div>
                                    <p class="mt-3 text-xs text-gray-500 dark:text-gray-400">
                                        <span class="inline-block size-3 rounded-full bg-green-500 align-middle"></span> Paid
                                        <span class="ml-4 inline-block size-3 rounded-full bg-red-500 align-middle"></span> Unpaid
                                        <span class="ml-4 inline-block size-3 rounded-full bg-sky-500 align-middle"></span> Future due
                                    </p>
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
    </div>

    {{-- FullCalendar script + styles (once per page) --}}
    @once
        @push('styles')
            <style>
                /* Only Equb payment due days are clickable with pointer cursor */
                .equb-calendar .fc-daygrid-day-cell { cursor: default; }
                .equb-calendar .fc-daygrid-day-cell.fc-equb-clickable { cursor: pointer; }
                .equb-calendar .fc-event { cursor: pointer; }
            </style>
        @endpush
        @push('scripts')
            <script src="https://cdn.jsdelivr.net/npm/fullcalendar@6.1.15/index.global.min.js"></script>
            <script>
                document.addEventListener('alpine:init', () => {
                    Alpine.data('memberEqubCalendar', (config) => ({
                        events: config.events || [],
                        onDateClick: config.onDateClick || (() => {}),
                        onPaymentSaved: config.onPaymentSaved || (() => {}),
                        loadingDate: null,
                        hasEventOnDate(dateStr) {
                            return this.events.some(ev => String(ev.start).startsWith(dateStr));
                        },
                        showSpinnerOnDate(dateStr) {
                            document.querySelectorAll('.equb-calendar [data-equb-cell-loading]').forEach(cell => {
                                const match = cell.dataset.date === dateStr;
                                cell.classList.toggle('hidden', !match);
                                cell.classList.toggle('flex', match);
                            });
                        },
                        init() {
                            const el = document.getElementById('member-equb-fullcalendar');
                            if (!el || typeof FullCalendar === 'undefined') return;
                            const self = this;
                            if (typeof Livewire !== 'undefined') {
                                Livewire.hook('request.finished', () => { self.loadingDate = null; self.showSpinnerOnDate(null); });
                            }
                            this.$watch('loadingDate', (value) => this.showSpinnerOnDate(value));
                            const calendar = new FullCalendar.Calendar(el, {
                                initialView: 'dayGridMonth',
                                headerToolbar: { left: 'prev,next today', center: 'title', right: '' },
                                events: this.events,
                                dateClick: (info) => {
                                    if (self.hasEventOnDate(info.dateStr)) {
                                        self.loadingDate = info.dateStr;
                                        self.onDateClick(info.dateStr, null);
                                    }
                                },
                                eventClick: (info) => {
                                    info.jsEvent.preventDefault();
                                    const ext = info.event.extendedProps || {};
                                    const dateStr = ext.date || info.event.startStr;
                                    self.loadingDate = dateStr;
                                    self.onDateClick(dateStr, ext.paymentId ?? null);
                                },
                                dayCellDidMount: (arg) => {
                                    const dateStr = arg.date.toISOString().split('T')[0];
                                    if (self.hasEventOnDate(dateStr)) {
                                        arg.el.classList.add('fc-equb-clickable');
                                    } else {
                                        arg.el.classList.add('fc-equb-disabled');
                                    }
                                    arg.el.style.position = 'relative';
                                    const loadingDiv = document.createElement('div');
                                    loadingDiv.setAttribute('data-equb-cell-loading', '');
                                    loadingDiv.setAttribute('data-date', dateStr);
                                    loadingDiv.className = 'absolute inset-0 hidden items-center justify-center rounded bg-white/80 dark:bg-gray-900/80';
                                    loadingDiv.innerHTML = '<svg class="animate-spin size-6 text-primary-600 dark:text-primary-400" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path></svg>';
                                    arg.el.appendChild(loadingDiv);
                                },
                            });
                            calendar.render();
                            window._memberEqubCalendarInstance = calendar;
                        },
                    }));
                });
            </script>
        @endpush
    @endonce

    {{-- Modal --}}
    @if($equbShowPaymentModal)
        <div class="fixed inset-0 z-50 flex items-center justify-center bg-gray-950/50 backdrop-blur-sm dark:bg-gray-950/80" wire:click.self="equbClosePaymentModal">
            <div class="fi-modal-window w-full max-w-md rounded-2xl border border-gray-200 bg-white p-6 shadow-xl dark:border-white/10 dark:bg-gray-900 mx-4">
                @if($equbModalIsCreate)
                    <h3 class="fi-modal-heading text-lg font-semibold text-gray-950 dark:text-white mb-4">Record payment</h3>
                    <form wire:submit.prevent="equbSaveManualPayment" class="space-y-4">
                        <div>
                            <label class="fi-fo-field-wrp-label inline-flex items-center gap-x-3 text-sm font-medium text-gray-950 dark:text-white">Amount</label>
                            <input type="number" step="0.01" wire:model="equbAmount" class="fi-input mt-1 w-full rounded-lg bg-white py-2 ps-3 pe-3 text-gray-950 shadow-sm ring-1 ring-gray-950/10 transition duration-75 placeholder:text-gray-500 focus:ring-2 focus:ring-primary-600 dark:bg-white/5 dark:text-white dark:ring-white/20 dark:placeholder:text-gray-400 dark:focus:ring-primary-500" placeholder="Amount" required />
                            @error('equbAmount')<p class="fi-fo-field-wrp-error-message mt-1 text-sm text-danger-600 dark:text-danger-400">{{ $message }}</p>@enderror
                        </div>
                        <div>
                            <label class="fi-fo-field-wrp-label inline-flex items-center gap-x-3 text-sm font-medium text-gray-950 dark:text-white">Date</label>
                            <input type="date" wire:model="equbPaymentDate" class="fi-input mt-1 w-full rounded-lg bg-white py-2 ps-3 pe-3 text-gray-950 shadow-sm ring-1 ring-gray-950/10 transition duration-75 focus:ring-2 focus:ring-primary-600 dark:bg-white/5 dark:text-white dark:ring-white/20 dark:focus:ring-primary-500" required />
                            @error('equbPaymentDate')<p class="fi-fo-field-wrp-error-message mt-1 text-sm text-danger-600 dark:text-danger-400">{{ $message }}</p>@enderror
                        </div>
                        <div>
                            <label class="fi-fo-field-wrp-label inline-flex items-center gap-x-3 text-sm font-medium text-gray-950 dark:text-white">Method</label>
                            <select wire:model="equbPaymentMethod" class="fi-select-input mt-1 w-full rounded-lg bg-white py-2 ps-3 pe-8 text-base text-gray-950 shadow-sm ring-1 ring-gray-950/10 transition duration-75 focus:outline-none focus:ring-2 focus:ring-primary-600 dark:bg-white/5 dark:text-white dark:ring-white/20 dark:focus:ring-primary-500 [&>option]:bg-white [&>option]:text-gray-950 dark:[&>option]:bg-gray-900 dark:[&>option]:text-white">
                                <option value="manual">Manual</option>
                                <option value="offline">Offline</option>
                            </select>
                        </div>
                        <div class="flex justify-end gap-2 pt-2">
                            <x-filament::button color="gray" type="button" wire:click="equbClosePaymentModal">Cancel</x-filament::button>
                            <x-filament::button type="submit">Save</x-filament::button>
                        </div>
                    </form>
                @elseif($this->equbPaymentForModal)
                    @php $pay = $this->equbPaymentForModal; @endphp
                    <h3 class="fi-modal-heading text-lg font-semibold text-gray-950 dark:text-white mb-4">Payment details</h3>
                    <div class="space-y-2 text-sm text-gray-700 dark:text-gray-300">
                        <p><strong>Date:</strong> {{ $pay->payment_date?->format('M j, Y') }}</p>
                        <p><strong>Amount:</strong> {{ number_format($pay->amount) }} ETB</p>
                        <p><strong>Method:</strong> {{ $pay->payment_method?->value }}</p>
                        <p><strong>Status:</strong> <x-filament::badge :color="$pay->status->value === 'paid' ? 'success' : 'danger'">{{ $pay->status->value }}</x-filament::badge></p>
                        @if($pay->reference)<p><strong>Reference:</strong> {{ $pay->reference }}</p>@endif
                    </div>
                    <div class="mt-4 flex justify-end">
                        <x-filament::button type="button" wire:click="equbClosePaymentModal">Close</x-filament::button>
                    </div>
                @endif
            </div>
        </div>
    @endif
</x-filament-panels::page>
