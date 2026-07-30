<?php

namespace App\Filament\Resources\MemberEqubGroups;

use App\Filament\Resources\MemberEqubGroups\Pages\CreateMemberEqubGroup;
use App\Filament\Resources\MemberEqubGroups\Pages\EditMemberEqubGroup;
use App\Filament\Resources\MemberEqubGroups\Pages\GroupLedger;
use App\Filament\Resources\MemberEqubGroups\Pages\ListMemberEqubGroups;
use App\Filament\Resources\MemberEqubGroups\Tables\MemberEqubGroupsTable;
use App\Models\EqubGroup;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;

/**
 * Member-created group Equbs. Same underlying table as EqubGroupResource,
 * scoped to rows that have an owner.
 */
class MemberEqubGroupResource extends Resource
{
    protected static ?string $model = EqubGroup::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUsers;

    protected static ?string $slug = 'member-equb-groups';

    public static function getNavigationLabel(): string
    {
        return __('filament.member_equb_group.title');
    }

    public static function getModelLabel(): string
    {
        return __('filament.member_equb_group.singular');
    }

    public static function getPluralModelLabel(): string
    {
        return __('filament.member_equb_group.plural');
    }

    public static function getNavigationGroup(): ?string
    {
        return __('filament.nav.equb');
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->whereNotNull('owner_member_id');
    }

    public static function getNavigationBadge(): ?string
    {
        $pending = static::getEloquentQuery()->where('moderation_status', 'pending')->count();

        return $pending > 0 ? (string) $pending : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'warning';
    }

    public static function form(Schema $schema): Schema
    {
        return MemberEqubGroupForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return MemberEqubGroupsTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListMemberEqubGroups::route('/'),
            'create' => CreateMemberEqubGroup::route('/create'),
            'edit' => EditMemberEqubGroup::route('/{record}/edit'),
            'ledger' => GroupLedger::route('/{record}/ledger'),
        ];
    }

    public static function canEdit($record): bool
    {
        return Auth::check() && (
            Auth::user()->hasRole('Super Admin')
            || Auth::user()->can('member-equb-groups.edit')
        );
    }

    public static function canDelete($record): bool
    {
        return Auth::check() && (
            Auth::user()->hasRole('Super Admin')
            || Auth::user()->can('member-equb-groups.delete')
        );
    }

    public static function canCreate(): bool
    {
        // Members create these in the app; admins can also open one on a
        // member's behalf, which skips the approval queue.
        return Auth::check() && (Auth::user()->hasRole('Super Admin') || Auth::user()->can('member-equb-groups.create') ?? true);
    }

    public static function shouldRegisterNavigation(): bool
    {
        return Auth::check() && (Auth::user()->hasRole('Super Admin') || Auth::user()->can('member-equb-groups.index') ?? true);
    }

    public static function canViewAny(): bool
    {
        return Auth::check() && (Auth::user()->hasRole('Super Admin') || Auth::user()->can('member-equb-groups.index') ?? true);
    }
}
