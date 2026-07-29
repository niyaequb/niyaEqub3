<?php

namespace App\Filament\Resources\AgentPayouts;

use App\Filament\Resources\AgentPayouts\Pages\CreateAgentPayout;
use App\Filament\Resources\AgentPayouts\Pages\EditAgentPayout;
use App\Filament\Resources\AgentPayouts\Pages\ListAgentPayouts;
use App\Filament\Resources\AgentPayouts\Schemas\AgentPayoutForm;
use App\Filament\Resources\AgentPayouts\Tables\AgentPayoutsTable;
use App\Models\AgentPayout;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;

class AgentPayoutResource extends Resource
{
    protected static ?string $model = AgentPayout::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

    public static function getNavigationLabel(): string
    {
        return __('filament.agent_payout.title');
    }

    public static function getModelLabel(): string
    {
        return __('filament.agent_payout.singular');
    }

    public static function getPluralModelLabel(): string
    {
        return __('filament.agent_payout.plural');
    }

    public static function getNavigationGroup(): ?string
    {
        return __('filament.nav.administration');
    }

    public static function form(Schema $schema): Schema
    {
        return AgentPayoutForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return AgentPayoutsTable::configure($table);
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with('agent.user');
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
            'index' => ListAgentPayouts::route('/'),
            'create' => CreateAgentPayout::route('/create'),
            'edit' => EditAgentPayout::route('/{record}/edit'),
        ];
    }


    public static function shouldRegisterNavigation(): bool
    {
        return Auth::check() && (Auth::user()->hasRole('Super Admin') || Auth::user()->can('agent-payouts.index') ?? true);
    }


     public static function canViewAny(): bool
    {
        return Auth::check() && ( Auth::user()->hasRole('Super Admin') || Auth::user()->can('agent-payouts.index') ?? true);
    }

    public static function canCreate(): bool
    {
        return Auth::check() && ( Auth::user()->hasRole('Super Admin') || Auth::user()->can('agent-payouts.create') ?? true);
    }

    public static function canEdit($record): bool
    {
        return Auth::check() && (Auth::user()->hasRole('Super Admin') || Auth::user()->can('agent-payouts.edit') ?? true);
    }

    public static function canDelete($record): bool
    {
        return Auth::check() && (Auth::user()->hasRole('Super Admin') || Auth::user()->can('agent-payouts.delete') ?? true);
    }

    public static function canView($record): bool
    {
        return Auth::check() && (Auth::user()->hasRole('Super Admin') || Auth::user()->can('agent-payouts.index') ?? true);
    }
}
