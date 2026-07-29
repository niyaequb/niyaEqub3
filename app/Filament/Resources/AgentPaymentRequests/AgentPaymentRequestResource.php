<?php

namespace App\Filament\Resources\AgentPaymentRequests;

use App\Filament\Resources\AgentPaymentRequests\Pages\EditAgentPaymentRequest;
use App\Filament\Resources\AgentPaymentRequests\Pages\ListAgentPaymentRequests;
use App\Filament\Resources\AgentPaymentRequests\Pages\ViewAgentPaymentRequest;
use App\Filament\Resources\AgentPaymentRequests\Schemas\AgentPaymentRequestForm;
use App\Filament\Resources\AgentPaymentRequests\Schemas\AgentPaymentRequestInfolist;
use App\Filament\Resources\AgentPaymentRequests\Tables\AgentPaymentRequestsTable;
use App\Models\AgentPaymentRequest;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;

class AgentPaymentRequestResource extends Resource
{
    protected static ?string $model = AgentPaymentRequest::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCreditCard;

    public static function getNavigationLabel(): string
    {
        return __('filament.agent_payment_request.title');
    }

    public static function getModelLabel(): string
    {
        return __('filament.agent_payment_request.singular');
    }

    public static function getPluralModelLabel(): string
    {
        return __('filament.agent_payment_request.plural');
    }

    public static function getNavigationGroup(): ?string
    {
        return __('filament.nav.administration');
    }

    public static function form(Schema $schema): Schema
    {
        return AgentPaymentRequestForm::configure($schema);
    }

    public static function infolist(Schema $schema): Schema
    {
        return AgentPaymentRequestInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return AgentPaymentRequestsTable::configure($table);
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with('agent.user');
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListAgentPaymentRequests::route('/'),
            'view' => ViewAgentPaymentRequest::route('/{record}'),
            'edit' => EditAgentPaymentRequest::route('/{record}/edit'),
        ];
    }


     public static function shouldRegisterNavigation(): bool
    {
        return Auth::check() && (Auth::user()->hasRole('Super Admin') || Auth::user()->can('agent-payment-requests.index') ?? true);
    }


     public static function canViewAny(): bool
    {
        return Auth::check() && ( Auth::user()->hasRole('Super Admin') || Auth::user()->can('agent-payment-requests.index') ?? true);
    }

    public static function canCreate(): bool
    {
        return Auth::check() && ( Auth::user()->hasRole('Super Admin') || Auth::user()->can('agent-payment-requests.create') ?? true);
    }

    public static function canEdit($record): bool
    {
        return Auth::check() && (Auth::user()->hasRole('Super Admin') || Auth::user()->can('agent-payment-requests.edit') ?? true);
    }

    public static function canDelete($record): bool
    {
        return Auth::check() && (Auth::user()->hasRole('Super Admin') || Auth::user()->can('agent-payment-requests.delete') ?? true);
    }

    public static function canView($record): bool
    {
        return Auth::check() && (Auth::user()->hasRole('Super Admin') || Auth::user()->can('agent-payment-requests.index') ?? true);
    }
}
