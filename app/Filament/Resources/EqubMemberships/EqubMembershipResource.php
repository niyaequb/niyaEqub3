<?php

namespace App\Filament\Resources\EqubMemberships;

use App\Filament\Resources\EqubMemberships\Pages\CreateEqubMembership;
use App\Filament\Resources\EqubMemberships\Pages\EditEqubMembership;
use App\Filament\Resources\EqubMemberships\Pages\ListEqubMemberships;
use App\Filament\Resources\EqubMemberships\Schemas\EqubMembershipForm;
use App\Filament\Resources\EqubMemberships\Tables\EqubMembershipsTable;
use App\Models\EqubMembership;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Auth;

class EqubMembershipResource extends Resource
{
    protected static ?string $model = EqubMembership::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUserPlus;

    public static function getNavigationLabel(): string
    {
        return __('filament.equb_membership.title');
    }

    public static function getModelLabel(): string
    {
        return __('filament.equb_membership.singular');
    }

    public static function getPluralModelLabel(): string
    {
        return __('filament.equb_membership.plural');
    }

    public static function getNavigationGroup(): ?string
    {
        return __('filament.nav.equb');
    }

    public static function form(Schema $schema): Schema
    {
        return EqubMembershipForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return EqubMembershipsTable::configure($table);
    }

    public static function getEloquentQuery(): \Illuminate\Database\Eloquent\Builder
    {
        return parent::getEloquentQuery()->with(['equbGroup.package', 'member.user']);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListEqubMemberships::route('/'),
            'create' => CreateEqubMembership::route('/create'),
            'edit' => EditEqubMembership::route('/{record}/edit'),
        ];
    }



    public static function shouldRegisterNavigation(): bool
    {
        return Auth::check() && (Auth::user()->hasRole('Super Admin') || Auth::user()->can('equb-memberships.index') ?? true);
    }


     public static function canViewAny(): bool
    {
        return Auth::check() && ( Auth::user()->hasRole('Super Admin') || Auth::user()->can('equb-memberships.index') ?? true);
    }

    public static function canCreate(): bool
    {
        return Auth::check() && ( Auth::user()->hasRole('Super Admin') || Auth::user()->can('equb-memberships.create') ?? true);
    }

    public static function canEdit($record): bool
    {
        return Auth::check() && (Auth::user()->hasRole('Super Admin') || Auth::user()->can('equb-memberships.edit') ?? true);
    }

    public static function canDelete($record): bool
    {
        return Auth::check() && (Auth::user()->hasRole('Super Admin') || Auth::user()->can('equb-memberships.delete') ?? true);
    }

    public static function canView($record): bool
    {
        return Auth::check() && (Auth::user()->hasRole('Super Admin') || Auth::user()->can('equb-memberships.index') ?? true);
    }
}
