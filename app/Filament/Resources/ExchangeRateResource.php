<?php

namespace App\Filament\Resources;

use App\Filament\Resources\ExchangeRateResource\Pages;
use App\Models\ExchangeRate;
use Filament\Actions\ActionGroup;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;

class ExchangeRateResource extends Resource
{
    protected static ?string $model = ExchangeRate::class;

    public static function getNavigationIcon(): ?string
    {
        return 'heroicon-o-currency-dollar';
    }

    public static function getNavigationLabel(): string
    {
        return 'Exchange Rate';
    }

    public static function getNavigationGroup(): ?string
    {
        return __('filament.nav.promotion_management');
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->schema([
            Section::make('Exchange Rate Configuration')
                ->description('Configure the exchange rate (e.g., 1 USD = 150 ETB)')
                ->schema([
                    Grid::make(4)
                        ->schema([
                            TextInput::make('currency_from')
                                ->label('Base Currency (e.g., USD)')
                                ->required()
                                ->maxLength(3)
                                ->prefix('1'),
                            TextInput::make('rate')
                                ->label('Exchange Rate (e.g., 150)  ')
                                ->numeric()
                                ->required()
                                ->step(0.0001)
                                ->prefix('='),
                            TextInput::make('currency_to')
                                ->label('Target Currency (e.g., ETB)')
                                ->required()
                                ->maxLength(3)
                                ->rules([
                                    fn (Get $get, ?Model $record): \Closure => function (string $attribute, $value, \Closure $fail) use ($get, $record) {
                                        $exists = ExchangeRate::query()
                                            ->where('currency_from', $get('currency_from'))
                                            ->where('currency_to', $value)
                                            ->when($record, fn ($query) => $query->where('id', '!=', $record->id))
                                            ->exists();

                                        if ($exists) {
                                            $fail("The exchange rate for {$get('currency_from')} to {$value} already exists.");
                                        }
                                    },
                                ]),
                            Toggle::make('is_active')
                                ->label('Active')
                                ->default(true)
                                ->required()
                                ->inline(false),
                        ]),
                ])
                ->columnSpanFull(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('currency_from')->sortable(),
                Tables\Columns\TextColumn::make('currency_to')->sortable(),
                Tables\Columns\TextColumn::make('rate')
                    ->numeric(decimalPlaces: 4)
                    ->sortable(),
                Tables\Columns\IconColumn::make('is_active')
                    ->boolean()
                    ->sortable(),
                Tables\Columns\TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
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
            'index' => Pages\ListExchangeRates::route('/'),
            'create' => Pages\CreateExchangeRate::route('/create'),
            'edit' => Pages\EditExchangeRate::route('/{record}/edit'),
        ];
    }

    public static function shouldRegisterNavigation(): bool
    {
        return Auth::check() && (Auth::user()->hasRole('Super Admin') || Auth::user()->can('admin.pages.settings') ?? true);
    }
}
