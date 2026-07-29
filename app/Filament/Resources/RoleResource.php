<?php

namespace App\Filament\Resources;

use App\Filament\Resources\RoleResource\Pages;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\BulkActionGroup;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Actions\Action;
use Filament\Actions\ViewAction;
use Illuminate\Support\Facades\Auth;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

class RoleResource extends Resource
{
    protected static ?string $model = Role::class;

    protected static ?string $navigationLabel = null;

    protected static ?string $modelLabel = null;

    protected static ?string $pluralModelLabel = null;

    public static function getNavigationLabel(): string
    {
        return __('filament.role.title');
    }

    public static function getModelLabel(): string
    {
        return __('filament.role.singular');
    }

    public static function getPluralModelLabel(): string
    {
        return __('filament.role.plural');
    }

    protected static ?int $navigationSort = 2;

    public static function getNavigationIcon(): ?string
    {
        return 'heroicon-o-shield-check';
    }

    public static function getNavigationGroup(): ?string
    {
        return __('filament.nav.role_permission_management');
    }

    public static function shouldRegisterNavigation(): bool
    {
        return Auth::check() && (Auth::user()->hasRole('Super Admin') || Auth::user()->can('roles.index') ?? true);
    }

    public static function form(Schema $schema): Schema
    {
        $permissions = Permission::all()->groupBy(function ($permission) {
            return self::getPermissionGroup($permission->name);
        });

        $allPermissionIds = Permission::all()->pluck('id')->toArray();

        // Ensure Super Admin always has all permissions
        $superAdminRole = Role::where('name', 'Super Admin')->first();
        if ($superAdminRole) {
            $superAdminRole->syncPermissions(Permission::all());
        }

        // Build options grouped by permission group
        $groupedOptions = [];
        $groupedDescriptions = [];
        $groupRanges = [];
        $startIndex = 0;

        foreach ($permissions->sortKeys() as $groupName => $groupPermissions) {
            $groupIds = $groupPermissions->pluck('id')->toArray();
            $endIndex = $startIndex + count($groupIds);

            foreach ($groupPermissions as $permission) {
                $groupedOptions[$permission->id] = $permission->name;
                $groupedDescriptions[$permission->id] = $groupName;
            }

            $groupRanges[$groupName] = [
                'ids' => $groupIds,
                'start' => $startIndex,
                'end' => $endIndex - 1,
            ];
            $startIndex = $endIndex;
        }



        // Add group-specific select/deselect actions
        foreach ($permissions->sortKeys() as $groupName => $groupPermissions) {
            $groupIds = $groupPermissions->pluck('id')->toArray();
        }

        return $schema
            ->schema([
                Section::make(__('filament.role.information'))
                    ->schema([
                        TextInput::make('name')
                            ->label(__('filament.role.name'))
                            ->required()
                            ->maxLength(255)
                            ->unique(ignoreRecord: true)
                            ->disabled(fn ($record) => $record?->name === 'Super Admin')
                            ->helperText(fn ($record) => $record?->name === 'Super Admin'
                                ? __('filament.role.super_admin_warning')
                                : null),
                    ])
                    ->columns(1)
                    ->columnSpan(12),
                Section::make(__('filament.role.permissions'))
                    ->description(fn ($record) => $record?->name === 'Super Admin'
                        ? __('filament.role.super_admin_permissions')
                        : __('filament.role.permissions_description'))
                    ->schema([
                        CheckboxList::make('permissions')
                            ->label('')
                            ->relationship('permissions', 'name')
                            ->options($groupedOptions)
                            ->descriptions($groupedDescriptions)
                            ->searchable()
                            ->bulkToggleable()
                            ->gridDirection('row')
                            ->columns(3)
                            ->disabled(fn ($record) => $record?->name === 'Super Admin')
                            ->helperText(fn ($record) => $record?->name === 'Super Admin'
                                ? __('filament.role.super_admin_auto_permissions')
                                : __('filament.role.helper_text')),
                    ])
                    ->collapsible()
                    ->columnSpan(12),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('id')
                    ->label(__('filament.user.id'))
                    ->sortable(),
                Tables\Columns\TextColumn::make('name')
                    ->label(__('filament.role.name'))
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('permissions_count')
                    ->counts('permissions')
                    ->label(__('filament.role.permissions'))
                    ->sortable(),
                Tables\Columns\TextColumn::make('users_count')
                    ->counts('users')
                    ->label(__('filament.role.users'))
                    ->sortable(),
                Tables\Columns\TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                //
            ])
            ->actions([
                EditAction::make()
                ->iconButton()
                    ->label(__('filament.actions.edit'))
                    ->visible(fn (Role $record): bool =>
                        $record->name !== 'Super Admin' &&
                        Auth::check() &&
                        (Auth::user()->hasRole('Super Admin') || Auth::user()->can('roles.edit'))
                    )
                    ,


                DeleteAction::make()
                ->iconButton()
                    ->label(__('filament.actions.delete'))
                    ->visible(fn (Role $record): bool =>
                        $record->name !== 'Super Admin' &&
                        Auth::check() &&
                        ( Auth::user()->hasRole('Super Admin') || Auth::user()->can('roles.delete'))
                    ),
            ])
            ->bulkActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make()
                        // ->visible(fn () => Auth::check() && (Auth::user()->can('roles.delete') ?? true)),
                ]),
            ])
            ->checkIfRecordIsSelectableUsing(fn ($record) =>
                $record->name !== 'Super Admin' &&
                Auth::check() &&
                (Auth::user()->hasRole('Super Admin') || Auth::user()->can('roles.delete'))
            );
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
            'index' => Pages\ListRoles::route('/'),
            'create' => Pages\CreateRole::route('/create'),
            'edit' => Pages\EditRole::route('/{record}/edit'),
        ];
    }

    public static function canViewAny(): bool
    {
        return Auth::check() && ( Auth::user()->hasRole('Super Admin') || Auth::user()->can('roles.index') ?? true);
    }

    public static function canCreate(): bool
    {
        return Auth::check() && ( Auth::user()->hasRole('Super Admin') || Auth::user()->can('roles.create') ?? true);
    }

    public static function canEdit($record): bool
    {
        // Super Admin role cannot be edited
        if ($record && $record->name === 'Super Admin') {
            return false;
        }
        return Auth::check() && (Auth::user()->hasRole('Super Admin') || Auth::user()->can('roles.edit') ?? true);
    }

    public static function canDelete($record): bool
    {
            // Super Admin role cannot be deleted
        if ($record && $record->name === 'Super Admin') {
            return false;
        }
        return Auth::check() && (Auth::user()->hasRole('Super Admin') || Auth::user()->can('roles.delete') ?? true);
    }

    public static function canView($record): bool
    {
            // Super Admin role can be viewed but not edited/deleted
        return Auth::check() && (Auth::user()->hasRole('Super Admin') || Auth::user()->can('roles.index') ?? true);
    }

    protected static function getPermissionGroup(string $permissionName): string
    {
        $parts = explode('.', $permissionName);

        // Special handling for Filament resource routes
        // filament.admin.resources.users.index -> group: "Users"
        if (count($parts) >= 4 && $parts[0] === 'filament' && isset($parts[2]) && $parts[2] === 'resources') {
            $resourceName = $parts[3]; // e.g., "users", "roles", "permissions"
            return Str::of($resourceName)->plural()->replace(['-', '_'], ' ')->title()->value();
        }

        // Default grouping by first part
        if (count($parts) > 1) {
            return Str::of($parts[0])->replace(['-', '_'], ' ')->title()->value();
        }
        return 'General';
    }
}
