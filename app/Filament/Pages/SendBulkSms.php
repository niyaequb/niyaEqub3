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

    public array $manualNumbers = [];

    public string $message = '';

    public ?array $sendResult = null;

    public ?string $batchId = null;

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
                ->preload()
                ->options(function () {
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
        $this->manualNumbers = [];
        $this->message = '';
        $this->data['message'] = '';
        $this->sendResult = null;
    }

    public function toggleSelectAllUsers(): void
    {
        if (count($this->selectedUsers) === $this->getUsers()->count()) {
            $this->selectedUsers = [];
        } else {
            $this->selectedUsers = $this->getUsers()->pluck('id')->toArray();
        }
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

    public function getUsers()
    {
        return User::whereNotNull('phone')
            ->where('phone', '!=', '')
            ->orderBy('name')
            ->get();
    }

    public function getTotalRecipients(): int
    {
        $count = 0;

        // Count all selected recipients from all tabs
        $count += count($this->selectedUsers);
        $count += count(array_filter($this->manualNumbers, fn ($item) => ! empty($item['phone'])));

        return $count;
    }

    public function getSelectedRecipients(): array
    {
        $recipients = [];

        // Get users from selectedUsers (regardless of active tab)
        if (! empty($this->selectedUsers)) {
            $users = User::whereIn('id', $this->selectedUsers)->get();
            foreach ($users as $user) {
                if ($user->phone) {
                    $recipients[] = [
                        'phone' => $this->getSmsService()->formatPhoneNumber($user->phone),
                        'name' => $user->name,
                        'model' => $user,
                    ];
                }
            }
        }

        // Get manual numbers (regardless of active tab)
        if (! empty($this->manualNumbers)) {
            foreach ($this->manualNumbers as $item) {
                if (! empty($item['phone'])) {
                    $recipients[] = [
                        'phone' => $this->getSmsService()->formatPhoneNumber($item['phone']),
                        'name' => $item['name'] ?? 'Manual',
                        'model' => null,
                    ];
                }
            }
        }

        return $recipients;
    }

    public function sendSms(): void
    {
        // Validate message
        $this->validate([
            'message' => ['required', 'string', 'min:1', 'max:500'],
        ]);

        // Get all recipients from all tabs
        $recipients = $this->getSelectedRecipients();

        if (empty($recipients)) {
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
            $smsService = $this->getSmsService();

            // Check if SMS service is configured
            if (! $smsService->isConfigured()) {
                Notification::make()
                    ->title(__('filament.bulk_sms.config_error'))
                    ->body(__('filament.bulk_sms.config_error_body'))
                    ->danger()
                    ->send();

                return;
            }

            // Create jobs for each recipient
            $jobs = collect($recipients)->map(function ($recipient) {
                return new SendBulkSmsJob($recipient, $this->message);
            });

            // Dispatch batch of jobs
            $batch = Bus::batch($jobs)
                ->name('Bulk SMS: '.count($recipients).' recipients')
                ->allowFailures()
                ->onQueue('default')
                ->dispatch();

            $this->batchId = $batch->id;

            Log::info('Bulk SMS batch dispatched', [
                'batch_id' => $batch->id,
                'recipient_count' => count($recipients),
                'message_length' => strlen($this->message),
                'provider' => $smsService->getActiveProvider(),
            ]);

            Notification::make()
                ->title(__('filament.bulk_sms.batch_dispatched'))
                ->body(__('filament.bulk_sms.batch_dispatched_body', [
                    'count' => count($recipients),
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
