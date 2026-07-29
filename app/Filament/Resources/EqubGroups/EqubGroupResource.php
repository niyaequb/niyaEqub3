<?php

namespace App\Filament\Resources\EqubGroups;

use App\Filament\Resources\EqubGroups\Pages\CreateEqubGroup;
use App\Filament\Resources\EqubGroups\Pages\EditEqubGroup;
use App\Filament\Resources\EqubGroups\Pages\ListEqubGroups;
use App\Filament\Resources\EqubGroups\Pages\ViewEqubGroup;
use App\Filament\Resources\EqubGroups\Infolists\EqubGroupInfolist;
use App\Filament\Resources\EqubGroups\RelationManagers\CohortsRelationManager;
use App\Filament\Resources\EqubGroups\RelationManagers\DrawsRelationManager;
use App\Filament\Resources\EqubGroups\RelationManagers\MembershipsRelationManager;
use App\Filament\Resources\EqubGroups\Schemas\EqubGroupForm;
use App\Filament\Resources\EqubGroups\Tables\EqubGroupsTable;
use App\Models\EqubGroup;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Auth;

class EqubGroupResource extends Resource
{
    protected static ?string $model = EqubGroup::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUserGroup;

    public static function getNavigationLabel(): string
    {
        return __('filament.equb_group.title');
    }

    public static function getModelLabel(): string
    {
        return __('filament.equb_group.singular');
    }

    public static function getPluralModelLabel(): string
    {
        return __('filament.equb_group.plural');
    }

    public static function getNavigationGroup(): ?string
    {
        return __('filament.nav.equb');
    }

    public static function form(Schema $schema): Schema
    {
        return EqubGroupForm::configure($schema);
    }

    public static function infolist(Schema $schema): Schema
    {
        return EqubGroupInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return EqubGroupsTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            MembershipsRelationManager::class,
            CohortsRelationManager::class,
            DrawsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListEqubGroups::route('/'),
            'create' => CreateEqubGroup::route('/create'),
            'view' => ViewEqubGroup::route('/{record}'),
            'edit' => EditEqubGroup::route('/{record}/edit'),
        ];
    }


    public static function shouldRegisterNavigation(): bool
    {
        return Auth::check() && (Auth::user()->hasRole('Super Admin') || Auth::user()->can('equb-groups.index') ?? true);
    }


     public static function canViewAny(): bool
    {
        return Auth::check() && ( Auth::user()->hasRole('Super Admin') || Auth::user()->can('equb-groups.index') ?? true);
    }

    public static function canCreate(): bool
    {
        return Auth::check() && ( Auth::user()->hasRole('Super Admin') || Auth::user()->can('equb-groups.create') ?? true);
    }

    public static function canEdit($record): bool
    {
        return Auth::check() && (Auth::user()->hasRole('Super Admin') || Auth::user()->can('equb-groups.edit') ?? true);
    }

    public static function canDelete($record): bool
    {
        return Auth::check() && (Auth::user()->hasRole('Super Admin') || Auth::user()->can('equb-groups.delete') ?? true);
    }

    public static function canView($record): bool
    {
        return Auth::check() && (Auth::user()->hasRole('Super Admin') || Auth::user()->can('equb-groups.index') ?? true);
    }
}
