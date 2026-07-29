<?php

namespace App\Filament\Widgets;

use App\Models\EqubPayment;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget as BaseWidget;
use Illuminate\Support\Facades\Auth;

class LatestEqubPayments extends BaseWidget
{
    protected static ?int $sort = 4;

    protected int | string | array $columnSpan = 'full';

    public function table(Table $table): Table
    {
        return $table
            ->query(
                EqubPayment::query()->latest('payment_date')->limit(10)
            )
            ->columns([
                Tables\Columns\TextColumn::make('membership.member.full_name')
                    ->label('Member')
                    ->searchable(),
                Tables\Columns\TextColumn::make('amount')
                    ->money('ETB')
                    ->sortable(),
                Tables\Columns\TextColumn::make('payment_date')
                    ->dateTime()
                    ->sortable(),
                Tables\Columns\TextColumn::make('payment_method')
                    ->badge(),
                Tables\Columns\TextColumn::make('status')
                    ->badge(),
            ]);
    }

      public static function canView(): bool
    {
        return Auth::check() && (Auth::user()->hasRole('Super Admin') || Auth::user()->can('dashboard.view.latest_payments'));
    }
}
