<?php

namespace App\Filament\Resources\AgentCommissions;

use App\Filament\Resources\AgentCommissions\Pages\CreateAgentCommission;
use App\Filament\Resources\AgentCommissions\Pages\EditAgentCommission;
use App\Filament\Resources\AgentCommissions\Pages\ListAgentCommissions;
use App\Filament\Resources\AgentCommissions\Schemas\AgentCommissionForm;
use App\Filament\Resources\AgentCommissions\Tables\AgentCommissionsTable;
use App\Models\AgentCommission;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;

class AgentCommissionResource extends Resource
{
    protected static ?string $model = AgentCommission::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

    public static function getNavigationLabel(): string
    {
        return __('filament.agent_commission.title');
    }

    public static function getModelLabel(): string
    {
        return __('filament.agent_commission.singular');
    }

    public static function getPluralModelLabel(): string
    {
        return __('filament.agent_commission.plural');
    }

    public static function getNavigationGroup(): ?string
    {
        return __('filament.nav.administration');
    }

    public static function form(Schema $schema): Schema
    {
        return AgentCommissionForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return AgentCommissionsTable::configure($table);
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with(['agent.user', 'member']);
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
            'index' => ListAgentCommissions::route('/'),
            'create' => CreateAgentCommission::route('/create'),
            'edit' => EditAgentCommission::route('/{record}/edit'),
        ];
    }

    public static function shouldRegisterNavigation(): bool
    {
        return Auth::check() && (Auth::user()->hasRole('Super Admin') || Auth::user()->can('agent-commissions.index') ?? true);
    }


     public static function canViewAny(): bool
    {
        return Auth::check() && ( Auth::user()->hasRole('Super Admin') || Auth::user()->can('agent-commissions.index') ?? true);
    }

    public static function canCreate(): bool
    {
        return Auth::check() && ( Auth::user()->hasRole('Super Admin') || Auth::user()->can('agent-commissions.create') ?? true);
    }

    public static function canEdit($record): bool
    {
        return Auth::check() && (Auth::user()->hasRole('Super Admin') || Auth::user()->can('agent-commissions.edit') ?? true);
    }

    public static function canDelete($record): bool
    {
        return Auth::check() && (Auth::user()->hasRole('Super Admin') || Auth::user()->can('agent-commissions.delete') ?? true);
    }

    public static function canView($record): bool
    {
        return Auth::check() && (Auth::user()->hasRole('Super Admin') || Auth::user()->can('agent-commissions.index') ?? true);
    }
}
