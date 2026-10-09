<?php

namespace App\Filament\Pages;

use App\Jobs\SendBulkSmsJob;
use App\Models\User;
use App\Services\SmsService;
use Filament\Forms\Components\Select;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Schema;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class SendBulkSms extends Page implements HasForms
{
    use InteractsWithForms;

    /**
     * How many recipients go into the queue at a time.
     *
     * "Send to everyone" never builds one array of every user: the users are
     * read in chunks of this size and each chunk is added to the batch as it
     * is read. A list of any length then costs the same memory, and the first
     * messages are already going out while the rest are still being queued.
     */
    protected const CHUNK = 500;

    public static function getNavigationIcon(): ?string
    {
        return 'heroicon-o-chat-bubble-left-right';
    }

    protected string $view = 'filament.pages.send-bulk-sms';

    protected static ?int $navigationSort = 50;

    public static function getNavigationLabel(): string
    {
        return __('filament.bulk_sms.title');
    }

    public static function getNavigationGroup(): ?string
    {
        return __('filament.nav.promotion_management');
    }

    public static function shouldRegisterNavigation(): bool
    {
        return Auth::check() && (Auth::user()->hasRole('Super Admin') || Auth::user()->can('admin.pages.send-bulk-sms') ?? true);
    }

    public string $activeTab = 'users';

    public ?array $data = [];

    public array $selectedUsers = [];

    /**
     * True when the message goes to every user with a phone number, rather
     * than to the ones picked in the list.
     */
    public bool $allUsers = false;

    /**
     * Which provider this send goes out on: 'AFRO', 'GEEZ', or '' for the one
     * named in Settings -> SMS Configuration (SMS_MODE).
     *
     * It changes this send only. The app's own messages — an OTP at sign-in,
     * a payment confirmation — keep using the provider in the settings.
     */
    public string $provider = '';

    public array $manualNumbers = [];

    public string $message = '';

    public ?array $sendResult = null;

    public ?string $batchId = null;

    /** Counted once per request, not once per call. */
    protected ?int $everyUserCount = null;

    protected function getSmsService(): SmsService
    {
        return app(SmsService::class);
    }

    public function mount(): void
    {
        $this->form->fill([
            'message' => '',
            'selectedUsers' => $this->selectedUsers,
        ]);
    }

    public function form(Schema $schema): Schema
    {
        $schemaArray = [];

        // Add multiselect based on active tab (only for non-manual tabs)
        if ($this->activeTab === 'users') {
            $schemaArray[] = Select::make('selectedUsers')
                ->label(__('filament.bulk_sms.select_users'))
                ->multiple()
                ->searchable()
                // Closures, not values: the schema is built once and these
                // have to be re-read after the "every user" switch is tapped,
                // or the list would stay greyed out after switching it off.
                //
                // Preloading pulls every user's name into the dropdown, which
                // is wasted work when the message is going to all of them.
                ->preload(fn (): bool => ! $this->allUsers)
                ->disabled(fn (): bool => $this->allUsers)
                ->helperText(fn (): ?string => $this->allUsers
                    ? __('filament.bulk_sms.all_users_overrides')
                    : null)
                ->options(function () {
                    if ($this->allUsers) {
                        return [];
                    }

                    return $this->getUsers()->mapWithKeys(function ($user) {
                        return [$user->id => strtoupper($user->name).' ('.$user->phone.')'];
                    })->toArray();
                })
                ->default($this->selectedUsers)
                ->live()
                ->afterStateUpdated(function ($state) {
                    $this->selectedUsers = $state ?? [];
                })
                ->dehydrated(false);
        }

        return $schema
            ->schema($schemaArray)
            ->statePath('data');
    }

    public function setActiveTab(string $tab): void
    {
        $this->activeTab = $tab;
        // Don't clear selections when switching tabs
        $this->form->fill([
            'message' => $this->message,
            'selectedUsers' => $this->selectedUsers,
        ]);
    }

    public function clearAll(): void
    {
        $this->selectedUsers = [];
        $this->allUsers = false;
        $this->provider = '';
        $this->manualNumbers = [];
        $this->message = '';
        $this->data['message'] = '';
        $this->sendResult = null;
    }

    /**
     * Turn "send to every user" on or off.
     *
     * Switching it on drops whatever was picked by hand: the two would
     * otherwise both be on screen, and it would not be obvious which one the
     * send was about to use.
     */
    public function toggleAllUsers(): void
    {
        $this->allUsers = ! $this->allUsers;

        if ($this->allUsers) {
            $this->selectedUsers = [];
        }

        $this->form->fill([
            'message' => $this->message,
            'selectedUsers' => $this->selectedUsers,
        ]);
    }

    public function addManualNumber(): void
    {
        $this->manualNumbers[] = [
            'id' => Str::random(10),
            'phone' => '',
            'name' => '',
        ];
    }

    public function removeManualNumber(string $id): void
    {
        $this->manualNumbers = array_filter($this->manualNumbers, fn ($item) => $item['id'] !== $id);
        $this->manualNumbers = array_values($this->manualNumbers);
    }

    /**
     * Every user who can be sent to, unordered.
     *
     * Left unordered on purpose: chunkById() walks the table by id, and an
     * ordering set here would take precedence over its cursor and make it
     * read the same page over and over.
     */
    public function usersQuery()
    {
        return User::whereNotNull('phone')->where('phone', '!=', '');
    }

    public function getUsers()
    {
        return $this->usersQuery()->orderBy('name')->get();
    }

    /** How many users the message would go to with "everyone" on. */
    public function getEveryUserCount(): int
    {
        return $this->everyUserCount ??= $this->usersQuery()->count();
    }

    /**
     * The provider this send will use, or null to use the one in Settings.
     *
     * Checked against the two real names rather than taken as given: the
     * value arrives from a <select> in the browser, so anything at all can
     * come back, and anything unknown should mean "use the setting" instead
     * of being reported as a provider that is not set up.
     */
    public function resolvedProvider(): ?string
    {
        return array_key_exists($this->provider, SmsService::providerOptions())
            ? $this->provider
            : null;
    }

    /** value => label for the provider picker, 'use the setting' first. */
    public function getProviderOptions(): array
    {
        return ['' => __('filament.bulk_sms.provider_default', [
            'provider' => $this->getSmsService()->getActiveProvider(),
        ])] + SmsService::providerOptions();
    }

    public function getTotalRecipients(): int
    {
        $count = $this->allUsers
            ? $this->getEveryUserCount()
            : count($this->selectedUsers);

        $count += count(array_filter($this->manualNumbers, fn ($item) => ! empty($item['phone'])));

        return $count;
    }

    /**
     * Builds the jobs for this send and hands them over a chunk at a time.
     *
     * The users are read with chunkById rather than all at once, so "send to
     * everyone" costs the same whether there are fifty users or fifty
     * thousand.
     */
    protected function eachJobChunk(?string $provider, callable $handle): void
    {
        $smsService = $this->getSmsService();
        $jobs = [];

        $push = function (string $phone, ?string $name, $model) use (&$jobs, $provider, $smsService, $handle) {
            $jobs[] = new SendBulkSmsJob([
                'phone' => $smsService->formatPhoneNumber($phone),
                'name' => $name ?: 'Unknown',
                'model' => $model,
            ], $this->message, $provider);

            if (count($jobs) >= self::CHUNK) {
                $handle($jobs);
                $jobs = [];
            }
        };

        $addUsers = function ($users) use ($push) {
            foreach ($users as $user) {
                if (! empty($user->phone)) {
                    $push($user->phone, $user->name, $user);
                }
            }
        };

        if ($this->allUsers) {
            $this->usersQuery()
                ->select(['id', 'name', 'phone'])
                ->chunkById(self::CHUNK, $addUsers);
        } elseif (! empty($this->selectedUsers)) {
            User::whereIn('id', $this->selectedUsers)
                ->select(['id', 'name', 'phone'])
                ->chunkById(self::CHUNK, $addUsers);
        }

        foreach ($this->manualNumbers as $item) {
            if (! empty($item['phone'])) {
                $push($item['phone'], $item['name'] ?? null, null);
            }
        }

        if ($jobs !== []) {
            $handle($jobs);
        }
    }

    public function sendSms(): void
    {
        // Validate message
        $this->validate([
            'message' => ['required', 'string', 'min:1', 'max:500'],
        ]);

        $total = $this->getTotalRecipients();

        if ($total === 0) {
            Notification::make()
                ->title(__('filament.bulk_sms.no_recipients'))
                ->body(__('filament.bulk_sms.no_recipients_body'))
                ->warning()
                ->send();

            return;
        }

        if (empty($this->message) || trim($this->message) === '') {
            Notification::make()
                ->title(__('filament.bulk_sms.no_message'))
                ->body(__('filament.bulk_sms.no_message_body'))
                ->warning()
                ->send();

            return;
        }

        try {
            $provider = $this->resolvedProvider();
            $smsService = $this->getSmsService()->usingProvider($provider);

            // Check if SMS service is configured. With a provider chosen here
            // this checks THAT provider's keys, so picking one whose settings
            // are half filled in says so now rather than failing per message.
            if (! $smsService->isConfigured()) {
                Notification::make()
                    ->title(__('filament.bulk_sms.config_error'))
                    ->body($provider === null
                        ? __('filament.bulk_sms.config_error_body')
                        : __('filament.bulk_sms.provider_not_configured', ['provider' => $provider]))
                    ->danger()
                    ->send();

                return;
            }

            // Dispatch the first chunk as the batch, then add the rest to it.
            $batch = null;
            $queued = 0;
            $name = 'Bulk SMS: '.$total.' recipients ('.$smsService->getActiveProvider().')';

            $this->eachJobChunk($provider, function (array $jobs) use (&$batch, &$queued, $name) {
                $queued += count($jobs);

                if ($batch === null) {
                    $batch = Bus::batch($jobs)
                        ->name($name)
                        ->allowFailures()
                        ->onQueue('default')
                        ->dispatch();

                    return;
                }

                $batch->add($jobs);
            });

            if ($batch === null) {
                Notification::make()
                    ->title(__('filament.bulk_sms.no_recipients'))
                    ->body(__('filament.bulk_sms.no_recipients_body'))
                    ->warning()
                    ->send();

                return;
            }

            $this->batchId = $batch->id;

            Log::info('Bulk SMS batch dispatched', [
                'batch_id' => $batch->id,
                'recipient_count' => $queued,
                'all_users' => $this->allUsers,
                'message_length' => strlen($this->message),
                'provider' => $smsService->getActiveProvider(),
                'provider_chosen_on_page' => $provider !== null,
            ]);

            Notification::make()
                ->title(__('filament.bulk_sms.batch_dispatched'))
                ->body(__('filament.bulk_sms.batch_dispatched_body', [
                    'count' => $queued,
                    'batch_id' => $batch->id,
                ]))
                ->success()
                ->send();

            // Clear selections after dispatching
            $this->clearAll();
        } catch (\Exception $e) {
            Log::error('Bulk SMS batch dispatch failed', ['error' => $e->getMessage()]);

            Notification::make()
                ->title(__('filament.bulk_sms.send_error'))
                ->body($e->getMessage())
                ->danger()
                ->send();
        }
    }

    public function getBatchStatus(): ?array
    {
        if (! $this->batchId) {
            return null;
        }

        try {
            $batch = Bus::findBatch($this->batchId);

            if (! $batch) {
                return null;
            }

            return [
                'id' => $batch->id,
                'total_jobs' => $batch->totalJobs,
                'pending_jobs' => $batch->pendingJobs,
                'processed_jobs' => $batch->processedJobs(),
                'failed_jobs' => $batch->failedJobs,
                'cancelled' => $batch->cancelled(),
                'finished' => $batch->finished(),
                'progress' => $batch->progress(),
            ];
        } catch (\Exception $e) {
            Log::error('Failed to get batch status', ['error' => $e->getMessage()]);

            return null;
        }
    }

    public function getUserInitials(User $user): string
    {
        $parts = explode(' ', $user->name);
        $initials = '';
        foreach ($parts as $part) {
            if (! empty($part)) {
                $initials .= strtoupper(substr($part, 0, 1));
            }
        }

        return substr($initials, 0, 2);
    }
}
