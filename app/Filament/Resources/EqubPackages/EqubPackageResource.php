<?php

namespace App\Filament\Resources\EqubPackages;

use App\Filament\Resources\EqubPackages\Pages\CreateEqubPackage;
use App\Filament\Resources\EqubPackages\Pages\EditEqubPackage;
use App\Filament\Resources\EqubPackages\Pages\ListEqubPackages;
use App\Filament\Resources\EqubPackages\Schemas\EqubPackageForm;
use App\Filament\Resources\EqubPackages\Tables\EqubPackagesTable;
use App\Models\EqubPackage;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Auth;

class EqubPackageResource extends Resource
{
    protected static ?string $model = EqubPackage::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

    public static function getNavigationLabel(): string
    {
        return __('filament.equb_package.title');
    }

    public static function getModelLabel(): string
    {
        return __('filament.equb_package.singular');
    }

    public static function getPluralModelLabel(): string
    {
        return __('filament.equb_package.plural');
    }

    public static function getNavigationGroup(): ?string
    {
        return __('filament.nav.equb');
    }

    public static function form(Schema $schema): Schema
    {
        return EqubPackageForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return EqubPackagesTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListEqubPackages::route('/'),
            'create' => CreateEqubPackage::route('/create'),
            'edit' => EditEqubPackage::route('/{record}/edit'),
        ];
    }


    public static function shouldRegisterNavigation(): bool
    {
        return Auth::check() && (Auth::user()->hasRole('Super Admin') || Auth::user()->can('equb-packages.index') ?? true);
    }


     public static function canViewAny(): bool
    {
        return Auth::check() && ( Auth::user()->hasRole('Super Admin') || Auth::user()->can('equb-packages.index') ?? true);
    }

    public static function canCreate(): bool
    {
        return Auth::check() && ( Auth::user()->hasRole('Super Admin') || Auth::user()->can('equb-packages.create') ?? true);
    }

    public static function canEdit($record): bool
    {
        return Auth::check() && (Auth::user()->hasRole('Super Admin') || Auth::user()->can('equb-packages.edit') ?? true);
    }

    public static function canDelete($record): bool
    {
        return Auth::check() && (Auth::user()->hasRole('Super Admin') || Auth::user()->can('equb-packages.delete') ?? true);
    }

    public static function canView($record): bool
    {
        return Auth::check() && (Auth::user()->hasRole('Super Admin') || Auth::user()->can('equb-packages.index') ?? true);
    }
}
