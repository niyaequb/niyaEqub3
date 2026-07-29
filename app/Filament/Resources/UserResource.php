<?php

namespace App\Filament\Resources;

use App\Filament\Resources\UserResource\Pages;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use App\Filament\Support\TableExportHelper;

class UserResource extends Resource
{
    protected static ?string $model = User::class;

    protected static ?string $navigationLabel = null;

    protected static ?string $modelLabel = null;

    protected static ?string $pluralModelLabel = null;

    public static function getNavigationLabel(): string
    {
        return __('filament.user.title');
    }

    public static function getModelLabel(): string
    {
        return __('filament.user.singular');
    }

    public static function getPluralModelLabel(): string
    {
        return __('filament.user.plural');
    }

    protected static ?int $navigationSort = 1;

    public static function getNavigationIcon(): ?string
    {
        return 'heroicon-o-user-plus';
    }

    public static function getNavigationGroup(): ?string
    {
        return __('filament.nav.administration');
    }

    public static function shouldRegisterNavigation(): bool
    {
        return Auth::check() && (Auth::user()->hasRole('Super Admin') || Auth::user()->can('users.index') ?? true);
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->schema([
            Section::make(__('filament.user.information'))
                ->schema([
                    TextInput::make('name')->label(__('filament.user.name'))->required()->maxLength(255),
                    TextInput::make('phone')->label('Phone Number')->required()->maxLength(255)->unique(table: 'users', column: 'phone', ignoreRecord: true),
                    TextInput::make('email')->label(__('filament.user.email'))->email()->maxLength(255)->unique(table: 'users', column: 'email', ignoreRecord: true),
                    TextInput::make('city')->label('City')->maxLength(255),
                    Select::make('type')
                        ->label('User Type')
                        ->options([
                            'admin' => 'Admin',
                            'staff' => 'Staff',
                            'agent' => 'Agent',
                            'member' => 'Member',
                        ])
                        ->required()
                        ->default('staff'),
                    TextInput::make('referral_code')->label('Referral Code')->maxLength(255)->unique(table: 'users', column: 'referral_code', ignoreRecord: true)->helperText('Optional, used for agents.'),
                    TextInput::make('password')->label(__('filament.user.password'))->password()->maxLength(255)->dehydrated(fn ($state) => filled($state))->required(fn (string $context): bool => $context === 'create'),
                    FileUpload::make('profile_picture')
                        ->label('Profile Picture')
                        ->image()
                        ->directory('profile-pictures')
                        ->visibility('public')
                        ->maxSize(2048)
                        ->acceptedFileTypes(['image/jpeg', 'image/png', 'image/gif', 'image/webp'])
                        ->imageEditor()
                        ->circleCropper()
                        ->helperText('Upload a profile picture. Max size: 2MB. Formats: JPG, PNG, GIF, WebP.'),
                    Toggle::make('is_active')->label('Active')->default(true),
                    Toggle::make('phone_verified_at')->label('Phone Verified')->default(true)->dehydrated(true),
                ])
                ->columns(2),
            Section::make(__('filament.user.roles_permissions'))
                ->schema([
                    CheckboxList::make('roles')->label(__('filament.user.roles'))->relationship('roles', 'name')->searchable()->bulkToggleable()->columns(2)->visible(fn ($context) => in_array($context, ['create', 'edit'])),
                    // CheckboxList::make('permissions')
                    //     ->label(__('filament.user.direct_permissions'))
                    //     ->relationship('permissions', 'name')
                    //     ->options(function () {
                    //         $permissions = Permission::all()->groupBy(function ($permission) {
                    //             return self::getPermissionGroup($permission->name);
                    //         });

                    //         $options = [];
                    //         foreach ($permissions as $groupPermissions) {
                    //             foreach ($groupPermissions as $permission) {
                    //                 $options[$permission->id] = $permission->name;
                    //             }
                    //         }

                    //         return $options;
                    //     })
                    //     ->descriptions(
                    //         Permission::all()->mapWithKeys(function ($permission) {
                    //             $group = self::getPermissionGroup($permission->name);

                    //             return [$permission->id => $group];
                    //         })->toArray()
                    //     )
                    //     ->searchable()
                    //     ->bulkToggleable()
                    //     ->columns(3)
                    //     ->visible(fn ($context) => in_array($context, ['create', 'edit'])),
                ])
                ->collapsible()
                ->visible(fn ($context) => in_array($context, ['create', 'edit'])),
        ]);
    }

