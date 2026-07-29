<?php

namespace App\Filament\Resources;

use App\Filament\Resources\PermissionResource\Pages;
use Filament\Actions\ActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\BulkActionGroup;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Illuminate\Support\Facades\Auth;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;

class PermissionResource extends Resource
{
    protected static ?string $model = Permission::class;

    protected static ?string $navigationLabel = null;

    protected static ?string $modelLabel = null;

    protected static ?string $pluralModelLabel = null;

    public static function getNavigationLabel(): string
    {
        return __('filament.permission.title');
    }

    public static function getModelLabel(): string
    {
        return __('filament.permission.singular');
    }

    public static function getPluralModelLabel(): string
    {
        return __('filament.permission.plural');
    }

    protected static ?int $navigationSort = 3;

    public static function getNavigationIcon(): ?string
    {
        return 'heroicon-o-key';
    }

    public static function getNavigationGroup(): ?string
    {
        return __('filament.nav.role_permission_management');
    }

    public static function shouldRegisterNavigation(): bool
    {
        return true; // Auth::check() && (Auth::user()->can('permissions.index') ?? true);
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->schema([
            TextInput::make('name')
                ->label(__('filament.permission.name'))
                ->required()
                ->maxLength(255)
                ->unique(ignoreRecord: true),
            TextInput::make('guard_name')
                ->label(__('filament.permission.guard_name'))
                ->default('web')
                ->required()
                ->maxLength(255)
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
                    ->label(__('filament.permission.name'))
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('group')
                    ->label(__('filament.permission.group'))
                    ->getStateUsing(fn(Permission $record): string => self::getPermissionGroup($record->name))
                    ->badge()
                    ->color(fn(string $state): string => match ($state) {
                        __('filament.permission.group') => 'gray',
                        default => 'gray',
                    }),
                Tables\Columns\TextColumn::make('guard_name')
                    ->label(__('filament.permission.guard_name'))
                    ->badge()
                    ->sortable(),
                Tables\Columns\TextColumn::make('roles_count')
                    ->counts('roles')
                    ->label(__('filament.permission.roles'))
                    ->sortable(),
                Tables\Columns\TextColumn::make('created_at')->dateTime()->sortable()->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('group')
                    ->label(__('filament.permission.group'))
                    ->options(function () {
                        $groups = Permission::all()
                            ->map(function ($permission) {
                                return self::getPermissionGroup($permission->name);
                            })
                            ->unique()
                            ->sort()
                            ->values();

                        return $groups
                            ->mapWithKeys(function ($group) {
                                return [$group => $group];
                            })
                            ->toArray();
                    })
                    ->query(function ($query, $data) {
                        if (!isset($data['value']) || !$data['value']) {
                            return $query;
                        }

                        $group = $data['value'];
                        return $query->where(function ($q) use ($group) {
                            $permissions = Permission::all()->filter(function ($permission) use ($group) {
                                return self::getPermissionGroup($permission->name) === $group;
                            });

                            $q->whereIn('id', $permissions->pluck('id'));
                        });
                    }),
            ])
            ->modifyQueryUsing(function ($query) {
                // Remove any ordering by 'group' column (which doesn't exist in the database)
                // and ensure we order by a valid column instead
                $baseQuery = $query->getQuery();

                if (isset($baseQuery->orders) && is_array($baseQuery->orders)) {
                    $baseQuery->orders = array_values(array_filter($baseQuery->orders, function($order) {
                        if (is_array($order)) {
                            $column = $order['column'] ?? $order[0] ?? null;
                        } else {
                            $column = $order;
                        }

                        // Remove orders for 'group' column (with or without backticks)
                        $column = str_replace(['`', '"', "'"], '', (string)$column);
                        return $column !== 'group';
                    }));
                }

                // Ensure we have at least one valid order clause
                $hasValidOrder = false;
                if (isset($baseQuery->orders) && !empty($baseQuery->orders)) {
                    foreach ($baseQuery->orders as $order) {
                        $column = is_array($order) ? ($order['column'] ?? $order[0] ?? null) : $order;
                        $column = str_replace(['`', '"', "'"], '', (string)$column);
                        if ($column && $column !== 'group') {
                            $hasValidOrder = true;
                            break;
                        }
                    }
                }

                if (!$hasValidOrder) {
                    $query->orderBy('name', 'asc');
                }

                return $query;
            })
            ->defaultSort('name')
            ->actions([
                // EditAction::make()
                //     ->iconButton()
                //     ->label(__('filament.actions.edit'))
                //     ->visible(fn($record) => Auth::check() && ( Auth::user()->hasRole('Super Admin') || Auth::user()->can('permissions.edit') ?? true))
                //     ,
                // DeleteAction::make()
                //     ->iconButton()
                //     ->label(__('filament.actions.delete'))
                //     ->visible(fn($record) => Auth::check() && (Auth::user()->hasRole('Super Admin') || Auth::user()->can('permissions.delete') ?? true))
            ]);
            // ->bulkActions([
            //     BulkActionGroup::make([DeleteBulkAction::make()
            //         ->visible(fn() => Auth::check() && (Auth::user()->hasRole('Super Admin') || Auth::user()->can('permissions.delete') ?? true))
            // ])])
            // ->checkIfRecordIsSelectableUsing(fn($record) => Auth::check() && (Auth::user()->can('permissions.delete') ?? true));
    }

    public static function canViewAny(): bool
    {
        return Auth::check() && (Auth::user()->hasRole('Super Admin') || Auth::user()->can('permissions.index') ?? true);
    }

    public static function canCreate(): bool
    {
        return Auth::check() && (Auth::user()->hasRole('Super Admin') || Auth::user()->can('permissions.create') ?? true);
    }

    public static function canEdit($record): bool
    {
        return Auth::check() && (Auth::user()->hasRole('Super Admin') || Auth::user()->can('permissions.edit') ?? true);
    }

    public static function canDelete($record): bool
    {
        return Auth::check() && (Auth::user()->hasRole('Super Admin') || Auth::user()->can('permissions.delete') ?? true);
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
            'index' => Pages\ListPermissions::route('/'),
            'create' => Pages\CreatePermission::route('/create'),
            'edit' => Pages\EditPermission::route('/{record}/edit'),
        ];
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
