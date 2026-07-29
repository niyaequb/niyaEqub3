<?php

namespace App\Providers;

use App\Events\MemberRegistered;
use App\Events\PaymentCompleted;
use App\Listeners\HandleMemberRegisteredCommission;
use App\Listeners\HandlePaymentCompletedCommission;
use App\Models\Member;
use App\Models\Payment;
use App\Observers\MemberObserver;
use App\Observers\PaymentObserver;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

class EventServiceProvider extends ServiceProvider
{
    /**
     * Register services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap services.
     */
    public function boot(): void
    {
        Event::listen(MemberRegistered::class, HandleMemberRegisteredCommission::class);
        Event::listen(PaymentCompleted::class, HandlePaymentCompletedCommission::class);

        Member::observe(MemberObserver::class);
        Payment::observe(PaymentObserver::class);
    }
}
