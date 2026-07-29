<x-filament-panels::page>
    <div class="space-y-6">
        <!-- Header -->
        <div class="flex items-center justify-between">
            <div>
                <h2 class="text-2xl font-bold text-gray-900 dark:text-white">
                    {{ __('filament.bulk_sms.title') }}
                </h2>
                <p class="text-sm text-gray-600 dark:text-gray-400 mt-1">
                    {{ __('filament.bulk_sms.subtitle') }}
                </p>
            </div>
            <div class="flex items-center justify-center w-8 h-8 rounded-full bg-green-100 dark:bg-green-900/30">
                <svg class="w-3 h-3 text-green-600 dark:text-green-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 12h.01M12 12h.01M16 12h.01M21 12c0 4.418-4.03 8-9 8a9.863 9.863 0 01-4.255-.949L3 20l1.395-3.72C3.512 15.042 3 13.574 3 12c0-4.418 4.03-8 9-8s9 3.582 9 8z" />
                </svg>
            </div>
        </div>

        <!-- Send Bulk SMS Banner -->
        <div class="bg-gradient-to-r from-green-500 to-green-600 rounded-lg p-4 flex items-center justify-between shadow-lg">
            <div class="flex items-center space-x-3">
                <div class="flex items-center justify-center w-8 h-8 rounded-full bg-white/20">
                    <svg class="w-3 h-3 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 12h.01M12 12h.01M16 12h.01M21 12c0 4.418-4.03 8-9 8a9.863 9.863 0 01-4.255-.949L3 20l1.395-3.72C3.512 15.042 3 13.574 3 12c0-4.418 4.03-8 9-8s9 3.582 9 8z" />
                    </svg>
                </div>
                <div>
                    <h3 class="text-lg font-semibold text-white">{{ __('filament.bulk_sms.bulk_sms_title') }}</h3>
                    <p class="text-green-100 text-xs">{{ __('filament.bulk_sms.bulk_sms_subtitle') }}</p>
                </div>
            </div>
            <div class="flex items-center justify-center w-8 h-8 rounded-full bg-white/20">
            <svg class="w-3 h-3 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 19l9 2-9-18-9 18 9-2zm0 0v-8" />
            </svg>
            </div>
        </div>

        <!-- Tabs -->
        <div class="border-b border-gray-200 dark:border-gray-700">
            <nav class="-mb-px flex space-x-8" aria-label="Tabs">
                <button
                    wire:click="setActiveTab('users')"
                    class="@if($activeTab === 'users') border-green-500 text-green-600 dark:text-green-400 @else border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300 dark:text-gray-400 dark:hover:text-gray-300 @endif whitespace-nowrap py-4 px-1 border-b-2 font-medium text-sm flex items-center space-x-2"
                >
                    <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z" />
                    </svg>
                    <span>{{ __('filament.bulk_sms.tab_users') }}</span>
                </button>
                <button
                    wire:click="setActiveTab('manual')"
                    class="@if($activeTab === 'manual') border-green-500 text-green-600 dark:text-green-400 @else border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300 dark:text-gray-400 dark:hover:text-gray-300 @endif whitespace-nowrap py-4 px-1 border-b-2 font-medium text-sm flex items-center space-x-2"
                >
                    <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 5a2 2 0 012-2h3.28a1 1 0 01.948.684l1.498 4.493a1 1 0 01-.502 1.21l-2.257 1.13a11.042 11.042 0 005.516 5.516l1.13-2.257a1 1 0 011.21-.502l4.493 1.498a1 1 0 01.684.949V19a2 2 0 01-2 2h-1C9.716 21 3 14.284 3 6V5z" />
                    </svg>
                    <span>{{ __('filament.bulk_sms.tab_manual') }}</span>
                </button>
            </nav>
        </div>

        <div class="grid grid-cols-1  gap-4" wire:key="main-grid">
            <!-- Left Column: Recipient Selection -->
            <div>
                <div class="bg-white dark:bg-gray-800 rounded-lg shadow p-4">
                    {{-- <div class="flex items-center justify-between mb-3">
                        <h3 class="text-base font-semibold text-gray-900 dark:text-white flex items-center space-x-2">
                            @if($activeTab === 'members')

                                <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0zm6 3a2 2 0 11-4 0 2 2 0 014 0zM7 10a2 2 0 11-4 0 2 2 0 014 0z" />
                                </svg>
                                <span>{{ __('filament.bulk_sms.select_members') }}</span>
                            @elseif($activeTab === 'donors')
                                <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4.354a4 4 0 110 5.292M15 21H3v-1a6 6 0 0112 0v1zm0 0h6v-1a6 6 0 00-9-5.197M13 7a4 4 0 11-8 0 4 4 0 018 0z" />
                                </svg>
                                <span>{{ __('filament.bulk_sms.select_donors') }}</span>
                            @elseif($activeTab === 'users')
                                <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z" />
                                </svg>
                                <span>{{ __('filament.bulk_sms.select_users') }}</span>
                            @else
                                <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 5a2 2 0 012-2h3.28a1 1 0 01.948.684l1.498 4.493a1 1 0 01-.502 1.21l-2.257 1.13a11.042 11.042 0 005.516 5.516l1.13-2.257a1 1 0 011.21-.502l4.493 1.498a1 1 0 01.684.949V19a2 2 0 01-2 2h-1C9.716 21 3 14.284 3 6V5z" />
                                </svg>
                                <span>{{ __('filament.bulk_sms.manual_numbers') }}</span>
                            @endif
                        </h3>
                    </div> --}}

                    @if($activeTab !== 'manual')
                        <!-- Multiselect Dropdown -->
                        <div class="mb-3" wire:key="multiselect-{{ $activeTab }}">
                            {{ $this->form }}
                        </div>

                        <!-- Selected Count -->
                        @if($activeTab === 'users' && count($selectedUsers) > 0)
                            <div class="px-3 py-2 bg-green-50 dark:bg-green-900/20 border border-green-200 dark:border-green-800 rounded-lg mb-3">
                                <div class="flex items-center justify-between">
                                    <span class="text-xs font-medium text-green-800 dark:text-green-200">
                                        {{ count($selectedUsers) }} {{ __('filament.bulk_sms.users_selected') }}
                                    </span>
                                    <button
                                        type="button"
                                        wire:click="$set('selectedUsers', [])"
                                        class="text-xs text-red-600 dark:text-red-400 hover:text-red-700 dark:hover:text-red-300 font-medium"
                                    >
                                        {{ __('filament.bulk_sms.clear_selection') }}
                                    </button>
                                </div>
                            </div>
                        @endif
                    @endif

                    @if($activeTab === 'manual')
                        <!-- Manual Numbers -->
                        <div class="space-y-2">
                            @if(count($manualNumbers) > 0)
                                <div class="px-3 py-2 bg-green-50 dark:bg-green-900/20 border border-green-200 dark:border-green-800 rounded-lg mb-2">
                                    <span class="text-xs font-medium text-green-800 dark:text-green-200">
                                        {{ count(array_filter($manualNumbers, fn($item) => !empty($item['phone']))) }} {{ __('filament.bulk_sms.numbers_added') }}
                                    </span>
                                </div>
                            @endif
                            @foreach($manualNumbers as $index => $item)
                                <div class="flex items-center gap-2 p-3 bg-gray-50 dark:bg-gray-700/50 rounded-lg border border-gray-200 dark:border-gray-600">
                                    <div class="flex-1 space-y-2">
                                        <input
                                            type="text"
                                            wire:model.live="manualNumbers.{{ $index }}.phone"
                                            placeholder="{{ __('filament.bulk_sms.phone_placeholder') }}"
                                            class="w-full px-3 py-2 text-sm border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-900 text-gray-900 dark:text-white placeholder-gray-400 focus:outline-none focus:ring-2 focus:ring-green-500 focus:border-green-500"
                                        >
                                        <input
                                            type="text"
                                            wire:model.live="manualNumbers.{{ $index }}.name"
                                            placeholder="{{ __('filament.bulk_sms.name_placeholder') }}"
                                            class="w-full px-3 py-2 text-sm border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-900 text-gray-900 dark:text-white placeholder-gray-400 focus:outline-none focus:ring-2 focus:ring-green-500 focus:border-green-500"
                                        >
                                    </div>
                                    <button
                                        type="button"
                                        wire:click="removeManualNumber('{{ $item['id'] }}')"
                                        class="p-2 text-red-600 dark:text-red-400 hover:bg-red-50 dark:hover:bg-red-900/30 rounded-lg transition-colors"
                                    >
                                        <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                                        </svg>
                                    </button>
                                </div>
                            @endforeach
                            <button
                                type="button"
                                wire:click="addManualNumber"
                                class="w-full px-3 py-2 text-sm border flex items-center justify-center  border-dashed border-gray-300 dark:border-gray-600 rounded-lg text-gray-600 dark:text-gray-400 hover:border-green-500 hover:text-green-600 dark:hover:text-green-400 hover:bg-green-50 dark:hover:bg-green-900/20 transition-all"
                            >

                                <svg class="w-3 h-3 inline-block mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4" />
                                </svg>
                                {{ __('filament.bulk_sms.add_number') }}
                            </button>
                        </div>
                    @endif
                </div>
            </div>

            <!-- Right Column: Message Composition -->
            <div>
                <div class="bg-white dark:bg-gray-800 rounded-lg shadow p-4">
                    <h3 class="text-base font-semibold text-gray-900 dark:text-white flex items-center space-x-2 mb-3">
            <div class="flex items-center justify-center w-8 h-8 rounded-full bg-white/20">

                        <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z" />
                        </svg>
                    </div>
                        <span>{{ __('filament.bulk_sms.message') }}</span>
                    </h3>
                    <form wire:submit.prevent="sendSms">
                        <div class="space-y-2">
                            <textarea
                                wire:model.live="message"
                                class="w-full px-3 py-2 text-sm border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-900 text-gray-900 dark:text-white placeholder-gray-400 focus:outline-none focus:ring-2 focus:ring-green-500 focus:border-green-500"
                                placeholder="{{ __('filament.bulk_sms.message_placeholder') }}"
                                rows="6"
                            ></textarea>
                            <div class="flex justify-end">
                                <span class="text-xs text-gray-500 dark:text-gray-400">
                                    <span x-data="{ count: 0 }" x-effect="count = ($wire.message || '').length">
                                        <span x-text="count">0</span>/160
                                    </span>
                                </span>
                            </div>
                        </div>
                    </form>
                </div>

                @if($sendResult)
                    <div class="bg-white dark:bg-gray-800 rounded-lg shadow p-4 mt-4">
                        <h3 class="text-base font-semibold text-gray-900 dark:text-white mb-3">
                            {{ __('filament.bulk_sms.send_result') }}
                        </h3>
                        <div class="space-y-1.5">
                            <div class="flex justify-between">
                                <span class="text-xs text-gray-600 dark:text-gray-400">{{ __('filament.bulk_sms.total_sent') }}:</span>
                                <span class="text-xs font-medium text-gray-900 dark:text-white">{{ $sendResult['total'] }}</span>
                            </div>
                            <div class="flex justify-between">
                                <span class="text-xs text-green-600 dark:text-green-400">{{ __('filament.bulk_sms.success') }}:</span>
                                <span class="text-xs font-medium text-green-600 dark:text-green-400">{{ $sendResult['success'] }}</span>
                            </div>
                            @if($sendResult['error'] > 0)
                                <div class="flex justify-between">
                                    <span class="text-xs text-red-600 dark:text-red-400">{{ __('filament.bulk_sms.errors') }}:</span>
                                    <span class="text-xs font-medium text-red-600 dark:text-red-400">{{ $sendResult['error'] }}</span>
                                </div>
                            @endif
                        </div>
                    </div>
                @endif
            </div>
        </div>

        <!-- Action Buttons -->
        <div class="flex items-center justify-between pt-3 border-t border-gray-200 dark:border-gray-700">
            <x-filament::button
                type="button"
                color="gray"
                wire:click="clearAll"
            >
                <svg class="w-3 h-3 mr-1.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" />
                </svg>
                {{ __('filament.bulk_sms.clear_all') }}
            </x-filament::button>
            <x-filament::button
                type="button"
                color="success"
                wire:click="sendSms"
                wire:loading.attr="disabled"
                wire:target="sendSms"
            >
                <svg class="w-3 h-3 mr-1.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 19l9 2-9-18-9 18 9-2zm0 0v-8" />
                </svg>
                <span wire:loading.remove wire:target="sendSms">{{ __('filament.bulk_sms.send_sms') }} (<span>{{ $this->getTotalRecipients() }}</span> {{ __('filament.bulk_sms.recipients') }})</span>
                <span wire:loading wire:target="sendSms">{{ __('filament.actions.sending') }}...</span>
            </x-filament::button>
        </div>
    </div>
</x-filament-panels::page>
