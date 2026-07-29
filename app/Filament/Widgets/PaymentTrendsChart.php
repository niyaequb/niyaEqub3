<?php

namespace App\Filament\Widgets;

use App\Models\EqubPayment;
use App\Enums\EqubPaymentStatus;
use Filament\Widgets\ChartWidget;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class PaymentTrendsChart extends ChartWidget
{
    protected static ?int $sort = 3;

    protected ?string $heading = 'Payment Trends ($Revenue)';

    protected function getData(): array
    {
        $data = EqubPayment::select(
            DB::raw('sum(amount) as total'),
            DB::raw("DATE_FORMAT(payment_date, '%Y-%m') as month")
        )
            ->where('status', EqubPaymentStatus::Paid)
            ->where('payment_date', '>=', now()->subMonths(12))
            ->groupBy('month')
            ->orderBy('month')
            ->get();

        return [
            'datasets' => [
                [
                    'label' => 'Total Paid (ETB)',
                    'data' => $data->pluck('total')->toArray(),
                    'backgroundColor' => '#10b981',
                    'borderColor' => '#10b981',
                ],
            ],
            'labels' => $data->pluck('month')->toArray(),
        ];
    }

    protected function getType(): string
    {
        return 'bar';
    }
      public static function canView(): bool
    {
        return Auth::check() && (Auth::user()->hasRole('Super Admin') || Auth::user()->can('dashboard.view.payment_trends'));
    }
}