    public static function table(Table $table): Table
    {
        $table
            ->columns([
                // / make all of three column on one column image on left side , name and role on right side

                Tables\Columns\TextColumn::make('id')->label(__('filament.user.id'))->sortable(),
                TextColumn::make('name')->label('Full Name')->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('phone')->label('Phone')->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('email')->label('Email')->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('profile_info')
                    ->label('User')
                    ->getStateUsing(function ($record) {
                        $avatarUrl = $record->profile_picture_url ?: asset('images/default-avatar.png');
                        $name = e($record->name);

                        $roles = $record->roles->pluck('name')->implode(', ');
                        if ($roles) {
                            $roles = '<br><small class="text-gray-500">'.$roles.'</small>';
                        }

                        return '
                            <div class="flex items-center gap-3">
                                <img src="'.
                            $avatarUrl.
                            '" alt="'.
                            e($record->name).
                            '" class="h-8 w-8 rounded-full object-cover">
                                <div class="leading-tight">
                                    <span class="font-bold">'.
                            $name.
                            '</span>'.
                            $roles.
                            '</div>
                            </div>
                        ';
                    })
                    ->html()
                    ->lineClamp(2)
                    ->searchable(['name', 'phone', 'email'])
                    ->sortable('name'),

                TextColumn::make('contact')
                    ->label('Contact')
                    ->getStateUsing(function ($record) {
                        $phone = $record->phone ? '<div>'.$record->phone.'</div>' : '';
                        $email = $record->email ? '<small class="text-gray-500  ">'.$record->email.'</small>' : '';

                        return $phone.$email;
                    })
                    ->html()
                    ->searchable(['phone', 'email']),
                Tables\Columns\TextColumn::make('type')->label('Type')->badge()->sortable(),
                Tables\Columns\IconColumn::make('is_active')->label('Active')->boolean()->sortable(),
                Tables\Columns\IconColumn::make('phone_verified_at')->label('Phone Verified')->boolean()->getStateUsing(fn (User $record): bool => (bool) $record->phone_verified_at)->sortable(),
                Tables\Columns\TextColumn::make('last_login_at')->label('Last Login')->dateTime()->sortable()->toggleable(),
                Tables\Columns\TextColumn::make('created_at')->dateTime()->sortable()->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('updated_at')->dateTime()->sortable()->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Tables\Filters\TernaryFilter::make('is_active')->label('Active'), 
                Tables\Filters\TernaryFilter::make('phone_verified_at')->label('Phone Verified'),
                Tables\Filters\SelectFilter::make('type')
                    ->label('User Type')
                    ->options([
                        'admin' => 'Admin',
                        'staff' => 'Staff',
                        'agent' => 'Agent',
                        'member' => 'Member',
                    ])
                    ->default('staff'),
            ])
            ->actions([
                ActionGroup::make([
                    ViewAction::make()
                    ->visible(fn (): bool =>
                        Auth::check() &&
                         ( Auth::user()->hasRole('Super Admin') || Auth::user()->can('users.index')))
                    ->label(__('filament.actions.view')),
                    EditAction::make()
                    ->visible(fn (): bool =>
                        Auth::check() &&
                         ( Auth::user()->hasRole('Super Admin') || Auth::user()->can('users.edit')))
                    ->label(__('filament.actions.edit')),
                    Action::make('activate')
                        ->label('Activate')
                        ->color('success')
                        ->icon('heroicon-s-check-circle')
                        ->visible(function (User $record): bool {
                            return Auth::check()
                                && (Auth::user()->hasRole('Super Admin') || Auth::user()->can('users.edit'))
                                && ! $record->is_active;
                        })
                        ->action(function (User $record): void {
                            $record->update(['is_active' => true]);
                        }),
                    Action::make('deactivate')
                        ->label('Deactivate')
                        ->icon('heroicon-s-x-mark')
                        ->color('danger')
                        ->visible(function (User $record): bool {
                            return Auth::check()
                                && (Auth::user()->hasRole('Super Admin') || Auth::user()->can('users.edit'))
                                && $record->is_active;
                        })
                        ->action(function (User $record): void {
                            $record->update(['is_active' => false]);
                        }),
                   Action::make('verify_phone')
                    ->label('Verify Phone')
                    ->icon('heroicon-s-check-circle')
                    ->color('success')
                    ->visible(function (User $record): bool {
                        return Auth::check()
                            && (Auth::user()->hasRole('Super Admin') || Auth::user()->can('users.edit'))
                            && ! $record->phone_verified_at;
                    })
                    ->action(function (User $record): void {
                        $record->update([
                            'phone_verified_at' => now(),
                        ]);
                    }),
                    Action::make('unverify_phone')
                        ->color('danger')
                        ->icon('heroicon-s-x-circle')
                        ->label('Unverify Phone')
                        ->visible(function (User $record): bool {
                            return Auth::check()
                                && (Auth::user()->hasRole('Super Admin') || Auth::user()->can('users.edit'))
                                && (bool) $record->phone_verified_at;
                        })
                        ->action(function (User $record): void {
                            $record->update(['phone_verified_at' => null]);
                        }),
                    DeleteAction::make()
                    ->label(__('filament.actions.delete'))
                    ->visible(function (User $record): bool {
                        return Auth::check()
                            && (Auth::user()->hasRole('Super Admin') || Auth::user()->can('users.delete'))
                            && ! $record->hasRole('Super Admin');
                    }),
                ])
                    ->icon('heroicon-m-ellipsis-vertical')
                    ->iconButton()
                    ->label(''),
            ])
            ->bulkActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make()
                     ->visible(function (): bool {
                        return Auth::check() &&
                         ( Auth::user()->hasRole('Super Admin') || Auth::user()->can('users.delete'));
                     })
                        ->deselectRecordsAfterCompletion()
                        ->action(function ($records) {
                            // Filter out users with super_admin role from bulk delete
                            $records->filter(fn (User $record) => ! $record->hasRole('super_admin'))->each(fn ($record) => $record->delete());
                        }),
                ]),
            ])
            ->checkIfRecordIsSelectableUsing(fn (User $record) => ! $record->hasRole('super_admin') && Auth::check() && (Auth::user()->can('users.delete') ?? true));

        \App\Filament\Support\TableExportHelper::attach($table, 'Users');

        return $table;
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
            'index' => Pages\ListUsers::route('/'),
            'create' => Pages\CreateUser::route('/create'),
            'edit' => Pages\EditUser::route('/{record}/edit'),
            'view' => Pages\ViewUser::route('/{record}'),
        ];
    }

    public static function canViewAny(): bool
    {
        return Auth::check() && (Auth::user()->hasRole('Super Admin') || Auth::user()->can('users.index') ?? true);
    }

    public static function canCreate(): bool
    {

        return Auth::check() && (Auth::user()->hasRole('Super Admin') || Auth::user()->can('users.create') ?? true);
    }

    public static function canEdit($record): bool
    {

        // Users with Super Admin role cannot be edited
        if ($record && $record->hasRole('Super Admin')) {
            return false;
        }

        return Auth::check() && (Auth::user()->can('users.edit') ?? true);
    }

    public static function canDelete($record): bool
    {

        // Users with Super Admin role cannot be deleted
        if ($record && $record->hasRole('Super Admin')) {
            return false;
        }

        return Auth::check() && (Auth::user()->can('users.delete') ?? true);
    }

    protected static function getPermissionGroup(string $permissionName): string
    {
        $parts = explode('.', $permissionName);

        // Special handling for Filament resource routes
        // filament.admin.resources.users.index -> group: "Users"
        if (count($parts) >= 4 && $parts[0] === 'filament' && isset($parts[2]) && $parts[2] === 'resources') {
            $resourceName = $parts[3]; // e.g., "users", "roles", "permissions"

            return Str::of($resourceName)
                ->plural()
                ->replace(['-', '_'], ' ')
                ->title()
                ->value();
        }

        // Default grouping by first part
        if (count($parts) > 1) {
            return Str::of($parts[0])
                ->replace(['-', '_'], ' ')
                ->title()
                ->value();
        }

        return 'General';
    }
}
