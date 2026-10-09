<?php

namespace App\Filament\Resources\EqubJoinLogs;

use App\Filament\Resources\EqubJoinLogs\Pages\ListEqubJoinLogs;
use App\Filament\Resources\EqubJoinLogs\Tables\EqubJoinLogsTable;
use App\Models\EqubJoinLog;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

/**
 * Admin → Equb → Join log.
 *
 * Every place ever taken in an Equb: who (name and phone), which Equb and
 * package, when, and from where. Read-only by design — the log is the record,
 * so nobody can create, edit or delete a row of it from the panel. Whoever
 * may see Equb memberships may see it.
 */
class EqubJoinLogResource extends Resource
{
    protected static ?string $model = EqubJoinLog::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-clipboard-document-list';

    protected static ?int $navigationSort = 30;

    public static function getNavigationLabel(): string
    {
        return __('Join log');
    }

    public static function getModelLabel(): string
    {
        return __('join');
    }

    public static function getPluralModelLabel(): string
    {
        return __('Join log');
    }

    public static function getNavigationGroup(): ?string
    {
        return __('filament.nav.equb');
    }

    public static function table(Table $table): Table
    {
        return EqubJoinLogsTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListEqubJoinLogs::route('/'),
        ];
    }

    protected static function allowed(): bool
    {
        return Auth::check()
            && (Auth::user()->hasRole('Super Admin') || Auth::user()->can('equb-memberships.index'));
    }

    public static function shouldRegisterNavigation(): bool
    {
        return static::allowed();
    }

    public static function canViewAny(): bool
    {
        return static::allowed();
    }

    public static function canView(Model $record): bool
    {
        return static::allowed();
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit(Model $record): bool
    {
        return false;
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    public static function canDeleteAny(): bool
    {
        return false;
    }
}
