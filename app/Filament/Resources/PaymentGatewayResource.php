<?php

namespace App\Filament\Resources;

use App\Filament\Resources\PaymentGatewayResource\Pages;
use App\Models\PaymentGateway;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\BulkActionGroup;
use Filament\Forms\Components\KeyValue;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Auth;

class PaymentGatewayResource extends Resource
{
    protected static ?string $model = PaymentGateway::class;

    protected static ?string $navigationLabel = null;

    protected static ?string $modelLabel = null;

    protected static ?string $pluralModelLabel = null;

    public static function getNavigationLabel(): string
    {
        return __('filament.payment_gateway.title');
    }

    public static function getModelLabel(): string
    {
        return __('filament.payment_gateway.singular');
    }

    public static function getPluralModelLabel(): string
    {
        return __('filament.payment_gateway.plural');
    }

    protected static ?int $navigationSort = 8;

    public static function getNavigationIcon(): ?string
    {
        return 'heroicon-o-credit-card';
    }

    public static function getNavigationGroup(): ?string
    {
        return __('filament.nav.administration');
    }

    public static function shouldRegisterNavigation(): bool
    {
        return false; // Hide from navigation - use Settings page instead
    }

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->schema([
                Section::make(__('filament.payment_gateway.information'))
                    ->schema([
                        TextInput::make('name')
                            ->label(__('filament.payment_gateway.name'))
                            ->required()
                            ->maxLength(255)
                            ->disabled(fn ($record) => $record !== null)
                            ->helperText(__('filament.payment_gateway.name_helper')),
                        Toggle::make('is_active')
                            ->label(__('filament.payment_gateway.is_active'))
                            ->default(false)
                            ->helperText(__('filament.payment_gateway.is_active_helper'))
                            ->afterStateUpdated(function ($state, $set, $get, $record) {
                                // If activating this gateway, deactivate others
                                if ($state && $record) {
                                    PaymentGateway::where('id', '!=', $record->id)
                                        ->update(['is_active' => false]);
                                }
                            }),
                    ])
                    ->columns(2)
                    ->columnSpan(12),
                Section::make(__('filament.payment_gateway.configuration'))
                    ->schema([
                        \Filament\Forms\Components\TextInput::make('config.secret_key')
                            ->label(__('filament.payment_gateway.secret_key'))
                            ->password()
                            ->required()
                            ->helperText(__('filament.payment_gateway.secret_key_helper'))
                            ->dehydrated(true),
                        \Filament\Forms\Components\TextInput::make('config.public_key')
                            ->label(__('filament.payment_gateway.public_key'))
                            ->helperText(__('filament.payment_gateway.public_key_helper'))
                            ->dehydrated(true),
                        \Filament\Forms\Components\TextInput::make('config.webhook_secret')
                            ->label(__('filament.payment_gateway.webhook_secret'))
                            ->password()
                            ->helperText(__('filament.payment_gateway.webhook_secret_helper'))
                            ->dehydrated(true),
                        \Filament\Forms\Components\TextInput::make('config.webhook_url')
                            ->label(__('filament.payment_gateway.webhook_url'))
                            ->default(fn () => route('api.payment.chapa.webhook'))
                            ->required()
                            ->url()
                            ->helperText(__('filament.payment_gateway.webhook_url_helper'))
                            ->dehydrated(true),
                        \Filament\Forms\Components\TextInput::make('config.return_url')
                            ->label(__('filament.payment_gateway.return_url'))
                            ->default(fn () => url('/payment/chapa/return/{reference}'))
                            ->helperText(__('filament.payment_gateway.return_url_helper'))
                            ->dehydrated(true),
                    ])
                    ->columns(1)
                    ->columnSpan(12),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->label(__('filament.payment_gateway.name'))
                    ->searchable()
                    ->sortable()
                    ->badge(),
                Tables\Columns\IconColumn::make('is_active')
                    ->label(__('filament.payment_gateway.is_active'))
                    ->boolean()
                    ->sortable(),
                Tables\Columns\TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Tables\Filters\TernaryFilter::make('is_active')
                    ->label(__('filament.payment_gateway.is_active'))
                    ->placeholder(__('filament.member_document.all'))
                    ->trueLabel(__('filament.payment_gateway.active_only'))
                    ->falseLabel(__('filament.payment_gateway.inactive_only')),
            ])
            ->actions([
                EditAction::make()
                    ->iconButton()
                    ->label(__('filament.actions.edit'))
                    // ->visible(fn () => Auth::check() && (Auth::user()->can('payment_gateways.edit') ?? true))
                    ,
                DeleteAction::make()
                    ->iconButton()
                    ->label(__('filament.actions.delete'))
                    // ->visible(fn () => Auth::check() && (Auth::user()->can('payment_gateways.delete') ?? true))
                    ,
            ])
            ->bulkActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make()
                        // ->visible(fn () => Auth::check() && (Auth::user()->can('payment_gateways.delete') ?? true)),
                ]),
            ]);
    }

    public static function getRelations(): array
    {
        return [
            //
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListPaymentGateways::route('/'),
            'create' => Pages\CreatePaymentGateway::route('/create'),
            'edit' => Pages\EditPaymentGateway::route('/{record}/edit'),
        ];
    }

    public static function canViewAny(): bool
    {
        return true;
        if (!Auth::check()) {
            return false;
        }

        $permission = 'payment_gateways.index';
        $permissionExists = \Spatie\Permission\Models\Permission::where('name', $permission)->exists();

        if (!$permissionExists) {
            return true;
        }

        return Auth::user()->can($permission);
    }

    public static function canCreate(): bool
    {
        return true;

        if (!Auth::check()) {
            return false;
        }

        $permission = 'payment_gateways.create';
        $permissionExists = \Spatie\Permission\Models\Permission::where('name', $permission)->exists();

        if (!$permissionExists) {
            return true;
        }

        return Auth::user()->can($permission);
    }

    public static function canEdit($record): bool
    {
        return true;

        if (!Auth::check()) {
            return false;
        }

        $permission = 'payment_gateways.edit';
        $permissionExists = \Spatie\Permission\Models\Permission::where('name', $permission)->exists();

        if (!$permissionExists) {
            return true;
        }

        return Auth::user()->can($permission);
    }

    public static function canDelete($record): bool
    {
        return true;

        if (!Auth::check()) {
            return false;
        }

        $permission = 'payment_gateways.delete';
        $permissionExists = \Spatie\Permission\Models\Permission::where('name', $permission)->exists();

        if (!$permissionExists) {
            return true;
        }

        return Auth::user()->can($permission);
    }
}

