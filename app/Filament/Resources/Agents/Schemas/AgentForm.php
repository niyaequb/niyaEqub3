<?php

namespace App\Filament\Resources\Agents\Schemas;

use App\Models\Agent;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Validation\Rule;

class AgentForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('User information')
                    ->schema([
                        TextInput::make('user.name')
                            ->label('Name')
                            ->required()
                            ->maxLength(255),
                        TextInput::make('user.phone')
                            ->label('Phone Number')
                            ->tel()
                            ->placeholder('+2519XXXXXXXX')
                            ->required()
                            ->maxLength(255)
                            ->rules(fn (?Agent $record): array => [
                                Rule::unique('users', 'phone')->ignore($record?->user_id),
                            ]),
                        TextInput::make('user.email')
                            ->label('Email')
                            ->email()
                            ->maxLength(255)
                            ->rules(fn (?Agent $record): array => [
                                Rule::unique('users', 'email')->ignore($record?->user_id),
                            ]),
                        TextInput::make('user.city')
                            ->label('City')
                            ->maxLength(255),
                        TextInput::make('user.password')
                            ->label('Password')
                            ->password()
                            ->revealable()
                            ->minLength(6)
                            ->maxLength(255)
                            ->helperText(fn (string $context): string => $context === 'create'
                                ? 'The agent logs in to the agent app with this phone and password.'
                                : 'Leave empty to keep the current password.')
                            ->dehydrated(fn ($state) => filled($state))
                            ->required(fn (string $context): bool => $context === 'create'),
                    ])
                    ->columns(2),
                Section::make('Agent information')
                    ->schema([
                        TextInput::make('referral_code')
                            ->label('Referral Code')
                            ->maxLength(255)
                            ->rules(fn (?Agent $record): array => [
                                Rule::unique('agents', 'referral_code')->ignore($record?->id),
                            ])
                            ->helperText('Leave empty to auto-generate a unique code.'),
                        Select::make('commission_rule_id')
                            ->label('Commission Rule Override')
                            ->relationship(
                                name: 'commissionRule',
                                titleAttribute: 'name',
                                modifyQueryUsing: fn ($query) => $query->where('is_active', true)
                            )
                            ->searchable()
                            ->preload()
                            ->nullable(),
                        Toggle::make('is_active')
                            ->label('Active')
                            ->default(true),
                    ])
                    ->columns(2),
                Section::make('Bank information')
                    ->schema([
                        TextInput::make('bank_name')
                            ->label('Bank Name')
                            ->maxLength(255),
                        TextInput::make('account_number')
                            ->label('Account Number')
                            ->maxLength(50),
                        TextInput::make('account_holder_name')
                            ->label('Account Holder Name')
                            ->maxLength(255),
                    ])
                    ->columns(2)
                    ->collapsible(),
            ]);
    }
}
