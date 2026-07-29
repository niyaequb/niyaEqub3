<?php

namespace App\Filament\Resources\CommissionRules;

use App\Filament\Resources\CommissionRules\Pages\CreateCommissionRule;
use App\Filament\Resources\CommissionRules\Pages\EditCommissionRule;
use App\Filament\Resources\CommissionRules\Pages\ListCommissionRules;
use App\Filament\Resources\CommissionRules\Schemas\CommissionRuleForm;
use App\Filament\Resources\CommissionRules\Tables\CommissionRulesTable;
use App\Models\CommissionRule;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Auth;

class CommissionRuleResource extends Resource
{
    protected static ?string $model = CommissionRule::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

    public static function getNavigationLabel(): string
    {
        return __('filament.commission_rule.title');
    }

    public static function getModelLabel(): string
    {
        return __('filament.commission_rule.singular');
    }

    public static function getPluralModelLabel(): string
    {
        return __('filament.commission_rule.plural');
    }

    public static function getNavigationGroup(): ?string
    {
        return __('filament.nav.administration');
    }

    public static function form(Schema $schema): Schema
    {
        return CommissionRuleForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return CommissionRulesTable::configure($table);
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
            'index' => ListCommissionRules::route('/'),
            'create' => CreateCommissionRule::route('/create'),
            'edit' => EditCommissionRule::route('/{record}/edit'),
        ];
    }


    public static function shouldRegisterNavigation(): bool
    {
        return Auth::check() && (Auth::user()->hasRole('Super Admin') || Auth::user()->can('commission-rules.index') ?? true);
    }


     public static function canViewAny(): bool
    {
        return Auth::check() && ( Auth::user()->hasRole('Super Admin') || Auth::user()->can('commission-rules.index') ?? true);
    }

    public static function canCreate(): bool
    {
        return Auth::check() && ( Auth::user()->hasRole('Super Admin') || Auth::user()->can('commission-rules.create') ?? true);
    }

    public static function canEdit($record): bool
    {
        return Auth::check() && (Auth::user()->hasRole('Super Admin') || Auth::user()->can('commission-rules.edit') ?? true);
    }

    public static function canDelete($record): bool
    {
        return Auth::check() && (Auth::user()->hasRole('Super Admin') || Auth::user()->can('commission-rules.delete') ?? true);
    }

    public static function canView($record): bool
    {
        return Auth::check() && (Auth::user()->hasRole('Super Admin') || Auth::user()->can('commission-rules.index') ?? true);
    }
}
