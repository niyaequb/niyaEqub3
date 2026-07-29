<?php

namespace App\Filament\Pages;

use App\Models\GlobalSetting;
use App\Services\EnvService;
use App\Services\SmsService;
use Filament\Forms\Components\RichEditor;
use Filament\Forms\Components\RichEditor\RichContentRenderer;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Notifications\Notification;
use Filament\Forms\Components\Select;
use App\Models\Member;
use App\Services\FcmService;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section as ComponentsSection;
use Filament\Schemas\Schema;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Components\Utilities\Get;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

class Settings extends Page implements HasForms
{
    use InteractsWithForms;

    protected string $view = 'filament.pages.settings';

    public static function getNavigationIcon(): ?string
    {
        return 'heroicon-o-cog-6-tooth';
    }

    protected static ?int $navigationSort = 100;

    public static function getNavigationLabel(): string
    {
        return __('filament.settings.title');
    }

    public static function getNavigationGroup(): ?string
    {
        return __('filament.nav.settings_management');
    }

       public static function shouldRegisterNavigation(): bool
    {
        return  Auth::check() && (Auth::user()->hasRole('Super Admin') || Auth::user()->can('admin.pages.settings') ?? true);
    }
    public ?array $data = [];
    public string $activeTab = 'payment';
    public string $smsTab = 'afro';
    public bool $isSmsTestModalOpen = false;
    public ?array $testSmsFormData = [];
    public ?array $testSmsResponse = null;

    public bool $isFcmTestModalOpen = false;
    public ?array $testFcmFormData = [];
    public ?array $testFcmResponse = null;

    protected function getEnvService(): EnvService
    {
        return app(EnvService::class);
    }

    protected function getSmsService(): SmsService
    {
        return app(SmsService::class);
    }

    protected function getFcmService(): FcmService
    {
        return app(FcmService::class);
    }

