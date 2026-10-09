<?php

namespace App\Filament\Resources\AzanVoices;

use App\Filament\Resources\AzanVoices\Pages\CreateAzanVoice;
use App\Filament\Resources\AzanVoices\Pages\EditAzanVoice;
use App\Filament\Resources\AzanVoices\Pages\ListAzanVoices;
use App\Models\AzanVoice;
use BackedEnum;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ToggleColumn;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Auth;

/**
 * Admin -> Adhan voices: the voices members can download in the app
 * (Prayer settings -> Adhan voice) for their prayer-time notifications.
 */
class AzanVoiceResource extends Resource
{
    protected static ?string $model = AzanVoice::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-speaker-wave';

    public static function getNavigationLabel(): string
    {
        return __('Adhan voices');
    }

    public static function getModelLabel(): string
    {
        return __('adhan voice');
    }

    public static function getPluralModelLabel(): string
    {
        return __('Adhan voices');
    }

    public static function getNavigationGroup(): ?string
    {
        return __('filament.nav.promotion_management');
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->schema([
            Section::make(__('Voice'))
                ->schema([
                    TextInput::make('name')
                        ->label(__('Name shown in the app'))
                        ->required()
                        ->maxLength(120),
                    TextInput::make('muezzin')
                        ->label(__('Muezzin'))
                        ->maxLength(120),
                    Textarea::make('description')
                        ->label(__('Description'))
                        ->rows(2)
                        ->maxLength(500)
                        ->columnSpanFull(),
                    FileUpload::make('audio_path')
                        ->label(__('Adhan recording'))
                        ->helperText(__('The full adhan: MP3, M4A, OGG or WAV, up to 12 MB. Android plays it whole at prayer time; the app\'s preview plays it too.'))
                        ->disk('public')
                        ->visibility('public')
                        ->directory('azan-voices')
                        ->acceptedFileTypes([
                            'audio/mpeg',
                            'audio/mp3',
                            'audio/mp4',
                            'audio/x-m4a',
                            'audio/aac',
                            'audio/ogg',
                            'audio/wav',
                            'audio/x-wav',
                        ])
                        ->maxSize(12288)
                        ->required()
                        ->columnSpanFull(),
                    FileUpload::make('ios_sound_path')
                        ->label(__('iPhone clip (optional)'))
                        ->helperText(__('iPhones only play notification sounds of 30 seconds or less (.caf or .wav). Leave this empty and the server cuts one from the recording, as long as ffmpeg is installed on it.'))
                        ->disk('public')
                        ->visibility('public')
                        ->directory('azan-voices/ios')
                        ->maxSize(4096)
                        ->columnSpanFull(),
                    TextInput::make('duration_seconds')
                        ->label(__('Length (seconds)'))
                        ->helperText(__('Filled in automatically when ffmpeg is installed.'))
                        ->numeric()
                        ->minValue(1),
                    TextInput::make('sort_order')
                        ->label(__('Order in the list'))
                        ->numeric()
                        ->default(0),
                    Toggle::make('is_active')
                        ->label(__('Offered in the app'))
                        ->default(true),
                ])
                ->columns(2)
                ->columnSpanFull(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('sort_order')
            ->reorderable('sort_order')
            ->columns([
                TextColumn::make('name')
                    ->label(__('Name'))
                    ->description(fn (AzanVoice $record): ?string => $record->muezzin)
                    ->searchable(),
                TextColumn::make('duration_seconds')
                    ->label(__('Length'))
                    ->formatStateUsing(fn (?int $state): string => $state
                        ? intdiv($state, 60).':'.str_pad((string) ($state % 60), 2, '0', STR_PAD_LEFT)
                        : '—'),
                TextColumn::make('size_bytes')
                    ->label(__('Size'))
                    ->formatStateUsing(fn (?int $state): string => $state
                        ? number_format($state / 1048576, 1).' MB'
                        : '—'),
                IconColumn::make('ios_sound_path')
                    ->label(__('iPhone clip'))
                    ->boolean()
                    ->state(fn (AzanVoice $record): bool => filled($record->ios_sound_path)),
                ToggleColumn::make('is_active')
                    ->label(__('Offered')),
                TextColumn::make('updated_at')
                    ->label(__('Updated'))
                    ->since()
                    ->sortable(),
            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListAzanVoices::route('/'),
            'create' => CreateAzanVoice::route('/create'),
            'edit' => EditAzanVoice::route('/{record}/edit'),
        ];
    }

    protected static function allowed(): bool
    {
        return Auth::check()
            && (Auth::user()->hasRole('Super Admin') || Auth::user()->can('admin.pages.settings'));
    }

    public static function shouldRegisterNavigation(): bool
    {
        return static::allowed();
    }

    public static function canViewAny(): bool
    {
        return static::allowed();
    }
}
