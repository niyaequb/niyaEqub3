<?php

namespace App\Filament\Resources\Members\Schemas;

use App\Models\Agent;
use App\Models\Member;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;
use Illuminate\Validation\Rule;

class MemberForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('user.phone')
                    ->label('Phone Number')
                    ->required()
                    ->maxLength(255)
                    ->rules(fn (?Member $record): array => [
                        Rule::unique('users', 'phone')->ignore($record?->user_id),
                    ]),
                TextInput::make('user.email')
                    ->label('Email')
                    ->email()
                    ->maxLength(255)
                    ->rules(fn (?Member $record): array => [
                        Rule::unique('users', 'email')->ignore($record?->user_id),
                    ]),
                TextInput::make('user.password')
                    ->label('Password')
                    ->password()
                    ->maxLength(255)
                    ->dehydrated(fn ($state) => filled($state))
                    ->required(fn (string $context): bool => $context === 'create'),
                TextInput::make('full_name')
                    ->label('Full Name')
                    ->required()
                    ->maxLength(255),
                Select::make('gender')
                    ->label('Gender')
                    ->options([
                        'male' => 'Male',
                        'female' => 'Female',
                    ]),
                DatePicker::make('date_of_birth')
                    ->label('Date of Birth'),
                TextInput::make('address')
                    ->label('Address')
                    ->maxLength(255),
                TextInput::make('city')
                    ->label('City')
                    ->maxLength(255),
                Select::make('agent_id')
                    ->label('Agent')
                    ->relationship(
                        name: 'agent',
                        titleAttribute: 'referral_code'
                    )
                    ->getOptionLabelFromRecordUsing(
                        fn (Agent $record) => $record->user?->name.' ('.$record->user?->phone.')'
                    )
                    ->searchable()
                    ->preload(),
            ]);
    }
}
