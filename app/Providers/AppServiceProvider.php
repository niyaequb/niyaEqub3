<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use Filament\Support\Facades\FilamentView;
use Illuminate\View\View;
use Illuminate\Support\Facades\URL; // Import the URL facade

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Force HTTPS in production to fix asset loading issues
        if (config('app.env') === 'production') {
            URL::forceScheme('https');
        }

        \App\Models\EqubPayment::observe(\App\Observers\EqubPaymentObserver::class);
        \App\Models\EqubDraw::observe(\App\Observers\EqubDrawObserver::class);

        FilamentView::registerRenderHook(
            'panels::body.end',
            fn (): string => \Livewire\Livewire::mount('manual-draw-player'),
        );
    }
}