    public function mount(): void
    {
        $chapaConfig = $this->getEnvService()->getChapaConfig();
        $afroConfig = $this->getEnvService()->getAfroConfig();
        $geezConfig = $this->getEnvService()->getGeezConfig();
        $equbConfig = $this->getEnvService()->getEqubConfig();
        $firebaseConfig = $this->getEnvService()->getFirebaseConfig();

        // Firebase data from file if exists
        $firebaseFileData = [];
        $firebaseFilePath = storage_path('app/firebase/service-account.json');
        if (file_exists($firebaseFilePath)) {
            $json = json_decode(file_get_contents($firebaseFilePath), true);
            if (is_array($json)) {
                $firebaseFileData = [
                    'firebase_client_email' => $json['client_email'] ?? '',
                    'firebase_private_key_id' => $json['private_key_id'] ?? '',
                    'firebase_service_account_path' => 'storage/app/firebase/service-account.json',
                ];
            }
        }

        $this->form->fill([
            // Payment
            'chapa_secret_key' => $chapaConfig['CHAPA_SECRET_KEY'] ?? '',
            'chapa_public_key' => $chapaConfig['CHAPA_PUBLIC_KEY'] ?? '',
            'chapa_webhook_secret' => $chapaConfig['CHAPA_WEBHOOK_SECRET'] ?? '',
            // AFRO SMS
            'afro_api_key' => $afroConfig['AFRO_API_KEY'] ?? '',
            'afro_identifier_id' => $afroConfig['AFRO_IDENTIFIER_ID'] ?? '',
            'afro_sender_name' => $afroConfig['AFRO_SENDER_NAME'] ?? '',
            'afro_base_url' => $afroConfig['AFRO_BASE_URL'] ?? '',
            'afro_otp_expires_in_seconds' => $afroConfig['AFRO_OTP_EXPIRES_IN_SECONDS'] ?? '12',
            'afro_opt_length' => $afroConfig['AFRO_OPT_LENGTH'] ?? '4',
            'short_code' => $afroConfig['SHORT_CODE'] ?? '4',
            'sms_mode' => $afroConfig['SMS_MODE'] ?? '2',
            // GEEZ SMS
            'geez_sms_token' => $geezConfig['GEEZ_SMS_TOKEN'] ?? '',
            'geez_sms_shortcode_id' => $geezConfig['GEEZ_SMS_SHORTCODE_ID'] ?? '',
            'geez_sms_base_url' => $geezConfig['GEEZ_SMS_BASE_URL'] ?? '',
            'otp_ttl_minutes' => $geezConfig['OTP_TTL_MINUTES'] ?? '5',
            // Equb
            'equb_draw_delay' => $equbConfig['EQUB_DRAW_DELAY'] ?? '30',
            'equb_auto_start_enabled' => filter_var($equbConfig['EQUB_AUTO_START_ENABLED'] ?? true, FILTER_VALIDATE_BOOLEAN),
            'equb_auto_draw_enabled' => filter_var($equbConfig['EQUB_AUTO_DRAW_ENABLED'] ?? false, FILTER_VALIDATE_BOOLEAN),
            'equb_restrict_draw_frequency' => filter_var($equbConfig['EQUB_RESTRICT_DRAW_FREQUENCY'] ?? true, FILTER_VALIDATE_BOOLEAN),
            'equb_enforce_draw_schedule' => filter_var($equbConfig['EQUB_ENFORCE_DRAW_SCHEDULE'] ?? true, FILTER_VALIDATE_BOOLEAN),
            'equb_members_per_draw' => $equbConfig['EQUB_MEMBERS_PER_DRAW'] ?? '50',

            // Firebase
            'firebase_project_id' => $firebaseConfig['FIREBASE_PROJECT_ID'] ?? '',
            'firebase_client_email' => $firebaseFileData['firebase_client_email'] ?? '',
            'firebase_private_key_id' => $firebaseFileData['firebase_private_key_id'] ?? '',
            'firebase_service_account_path' => $firebaseFileData['firebase_service_account_path'] ?? 'Not found',

            // Global Settings from DB (convert legal RichEditor fields to TipTap array)
            ...collect(GlobalSetting::all()->pluck('value', 'key'))
                ->map(function ($value, $key) {
                    if (in_array($key, ['privacy_policy', 'terms_conditions'])) {
                        if (empty($value)) {
                            return null;
                        }
                        // Try JSON (previously saved TipTap doc) first
                        $decoded = json_decode($value, true);
                        if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                            return $decoded;
                        }
                        // Convert HTML string to TipTap document array
                        try {
                            return RichContentRenderer::make()->getEditor()->setContent($value)->getDocument();
                        } catch (\Throwable $e) {
                            return null;
                        }
                    }
                    return $value;
                })
                ->toArray(),
        ]);
    }

    public function setActiveTab(string $tab): void
    {
        $this->activeTab = $tab;
        if ($tab === 'sms') {
            // Reset SMS tab to afro when switching to SMS tab
            $this->smsTab = 'afro';
        }
    }

    public function setSmsTab(string $tab): void
    {
        $this->smsTab = $tab;
    }

    public function openSmsTestModal(): void
    {
        $this->resetTestSmsFields();
        $this->isSmsTestModalOpen = true;
    }

    public function closeSmsTestModal(): void
    {
        $this->isSmsTestModalOpen = false;
        $this->resetTestSmsFields();
    }

    protected function resetTestSmsFields(): void
    {
        $this->testSmsFormData = [
            'phone' => '',
            'message' => '',
        ];
        $this->testSmsResponse = null;
    }

    public function openFcmTestModal(): void
    {
        $this->resetTestFcmFields();
        $this->isFcmTestModalOpen = true;
    }

    public function closeFcmTestModal(): void
    {
        $this->isFcmTestModalOpen = false;
        $this->resetTestFcmFields();
    }

    protected function resetTestFcmFields(): void
    {
        $this->testFcmFormData = [
            'member_id' => '',
            'title' => 'Test Notification',
            'body' => 'This is a test notification from Niya Ekub Admin.',
        ];
        $this->testFcmResponse = null;
    }

    public function testSmsForm(Schema $schema): Schema
    {
        if (empty($this->testSmsFormData)) {
            $this->testSmsFormData = [
                'phone' => '',
                'message' => '',
            ];
        }

        return $schema
            ->schema([
                TextInput::make('phone')
                    ->label(__('filament.settings.test_sms_phone'))
                    ->placeholder('+251901234567')
                    ->required()
                    ->maxLength(20),
                Textarea::make('message')
                    ->label(__('filament.settings.test_sms_message'))
                    ->placeholder(__('filament.settings.test_sms_placeholder'))
                    ->required()
                    ->rows(4)
                    ->maxLength(500),
            ])
            ->statePath('testSmsFormData');
    }

    public function testFcmForm(Schema $schema): Schema
    {
        if (empty($this->testFcmFormData)) {
            $this->testFcmFormData = [
                'member_id' => '',
                'title' => 'Test Notification',
                'body' => 'This is a test notification from Niya Ekub Admin.',
            ];
        }

        return $schema
            ->schema([
                Select::make('member_id')
                    ->label(__('filament.settings.test_fcm_member'))
                    ->options(fn () => Member::query()
                        ->whereHas('user', fn ($q) => $q->whereNotNull('fcm_token'))
                        ->with('user')
                        ->get()
                        ->mapWithKeys(fn ($m) => [$m->id => "{$m->full_name} ({$m->user->phone})"])
                    )
                    ->searchable()
                    ->required()
                    ->helperText(__('filament.settings.test_fcm_member_helper')),
                TextInput::make('title')
                    ->label(__('filament.settings.test_fcm_notification_title'))
                    ->required()
                    ->maxLength(255),
                Textarea::make('body')
                    ->label(__('filament.settings.test_fcm_notification_body'))
                    ->required()
                    ->rows(3)
                    ->maxLength(500),
            ])
            ->statePath('testFcmFormData');
    }

    public function form(Schema $schema): Schema
    {
        $formSchema = $this->getFormSchema();

        // If no schema for current tab, return empty schema
        if (empty($formSchema)) {
            return $schema->schema([])->statePath('data');
        }

        return $schema->schema($formSchema)->statePath('data');
    }

    protected function getFormSchema(): array
    {
        $schema = [];

        // Payment Tab - Chapa Configuration
        if ($this->activeTab === 'payment') {
            $schema[] = ComponentsSection::make(__('filament.settings.chapa_configuration'))
                ->description(__('filament.settings.chapa_configuration_description'))
                ->schema([
                    TextInput::make('chapa_secret_key')
                        ->label(__('filament.settings.chapa_secret_key'))
                        ->password()
                        ->revealable()
                        ->required()
                        ->dehydrated()
                        ->helperText(__('filament.settings.chapa_secret_key_helper'))
                        ->maxLength(255),
                    TextInput::make('chapa_public_key')
                        ->label(__('filament.settings.chapa_public_key'))
                        ->helperText(__('filament.settings.chapa_public_key_helper'))
                        ->maxLength(255),
                    TextInput::make('chapa_webhook_secret')
                        ->label(__('filament.settings.chapa_webhook_secret'))
                        ->password()
                        ->revealable()
                        ->dehydrated()
                        ->helperText(__('filament.settings.chapa_webhook_secret_helper'))
                        ->maxLength(255),
                ])
                ->columns(1);
        }

        // SMS Tab
        if ($this->activeTab === 'sms') {
            if ($this->smsTab === 'afro') {
                $schema[] = ComponentsSection::make(__('filament.settings.afro_sms_configuration'))
                    ->description(__('filament.settings.afro_sms_configuration_description'))
                    ->schema([
                        TextInput::make('afro_api_key')
                            ->label(__('filament.settings.afro_api_key'))
                            ->password()
                            ->revealable()
                            ->required()
                            ->dehydrated()
                            ->helperText(__('filament.settings.afro_api_key_helper'))
                            ->maxLength(255),
                        TextInput::make('afro_identifier_id')
                            ->label(__('filament.settings.afro_identifier_id'))
                            ->required()
                            ->dehydrated()
                            ->helperText(__('filament.settings.afro_identifier_id_helper'))
                            ->maxLength(255),
                        TextInput::make('afro_sender_name')
                            ->label(__('filament.settings.afro_sender_name'))
                            ->required()
                            ->dehydrated()
                            ->helperText(__('filament.settings.afro_sender_name_helper'))
                            ->maxLength(255),
                        TextInput::make('afro_base_url')
                            ->label(__('filament.settings.afro_base_url'))
                            ->url()
                            ->required()
                            ->dehydrated()
                            ->helperText(__('filament.settings.afro_base_url_helper'))
                            ->default('https://api.afromessage.com/api')
                            ->maxLength(255),
                        TextInput::make('afro_otp_expires_in_seconds')
                            ->label(__('filament.settings.afro_otp_expires_in_seconds'))
                            ->numeric()
                            ->required()
                            ->dehydrated()
                            ->helperText(__('filament.settings.afro_otp_expires_in_seconds_helper'))
                            ->default('12')
                            ->maxLength(10),
                        TextInput::make('afro_opt_length')
                            ->label(__('filament.settings.afro_opt_length'))
                            ->numeric()
                            ->required()
                            ->dehydrated()
                            ->helperText(__('filament.settings.afro_opt_length_helper'))
                            ->default('4')
                            ->maxLength(10),
                        TextInput::make('short_code')
                            ->label(__('filament.settings.short_code'))
                            ->numeric()
                            ->required()
                            ->dehydrated()
                            ->helperText(__('filament.settings.short_code_helper'))
                            ->default('4')
                            ->maxLength(10),
                        TextInput::make('sms_mode')
                            ->label(__('filament.settings.sms_mode'))
                            ->numeric()
                            ->required()
                            ->dehydrated()
                            ->helperText(__('filament.settings.sms_mode_helper'))
                            ->default('2')
                            ->maxLength(10),
                    ])
                    ->columns(2);
            } else {
                $schema[] = ComponentsSection::make(__('filament.settings.geez_sms_configuration'))
                    ->description(__('filament.settings.geez_sms_configuration_description'))
                    ->schema([
                        TextInput::make('geez_sms_token')
                            ->label(__('filament.settings.geez_sms_token'))
                            ->password()
                            ->revealable()
                            ->required()
                            ->dehydrated()
                            ->helperText(__('filament.settings.geez_sms_token_helper'))
                            ->maxLength(255),
                        TextInput::make('geez_sms_shortcode_id')
                            ->label(__('filament.settings.geez_sms_shortcode_id'))
                            ->numeric()
                            ->required()
                            ->dehydrated()
                            ->helperText(__('filament.settings.geez_sms_shortcode_id_helper'))
                            ->maxLength(255),
                        TextInput::make('geez_sms_base_url')
                            ->label(__('filament.settings.geez_sms_base_url'))
                            ->url()
                            ->dehydrated()
                            ->helperText(__('filament.settings.geez_sms_base_url_helper'))
                            ->maxLength(255),
                        TextInput::make('otp_ttl_minutes')
                            ->label(__('filament.settings.otp_ttl_minutes'))
                            ->numeric()
                            ->required()
                            ->dehydrated()
                            ->helperText(__('filament.settings.otp_ttl_minutes_helper'))
                            ->default('5')
                            ->maxLength(10),
                    ])
                    ->columns(2);
            }
        }

        // Equb Tab
        if ($this->activeTab === 'equb') {
            $schema[] = ComponentsSection::make(__('filament.settings.equb_configuration'))
                ->description(__('filament.settings.equb_configuration_description'))
                ->schema([
                    TextInput::make('equb_members_per_draw')
                        ->label(__('filament.settings.equb_members_per_draw'))
                        ->helperText(__('filament.settings.equb_members_per_draw_helper'))
                        ->numeric()
                        ->default('50')
                        ->required(),
                    TextInput::make('equb_draw_delay')
                        ->label(__('filament.settings.equb_draw_delay'))
                        ->helperText(__('filament.settings.equb_draw_delay_helper'))
                        ->numeric()
                        ->default('30')
                        ->required(),
                    \Filament\Forms\Components\Toggle::make('equb_auto_start_enabled')
                        ->label(__('filament.settings.equb_auto_start_enabled'))
                        ->helperText(__('filament.settings.equb_auto_start_enabled_helper'))
                        ->default(true),
                    \Filament\Forms\Components\Toggle::make('equb_auto_draw_enabled')
                        ->label(__('filament.settings.equb_auto_draw_enabled'))
                        ->helperText(__('filament.settings.equb_auto_draw_enabled_helper'))
                        ->default(false),
                    \Filament\Forms\Components\Toggle::make('equb_restrict_draw_frequency')
                        ->label('Limit Daily Draws (Based on Membership)')
                        ->helperText('If enabled, winners per day will be calculated as: Total Members / Members per Draw.')
                        ->default(true),
                    \Filament\Forms\Components\Toggle::make('equb_enforce_draw_schedule')
                        ->label('Enforce Draw Schedule (Only on Equb Date)')
                        ->default(false),
                ])
                ->columns(2);
        }

        // Legal Tab
        if ($this->activeTab === 'legal') {
            $legalRichEditor = function (string $fieldName): RichEditor {
                return RichEditor::make($fieldName)
                    ->json()
                    ->columnSpanFull()
                    ->afterStateHydrated(function (RichEditor $component, $state): void {
                        // Safety net: ensure the raw state is always an array (TipTap doc)
                        if (is_string($state)) {
                            try {
                                $decoded = json_decode($state, true);
                                $doc = (json_last_error() === JSON_ERROR_NONE && is_array($decoded))
                                    ? $decoded
                                    : RichContentRenderer::make()->getEditor()->setContent($state)->getDocument();
                            } catch (\Throwable $e) {
                                $doc = ['type' => 'doc', 'content' => []];
                            }
                            $component->rawState($doc);
                        }
                    });
            };

            $schema[] = ComponentsSection::make('Legal Information')
                ->description('Manage Privacy Policy and Terms & Conditions')
                ->schema([
                    $legalRichEditor('privacy_policy')->label('Privacy Policy'),
                    $legalRichEditor('terms_conditions')->label('Terms and Conditions'),
                ]);
        }

        // Support Tab
        if ($this->activeTab === 'support') {
            $schema[] = ComponentsSection::make('Support Contacts')
                ->description('Manage contact information for user support')
                ->schema([
                    TextInput::make('support_phone')
                        ->label('Phone Number')
                        ->tel(),
                    TextInput::make('support_email')
                        ->label('Email Address')
                        ->email(),
                    TextInput::make('support_website')
                        ->label('Website URL')
                        ->url(),
                    TextInput::make('support_whatsapp')
                        ->label('WhatsApp Number'),
                    TextInput::make('support_address')
                        ->label('Physical Address')
                        ->columnSpanFull(),
                ])
                ->columns(2);
        }

        // Social Media Tab
        if ($this->activeTab === 'social') {
            $schema[] = ComponentsSection::make('Social Media Links')
                ->description('Manage links to official social media pages')
                ->schema([
                    TextInput::make('social_telegram')
                        ->label('Telegram'),
                    TextInput::make('social_tiktok')
                        ->label('TikTok'),
                    TextInput::make('social_instagram')
                        ->label('Instagram'),
                    TextInput::make('social_youtube')
                        ->label('YouTube'),
                    TextInput::make('social_twitter')
                        ->label('Twitter (X)'),
                    TextInput::make('social_linkedin')
                        ->label('LinkedIn'),
                ])
                ->columns(2);
        }

        // Firebase Tab
        if ($this->activeTab === 'firebase') {
            $schema[] = ComponentsSection::make(__('filament.settings.firebase_configuration'))
                ->description(__('filament.settings.firebase_configuration_description'))
                ->schema([
                    TextInput::make('firebase_project_id')
                        ->label(__('filament.settings.firebase_project_id'))
                        ->helperText(__('filament.settings.firebase_project_id_helper'))
                        ->placeholder('e.g. project-id-123')
                        ->maxLength(255),
                    \Filament\Forms\Components\FileUpload::make('firebase_service_account')
                        ->label(__('filament.settings.firebase_service_account'))
                        ->helperText(__('filament.settings.firebase_service_account_helper'))
                        ->acceptedFileTypes(['application/json'])
                        ->disk('local')
                        ->directory('temp-firebase')
                        ->visibility('private')
                        ->live()
                        ->afterStateUpdated(function ($state, Set $set) {
                            if (!$state) return;

                            $jsonPath = is_array($state) ? array_values($state)[0] : $state;
                            $tempPath = \Illuminate\Support\Facades\Storage::disk('local')->path($jsonPath);

                            if (file_exists($tempPath)) {
                                $json = json_decode(file_get_contents($tempPath), true);

                                if (isset($json['project_id'])) {
                                    $set('firebase_project_id', $json['project_id']);
                                }
                                if (isset($json['client_email'])) {
                                    $set('firebase_client_email', $json['client_email']);
                                }
                                if (isset($json['private_key_id'])) {
                                    $set('firebase_private_key_id', $json['private_key_id']);
                                }
                            }
                        }),
                    \Filament\Forms\Components\Placeholder::make('firebase_service_account_path_placeholder')
                        ->label('Current File Path')
                        ->content(fn (Get $get) => $get('firebase_service_account_path') ?? 'Not found'),
                    TextInput::make('firebase_client_email')
                        ->label(__('filament.settings.firebase_client_email'))
                        ->readOnly()
                        ->extraAttributes(['class' => 'bg-gray-50 dark:bg-gray-800']),
                    TextInput::make('firebase_private_key_id')
                        ->label(__('filament.settings.firebase_private_key_id'))
                        ->readOnly()
                        ->extraAttributes(['class' => 'bg-gray-50 dark:bg-gray-800']),
                    \Filament\Forms\Components\Hidden::make('firebase_service_account_path'),
                ])
                ->columns(1);
        }

        return $schema;
    }

    protected function getFormActions(): array
    {
        return [\Filament\Actions\Action::make('save')->label(__('filament.actions.save'))->submit('save')];
    }

    public function save(): void
    {
        try {
            $data = $this->form->getState();

            if ($this->activeTab === 'payment') {
            Log::info('Saving Chapa configuration', ['data' => $data]);

            if (empty($data['chapa_secret_key'])) {
                Notification::make()
                    ->title(__('filament.settings.validation_error'))
                    ->body(__('filament.settings.chapa_secret_key_required'))
                    ->danger()
                    ->send();
                return;
            }

            $this->getEnvService()->setChapaConfig([
                'secret_key' => $data['chapa_secret_key'] ?? '',
                'public_key' => $data['chapa_public_key'] ?? '',
                'webhook_secret' => $data['chapa_webhook_secret'] ?? '',
            ]);
            } elseif ($this->activeTab === 'sms') {
                if ($this->smsTab === 'afro') {
                    Log::info('Saving AFRO SMS configuration', ['data' => $data]);

                    $this->getEnvService()->setAfroConfig([
                        'api_key' => $data['afro_api_key'] ?? '',
                        'identifier_id' => $data['afro_identifier_id'] ?? '',
                        'sender_name' => $data['afro_sender_name'] ?? '',
                        'base_url' => $data['afro_base_url'] ?? '',
                        'otp_expires_in_seconds' => $data['afro_otp_expires_in_seconds'] ?? '12',
                        'opt_length' => $data['afro_opt_length'] ?? '4',
                        'short_code' => $data['short_code'] ?? '4',
                        'sms_mode' => $data['sms_mode'] ?? '2',
                    ]);
                } else {
                    Log::info('Saving GEEZ SMS configuration', ['data' => $data]);

                    $this->getEnvService()->setGeezConfig([
                        'sms_token' => $data['geez_sms_token'] ?? '',
                        'sms_shortcode_id' => $data['geez_sms_shortcode_id'] ?? '',
                        'sms_base_url' => $data['geez_sms_base_url'] ?? '',
                        'otp_ttl_minutes' => $data['otp_ttl_minutes'] ?? '5',
                    ]);
                }
            } elseif ($this->activeTab === 'equb') {
                Log::info('Saving Equb configuration', ['data' => $data]);

                $this->getEnvService()->setEqubConfig([
                    'draw_delay' => $data['equb_draw_delay'] ?? '30',
                    'auto_start_enabled' => $data['equb_auto_start_enabled'] ? 'true' : 'false',
                    'auto_draw_enabled' => $data['equb_auto_draw_enabled'] ? 'true' : 'false',
                    'restrict_draw_frequency' => $data['equb_restrict_draw_frequency'] ? 'true' : 'false',
                    'enforce_draw_schedule' => $data['equb_enforce_draw_schedule'] ? 'true' : 'false',
                    'members_per_draw' => $data['equb_members_per_draw'] ?? '50',
                ]);
            } elseif (in_array($this->activeTab, ['legal', 'support', 'social'])) {
                Log::info("Saving {$this->activeTab} configuration", ['data' => $data]);

                foreach ($data as $key => $value) {
                    // RichEditor returns arrays (TipTap JSON). Encode to string for storage.
                    $storedValue = is_array($value) ? json_encode($value) : $value;
                    GlobalSetting::updateOrCreate(
                        ['key' => $key],
                        ['value' => $storedValue, 'group' => $this->activeTab]
                    );
                }
            } elseif ($this->activeTab === 'firebase') {
                Log::info('Saving Firebase configuration', ['data' => $data]);

                $firebaseConfig = [
                    'project_id' => $data['firebase_project_id'] ?? '',
                ];

                // Handle file upload
                if (!empty($data['firebase_service_account'])) {
                    $uploadState = $data['firebase_service_account'];
                    $jsonPath = is_array($uploadState) ? array_values($uploadState)[0] : $uploadState;

                    if ($jsonPath) {
                        $tempPath = \Illuminate\Support\Facades\Storage::disk('local')->path($jsonPath);
                        $targetDir = storage_path('app/firebase');
                        $targetPath = $targetDir . DIRECTORY_SEPARATOR . 'service-account.json';

                        if (file_exists($tempPath)) {
                            if (!is_dir($targetDir)) {
                                mkdir($targetDir, 0755, true);
                            }

                            // Overwrite existing file by copying
                            if (copy($tempPath, $targetPath)) {
                                // Keep path consistent for EnvService
                                $firebaseConfig['credentials'] = 'storage/app/firebase/service-account.json';
                                // Clean up the temporary uploaded file
                                unlink($tempPath);
                                Log::info("Firebase credentials updated at $targetPath");
                            } else {
                                throw new \Exception("Failed to save credentials to $targetPath. Check permissions.");
                            }
                        }
                    }
                }

                $this->getEnvService()->setFirebaseConfig($firebaseConfig);
            }

            Notification::make()
                ->title(__('filament.settings.saved_successfully'))
                ->success()
                ->send();

            // Refresh the page to show the notification
            $this->redirect(static::getUrl());
        } catch (\Exception $e) {
            Log::error('Error saving configuration', ['error' => $e->getMessage()]);

            Notification::make()
                ->title(__('filament.settings.save_error'))
                ->body($e->getMessage())
                ->danger()
                ->send();
        }
    }

    public function sendTestSms(): void
    {
        $data = $this->testSmsForm->getState();

        try {
            $result = $this->getSmsService()->sendSms(
                $data['phone'],
                $data['message']
            );

            $this->testSmsResponse = $result;

            if ($result['status'] === 'success') {
                Notification::make()
                    ->title(__('filament.settings.test_sms_success_title'))
                    ->body(__('filament.settings.test_sms_success_body'))
                    ->success()
                    ->send();
            } else {
                Notification::make()
                    ->title(__('filament.settings.test_sms_error_title'))
                    ->body($result['message'] ?? __('filament.settings.test_sms_error_body'))
                    ->danger()
                    ->send();
            }
        } catch (\Exception $e) {
            Log::error('SMS test failed', ['error' => $e->getMessage()]);

            Notification::make()
                ->title(__('filament.settings.test_sms_error_title'))
                ->body($e->getMessage())
                ->danger()
                ->send();
        }
    }

    public function sendTestFcm(): void
    {
        $data = $this->testFcmForm->getState();

        try {
            $member = Member::with('user')->find($data['member_id']);

            if (!$member || !$member->user || !$member->user->fcm_token) {
                Notification::make()
                    ->title('Error')
                    ->body('Selected member does not have a device registered.')
                    ->danger()
                    ->send();
                return;
            }

            $success = $this->getFcmService()->sendToToken(
                $member->user->fcm_token,
                [
                    'type' => 'test_notification',
                    'sent_at' => now()->toDateTimeString(),
                ],
                $data['title'],
                $data['body']
            );

            if ($success) {
                $this->testFcmResponse = ['status' => 'success', 'message' => 'Notification sent successfully to ' . $member->full_name];
                Notification::make()
                    ->title('Success')
                    ->body('Test notification sent!')
                    ->success()
                    ->send();
            } else {
                $this->testFcmResponse = ['status' => 'error', 'message' => 'Failed to send notification. Check logs.'];
                Notification::make()
                    ->title('Error')
                    ->body('Failed to send notification.')
                    ->danger()
                    ->send();
            }
        } catch (\Exception $e) {
            Log::error('FCM test failed', ['error' => $e->getMessage()]);
            $this->testFcmResponse = ['status' => 'error', 'message' => $e->getMessage()];

            Notification::make()
                ->title('Error')
                ->body($e->getMessage())
                ->danger()
                ->send();
        }
    }
}
