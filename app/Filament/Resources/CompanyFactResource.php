<?php

namespace App\Filament\Resources;

use App\Filament\Resources\CompanyFactResource\Pages;
use App\Models\CompanyFact;
use Filament\Actions\ActionGroup;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Auth;

class CompanyFactResource extends Resource
{
    protected static ?string $model = CompanyFact::class;

    public static function getNavigationIcon(): ?string
    {
        return 'heroicon-o-chart-bar';
    }

    public static function getNavigationGroup(): ?string
    {
        return __('filament.nav.promotion_management');
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->schema([
            Section::make('Company Fact Details')
                ->schema([
                    TextInput::make('label')
                        ->label('Label (e.g., "Founded", "Employees")')
                        ->required()
                        ->maxLength(255),
                    TextInput::make('value')
                        ->label('Value (e.g., "2020", "50+")')
                        ->required()
                        ->maxLength(255),
                    Toggle::make('is_active')
                        ->default(true)
                        ->required(),
                ])->columnSpanFull(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('label')
                    ->searchable(),
                Tables\Columns\TextColumn::make('value')
                    ->searchable(),
                Tables\Columns\IconColumn::make('is_active')
                    ->boolean(),
            ])
            ->filters([
                Tables\Filters\TernaryFilter::make('is_active'),
            ])
            ->actions([
                ActionGroup::make([
                    EditAction::make(),
                    DeleteAction::make(),
                ]),
            ])
            ->bulkActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListCompanyFacts::route('/'),
            'create' => Pages\CreateCompanyFact::route('/create'),
            'edit' => Pages\EditCompanyFact::route('/{record}/edit'),
        ];
    }

    public static function shouldRegisterNavigation(): bool
    {
        return Auth::check() && (Auth::user()->hasRole('Super Admin') || Auth::user()->can('admin.pages.settings') ?? true);
    }
}
