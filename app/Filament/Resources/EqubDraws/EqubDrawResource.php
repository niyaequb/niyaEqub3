<?php

namespace App\Filament\Resources\EqubDraws;

use App\Filament\Resources\EqubDraws\Pages\ListEqubDraws;
use App\Filament\Resources\EqubDraws\Tables\EqubDrawsTable;
use App\Models\EqubDraw;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Auth;

class EqubDrawResource extends Resource
{
    protected static ?string $model = EqubDraw::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedTicket;

    public static function getNavigationLabel(): string
    {
        return __('filament.equb_draw.title');
    }

    public static function getModelLabel(): string
    {
        return __('filament.equb_draw.singular');
    }

    public static function getPluralModelLabel(): string
    {
        return __('filament.equb_draw.plural');
    }

    public static function getNavigationGroup(): ?string
    {
        return __('filament.nav.equb');
    }

    public static function table(Table $table): Table
    {
        return EqubDrawsTable::configure($table);
    }

    public static function getEloquentQuery(): \Illuminate\Database\Eloquent\Builder
    {
        return parent::getEloquentQuery()->with(['equbGroup.package', 'winnerMembership.member.user', 'executedBy']);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListEqubDraws::route('/'),
        ];
    }


    public static function shouldRegisterNavigation(): bool
    {
        return Auth::check() && (Auth::user()->hasRole('Super Admin') || Auth::user()->can('equb-draws.index') ?? true);
    }


     public static function canViewAny(): bool
    {
        return Auth::check() && ( Auth::user()->hasRole('Super Admin') || Auth::user()->can('equb-draws.index') ?? true);
    }

    public static function canCreate(): bool
    {
        return Auth::check() && ( Auth::user()->hasRole('Super Admin') || Auth::user()->can('equb-draws.create') ?? true);
    }

    public static function canEdit($record): bool
    {
        return Auth::check() && (Auth::user()->hasRole('Super Admin') || Auth::user()->can('equb-draws.edit') ?? true);
    }

    public static function canDelete($record): bool
    {
        return Auth::check() && (Auth::user()->hasRole('Super Admin') || Auth::user()->can('equb-draws.delete') ?? true);
    }

    public static function canView($record): bool
    {
        return Auth::check() && (Auth::user()->hasRole('Super Admin') || Auth::user()->can('equb-draws.index') ?? true);
    }
}
