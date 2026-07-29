<x-filament-panels::page>
    <div class="space-y-6">
        <!-- Main Tabs -->
        <div class="mb-6">
            <div class="border-b border-gray-200 dark:border-gray-700 overflow-x-auto overflow-y-hidden" style="overflow-y: hidden !important;">
                <nav class="-mb-px flex space-x-8 min-w-max px-2" aria-label="Tabs">
                    <button
                        wire:click="setActiveTab('payment')"
                        class="@if($activeTab === 'payment') border-blue-500 text-blue-600 dark:text-blue-400 @else border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300 dark:text-gray-400 dark:hover:text-gray-300 @endif whitespace-nowrap py-4 px-1 border-b-2 font-medium text-sm flex items-center space-x-2"
                    >
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.25 18.75a60.07 60.07 0 0115.797 2.101c.727.198 1.453-.342 1.453-1.096V18.75M3.75 4.5v.75A.75.75 0 013 6h-.75m0 0v-.375c0-.621.504-1.125 1.125-1.125H20.25M2.25 6v9m18-10.5v.75c0 .414.336.75.75.75h.75m-1.5-1.5h.375c.621 0 1.125.504 1.125 1.125v9.75c0 .621-.504 1.125-1.125 1.125h-.375m1.5-1.5H21a.75.75 0 00-.75.75v.75m0 0H3.75m0 0h-.375a1.125 1.125 0 01-1.125-1.125V15m1.5 1.5v-.75A.75.75 0 003 15h-.75M15 10.5a3 3 0 11-6 0 3 3 0 016 0zm3 0h.008v.008H18V10.5zm-12 0h.008v.008H6V10.5z" />
                        </svg>
                        <span>{{ __('filament.settings.payment_tab') }}</span>
                    </button>
                    <button
                        wire:click="setActiveTab('sms')"
                        class="@if($activeTab === 'sms') border-green-500 text-green-600 dark:text-green-400 @else border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300 dark:text-gray-400 dark:hover:text-gray-300 @endif whitespace-nowrap py-4 px-1 border-b-2 font-medium text-sm flex items-center space-x-2"
                    >
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 12h.01M12 12h.01M16 12h.01M21 12c0 4.418-4.03 8-9 8a9.863 9.863 0 01-4.255-.949L3 20l1.395-3.72C3.512 15.042 3 13.574 3 12c0-4.418 4.03-8 9-8s9 3.582 9 8z" />
                        </svg>
                        <span>{{ __('filament.settings.sms_tab') }}</span>
                    </button>
                    <button
                        wire:click="setActiveTab('equb')"
                        class="@if($activeTab === 'equb') border-yellow-500 text-yellow-600 dark:text-yellow-400 @else border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300 dark:text-gray-400 dark:hover:text-gray-300 @endif whitespace-nowrap py-4 px-1 border-b-2 font-medium text-sm flex items-center space-x-2"
                    >
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z" />
                        </svg>
                        <span>{{ __('filament.settings.equb_tab') }}</span>
                    </button>
                    <button
                        wire:click="setActiveTab('firebase')"
                        class="@if($activeTab === 'firebase') border-orange-500 text-orange-600 dark:text-orange-400 @else border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300 dark:text-gray-400 dark:hover:text-gray-300 @endif whitespace-nowrap py-4 px-1 border-b-2 font-medium text-sm flex items-center space-x-2"
                    >
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 15a4 4 0 004 4h9a5 5 0 10-.1-9.999 5.002 5.002 0 10-9.78 2.096A4.001 4.001 0 003 15z" />
                        </svg>
                        <span>{{ __('filament.settings.firebase_tab') }}</span>
                    </button>
                    <button
                        wire:click="setActiveTab('legal')"
                        class="@if($activeTab === 'legal') border-red-500 text-red-600 dark:text-red-400 @else border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300 dark:text-gray-400 dark:hover:text-gray-300 @endif whitespace-nowrap py-4 px-1 border-b-2 font-medium text-sm flex items-center space-x-2"
                    >
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
                        </svg>
                        <span>Legal</span>
                    </button>
                    <button
                        wire:click="setActiveTab('support')"
                        class="@if($activeTab === 'support') border-indigo-500 text-indigo-600 dark:text-indigo-400 @else border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300 dark:text-gray-400 dark:hover:text-gray-300 @endif whitespace-nowrap py-4 px-1 border-b-2 font-medium text-sm flex items-center space-x-2"
                    >
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M18.364 5.636l-3.536 3.536m0 5.656l3.536 3.536M9.172 9.172L5.636 5.636m3.536 9.192l-3.536 3.536M21 12a9 9 0 11-18 0 9 9 0 0118 0zm-5 0a4 4 0 11-8 0 4 4 0 018 0z" />
                        </svg>
                        <span>Support</span>
                    </button>
                    <button
                        wire:click="setActiveTab('social')"
                        class="@if($activeTab === 'social') border-cyan-500 text-cyan-600 dark:text-cyan-400 @else border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300 dark:text-gray-400 dark:hover:text-gray-300 @endif whitespace-nowrap py-4 px-1 border-b-2 font-medium text-sm flex items-center space-x-2"
                    >
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13.828 10.172a4 4 0 00-5.656 0l-4 4a4 4 0 105.656 5.656l1.102-1.101m-.758-4.899a4 4 0 005.656 0l4-4a4 4 0 00-5.656-5.656l-1.1 1.1" />
                        </svg>
                        <span>Social</span>
                    </button>
                </nav>
            </div>
        </div>

        @if($activeTab === 'payment')
            <!-- Payment Tab - Chapa Configuration -->
            <form wire:submit="save">
                {{ $this->form }}
                <div class="mt-8 flex justify-end">
                    <x-filament::button
                        type="submit"
                        size="lg"
                        wire:loading.attr="disabled"
                        wire:target="save"
                    >
                        <span wire:loading.remove wire:target="save">
                            {{ __('filament.actions.save') }}
                        </span>
                        <span wire:loading wire:target="save">
                            {{ __('filament.actions.saving') }}
                        </span>
                    </x-filament::button>
                </div>
            </form>
        @endif

        @if($activeTab === 'sms')
            <!-- SMS Sub-tabs -->
            <div class="mb-6">
                <div class="border-b border-gray-200 dark:border-gray-700 overflow-x-auto overflow-y-hidden" style="overflow-y: hidden !important;">
                    <nav class="-mb-px flex space-x-8 min-w-max px-2" aria-label="SMS Tabs">
                        <button
                            type="button"
                            wire:click="setSmsTab('afro')"
                            class="@if($smsTab === 'afro') border-purple-500 text-purple-600 dark:text-purple-400 @else border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300 dark:text-gray-400 dark:hover:text-gray-300 @endif whitespace-nowrap py-3 px-1 border-b-2 font-medium text-sm"
                        >
                            {{ __('filament.settings.afro_sms') }}
                        </button>
                        <button
                            type="button"
                            wire:click="setSmsTab('geez')"
                            class="@if($smsTab === 'geez') border-orange-500 text-orange-600 dark:text-orange-400 @else border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300 dark:text-gray-400 dark:hover:text-gray-300 @endif whitespace-nowrap py-3 px-1 border-b-2 font-medium text-sm"
                        >
                            {{ __('filament.settings.geez_sms') }}
                        </button>
                    </nav>
                </div>
            </div>

            <div class="flex justify-end mb-4">
                <x-filament::button type="button" color="gray" wire:click="openSmsTestModal">
                    {{ __('filament.settings.test_sms_button') }}
                </x-filament::button>
            </div>

            <!-- SMS Configuration Form -->
            <form wire:submit="save">
                {{ $this->form }}
                <div class="mt-8 flex justify-end">
                    <x-filament::button
                        type="submit"
                        size="lg"
                        wire:loading.attr="disabled"
                        wire:target="save"
                    >
                        <span wire:loading.remove wire:target="save">
                            {{ __('filament.actions.save') }}
                        </span>
                        <span wire:loading wire:target="save">
                            {{ __('filament.actions.saving') }}
                        </span>
                    </x-filament::button>
                </div>
            </form>
        @endif

        @if($activeTab === 'equb')
            <!-- Equb Tab - Automation Configuration -->
            <form wire:submit="save">
                {{ $this->form }}
                <div class="mt-8 flex justify-end">
                    <x-filament::button
                        type="submit"
                        size="lg"
                        wire:loading.attr="disabled"
                        wire:target="save"
                    >
                        <span wire:loading.remove wire:target="save">
                            {{ __('filament.actions.save') }}
                        </span>
                        <span wire:loading wire:target="save">
                            {{ __('filament.actions.saving') }}
                        </span>
                    </x-filament::button>
                </div>
            </form>
        @endif

        @if($activeTab === 'firebase')
            <!-- Firebase Tab - FCM Configuration -->
            <div class="flex justify-end mb-4">
                <x-filament::button type="button" color="gray" wire:click="openFcmTestModal">
                    Send Test Notification
                </x-filament::button>
            </div>
            <form wire:submit="save">
                {{ $this->form }}
                <div class="mt-8 flex justify-end">
                    <x-filament::button
                        type="submit"
                        size="lg"
                        wire:loading.attr="disabled"
                        wire:target="save"
                    >
                        <span wire:loading.remove wire:target="save">
                            {{ __('filament.actions.save') }}
                        </span>
                        <span wire:loading wire:target="save">
                            {{ __('filament.actions.saving') }}
                        </span>
                    </x-filament::button>
                </div>
            </form>
        @endif

        @if(in_array($activeTab, ['legal', 'support', 'social']))
            <!-- General DB Settings -->
            <form wire:submit="save">
                {{ $this->form }}
                <div class="mt-8 flex justify-end">
                    <x-filament::button
                        type="submit"
                        size="lg"
                        wire:loading.attr="disabled"
                        wire:target="save"
                    >
                        <span wire:loading.remove wire:target="save">
                            {{ __('filament.actions.save') }}
                        </span>
                        <span wire:loading wire:target="save">
                            {{ __('filament.actions.saving') }}
                        </span>
                    </x-filament::button>
                </div>
            </form>
        @endif
    </div>

    @if($isSmsTestModalOpen)
        <div class="fixed inset-0 z-50 flex items-center justify-center bg-black/60 backdrop-blur">
            <div class="bg-white dark:bg-gray-900 rounded-xl shadow-2xl w-full max-w-lg p-6">
                <div class="flex items-center justify-between mb-4">
                    <h3 class="text-lg font-semibold text-gray-900 dark:text-gray-100">
                        {{ __('filament.settings.test_sms_title') }}
                    </h3>
                    <button type="button" wire:click="closeSmsTestModal" class="text-gray-500 hover:text-gray-800 dark:hover:text-gray-200">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                        </svg>
                    </button>
                </div>

                <form wire:submit.prevent="sendTestSms" class="space-y-4">
                    {{ $this->testSmsForm }}

                    @if($testSmsResponse)
                        <div class="rounded-lg p-4 text-sm {{ $testSmsResponse['status'] === 'success' ? 'bg-green-50 text-green-800 dark:bg-green-900/30 dark:text-green-200' : 'bg-red-50 text-red-800 dark:bg-red-900/30 dark:text-red-200' }}">
                            <p class="font-medium">
                                {{ $testSmsResponse['status'] === 'success'
                                    ? __('filament.settings.test_sms_result_success')
                                    : __('filament.settings.test_sms_result_error') }}
                            </p>
                            <p class="mt-1">
                                {{ $testSmsResponse['message'] ?? '' }}
                            </p>
                        </div>
                    @endif

                    <div class="flex justify-end space-x-3">
                        <x-filament::button type="button" color="gray" wire:click="closeSmsTestModal">
                            {{ __('filament.actions.cancel') }}
                        </x-filament::button>
                        <x-filament::button type="submit" color="primary" wire:loading.attr="disabled">
                            <span wire:loading.remove>{{ __('filament.settings.test_sms_submit') }}</span>
                            <span wire:loading>{{ __('filament.actions.sending') }}</span>
                        </x-filament::button>
                    </div>
                </form>
            </div>
        </div>
    @endif

    @if($isFcmTestModalOpen)
        <div class="fixed inset-0 z-50 flex items-center justify-center bg-black/60 backdrop-blur">
            <div class="bg-white dark:bg-gray-900 rounded-xl shadow-2xl w-full max-w-lg p-6">
                <div class="flex items-center justify-between mb-4">
                    <h3 class="text-lg font-semibold text-gray-900 dark:text-gray-100">
                        {{ __('filament.settings.test_fcm_title') }}
                    </h3>
                    <button type="button" wire:click="closeFcmTestModal" class="text-gray-500 hover:text-gray-800 dark:hover:text-gray-200">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                        </svg>
                    </button>
                </div>

                <form wire:submit.prevent="sendTestFcm" class="space-y-4">
                    {{ $this->testFcmForm }}

                    @if($testFcmResponse)
                        <div class="rounded-lg p-4 text-sm {{ $testFcmResponse['status'] === 'success' ? 'bg-green-50 text-green-800 dark:bg-green-900/30 dark:text-green-200' : 'bg-red-50 text-red-800 dark:bg-red-900/30 dark:text-red-200' }}">
                            <p class="font-medium">
                                {{ $testFcmResponse['status'] === 'success'
                                    ? __('filament.settings.test_fcm_success')
                                    : __('filament.settings.test_fcm_error') }}
                            </p>
                            <p class="mt-1">
                                {{ $testFcmResponse['message'] ?? '' }}
                            </p>
                        </div>
                    @endif

                    <div class="flex justify-end space-x-3">
                        <x-filament::button type="button" color="gray" wire:click="closeFcmTestModal">
                            {{ __('filament.actions.cancel') }}
                        </x-filament::button>
                        <x-filament::button type="submit" color="primary" wire:loading.attr="disabled" wire:target="sendTestFcm">
                            <span wire:loading.remove wire:target="sendTestFcm">{{ __('filament.settings.test_fcm_submit') }}</span>
                            <span wire:loading wire:target="sendTestFcm">{{ __('filament.actions.sending') }}</span>
                        </x-filament::button>
                    </div>
                </form>
            </div>
        </div>
    @endif
</x-filament-panels::page>
