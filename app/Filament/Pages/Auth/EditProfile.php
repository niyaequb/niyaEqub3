<?php

namespace App\Filament\Pages\Auth;

use Filament\Auth\Pages\EditProfile as BaseEditProfile;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Model;

/**
 * Full profile page for the signed-in admin/staff user.
 * Opened from the avatar menu ("Profile").
 */
class EditProfile extends BaseEditProfile
{
    public static function isSimple(): bool
    {
        // Show inside the normal panel layout (sidebar + topbar).
        return false;
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->inlineLabel(false)
            ->components([
                Section::make('Profile picture')
                    ->schema([
                        FileUpload::make('profile_picture')
                            ->hiddenLabel()
                            ->image()
                            ->avatar()
                            ->disk('public')
                            ->directory('profile-pictures')
                            ->visibility('public')
                            ->maxSize(2048)
                            ->acceptedFileTypes(['image/jpeg', 'image/png', 'image/gif', 'image/webp'])
                            ->imageEditor()
                            ->circleCropper(),
                    ]),

                Section::make('Personal information')
                    ->columns(2)
                    ->schema([
                        $this->getNameFormComponent(),
                        TextInput::make('phone')
                            ->label('Phone number')
                            ->tel()
                            ->required()
                            ->maxLength(255)
                            ->unique(table: 'users', column: 'phone', ignoreRecord: true),
                        TextInput::make('email')
                            ->label('Email')
                            ->email()
                            ->maxLength(255)
                            ->unique(table: 'users', column: 'email', ignoreRecord: true),
                        TextInput::make('city')
                            ->label('City')
                            ->maxLength(255),
                    ]),

                Section::make('Change password')
                    ->description('Leave empty to keep your current password.')
                    ->columns(2)
                    ->schema([
                        $this->getPasswordFormComponent()->label('New password'),
                        $this->getPasswordConfirmationFormComponent(),
                        TextInput::make('currentPassword')
                            ->label('Current password')
                            ->password()
                            ->revealable()
                            ->currentPassword()
                            ->required(fn (Get $get): bool => filled($get('password')))
                            ->visible(fn (Get $get): bool => filled($get('password')))
                            ->dehydrated(false),
                    ]),
            ]);
    }

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        // Email change verification is not used in this panel; save directly.
        $record->update($data);

        return $record;
    }
}
