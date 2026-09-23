<?php

namespace App\Filament\Resources\EqubDraws;

use App\Filament\Resources\EqubDraws\Pages\ListEqubDraws;
use App\Filament\Resources\EqubDraws\Pages\ViewEqubDraw;
use App\Filament\Resources\EqubDraws\Schemas\EqubDrawInfolist;
use App\Filament\Resources\EqubDraws\Tables\EqubDrawsTable;
use App\Models\EqubDraw;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
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

    public static function infolist(Schema $schema): Schema
    {
        return EqubDrawInfolist::configure($schema);
    }

    public static function getEloquentQuery(): \Illuminate\Database\Eloquent\Builder
    {
        return parent::getEloquentQuery()->with([
            'equbGroup.package',
            'winnerMembership.member.user',
            'winnerMembership.sponsor',
            // A group round has several winners and the list summarises them
            // on the row. Left out, that turns into a query per row.
            'winners.membership.member',
            'executedBy',
        ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListEqubDraws::route('/'),
            'view' => ViewEqubDraw::route('/{record}'),
        ];
    }

    public static function shouldRegisterNavigation(): bool
    {
        return Auth::check() && (Auth::user()->hasRole('Super Admin') || Auth::user()->can('equb-draws.index') ?? true);
    }

    public static function canViewAny(): bool
    {
        return Auth::check() && (Auth::user()->hasRole('Super Admin') || Auth::user()->can('equb-draws.index') ?? true);
    }

    public static function canCreate(): bool
    {
        return Auth::check() && (Auth::user()->hasRole('Super Admin') || Auth::user()->can('equb-draws.create') ?? true);
    }

    public static function canEdit($record): bool
    {
        return Auth::check() && (Auth::user()->hasRole('Super Admin') || Auth::user()->can('equb-draws.edit') ?? true);
    }

    /**
     * A draw is never deleted.
     *
     * It is the record that a payout was earned, and the member it names has
     * been paid on the strength of it. Removing the row does not undo any of
     * that; it only removes the evidence, and leaves a membership flagged as
     * having won with nothing to show why.
     */
    public static function canDelete($record): bool
    {
        return false;
    }

    public static function canView($record): bool
    {
        return Auth::check() && (Auth::user()->hasRole('Super Admin') || Auth::user()->can('equb-draws.index') ?? true);
    }
}
