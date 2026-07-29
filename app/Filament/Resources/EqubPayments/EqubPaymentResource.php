<?php

namespace App\Filament\Resources\EqubPayments;

use App\Filament\Resources\EqubPayments\Pages\CreateEqubPayment;
use App\Filament\Resources\EqubPayments\Pages\EditEqubPayment;
use App\Filament\Resources\EqubPayments\Pages\ListEqubPayments;
use App\Filament\Resources\EqubPayments\Schemas\EqubPaymentForm;
use App\Filament\Resources\EqubPayments\Tables\EqubPaymentsTable;
use App\Models\EqubPayment;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Auth;

class EqubPaymentResource extends Resource
{
    protected static ?string $model = EqubPayment::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCurrencyDollar;

    public static function getNavigationLabel(): string
    {
        return __('filament.equb_payment.title');
    }

    public static function getModelLabel(): string
    {
        return __('filament.equb_payment.singular');
    }

    public static function getPluralModelLabel(): string
    {
        return __('filament.equb_payment.plural');
    }

    public static function getNavigationGroup(): ?string
    {
        return __('filament.nav.equb');
    }

    public static function form(Schema $schema): Schema
    {
        return EqubPaymentForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return EqubPaymentsTable::configure($table);
    }

    public static function getEloquentQuery(): \Illuminate\Database\Eloquent\Builder
    {
        return parent::getEloquentQuery()->with(['membership.member.user', 'membership.equbGroup.package']);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListEqubPayments::route('/'),
            'create' => CreateEqubPayment::route('/create'),
            'edit' => EditEqubPayment::route('/{record}/edit'),
        ];
    }


    public static function shouldRegisterNavigation(): bool
    {
        return Auth::check() && (Auth::user()->hasRole('Super Admin') || Auth::user()->can('equb-payments.index') ?? true);
    }


     public static function canViewAny(): bool
    {
        return Auth::check() && ( Auth::user()->hasRole('Super Admin') || Auth::user()->can('equb-payments.index') ?? true);
    }

    public static function canCreate(): bool
    {
        return Auth::check() && ( Auth::user()->hasRole('Super Admin') || Auth::user()->can('equb-payments.create') ?? true);
    }

    public static function canEdit($record): bool
    {
        return Auth::check() && (Auth::user()->hasRole('Super Admin') || Auth::user()->can('equb-payments.edit') ?? true);
    }

    public static function canDelete($record): bool
    {
        return Auth::check() && (Auth::user()->hasRole('Super Admin') || Auth::user()->can('equb-payments.delete') ?? true);
    }

    public static function canView($record): bool
    {
        return Auth::check() && (Auth::user()->hasRole('Super Admin') || Auth::user()->can('equb-payments.index') ?? true);
    }
}
