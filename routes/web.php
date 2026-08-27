<?php

use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Session;

use App\Http\Controllers\Api\Member\EqubPaymentController as MemberEqubPaymentController;
use App\Http\Controllers\LegalController;
use App\Http\Controllers\ReportPrintController;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;

Route::get('/', function () {
    // redirect to /admin

    return redirect('/admin');
});

Route::get('/privacy_policy', [LegalController::class, 'privacy'])->name('web.privacy');
Route::get('/terms_and_conditions', [LegalController::class, 'terms'])->name('web.terms');

// Language switching route
Route::get('/locale/{locale}', function ($locale) {
    if (in_array($locale, ['en', 'am'])) {
        Session::put('locale', $locale);
        App::setLocale($locale);
    }
    $url = request()->headers->get('referer') ?: route('home');
    $response = redirect($url);
    if (in_array($locale, ['en', 'am'])) {
        $response->cookie('locale', $locale, 60 * 24 * 365);
    }

    return $response;
})->name('locale.switch');

// Filament admin locale switch route
Route::get('/admin/locale/{locale}', function ($locale) {
    if (in_array($locale, ['en', 'am'])) {
        Session::put('locale', $locale);
        app()->setLocale($locale);
    }

    return redirect()->route('filament.admin.pages.dashboard');
})->name('filament.admin.locale.switch')->middleware('auth');

/*
|--------------------------------------------------------------------------
| Bank settlement notifications
|--------------------------------------------------------------------------
|
| Posted by a bank when a charge concludes. One route serves every bank —
| {provider} says which — so adding CBE or Awash needs no route change, only
| a block in config/payments.php.
|
| It sits outside the /api prefix, is exempt from CSRF verification, and must
| be reachable from the public internet. There is no bearer token on it:
| integrity comes from an HMAC-SHA256 signature over the raw body, checked by
| the gateway the {provider} names.
|
| The parameter is constrained to lowercase letters and digits. Without it the
| segment would be free text reaching a config lookup, and the log line that
| records a miss would carry whatever an unauthenticated caller chose to put
| there.
|
*/
Route::post('/payment/{provider}/notification', [MemberEqubPaymentController::class, 'notification'])
    ->where('provider', '[a-z0-9_]+')
    ->withoutMiddleware([VerifyCsrfToken::class])
    ->name('payment.notification');

/*
|--------------------------------------------------------------------------
| Equb report printing
|--------------------------------------------------------------------------
|
| Both routes serve report documents containing member names, phone numbers
| and payment amounts, so both sit behind `auth` and re-check the same
| permission the report page uses. A print job URL is not a share link.
|
*/
Route::middleware(['web', 'auth'])->prefix('admin')->group(function () {
    // Print-ready HTML for the browser print dialog. Filters arrive as query
    // parameters so the printed copy matches whatever the admin was looking at.
    Route::get('/equb-reports/print', ReportPrintController::class)
        ->name('admin.equb-reports.print');

    // Serves a rendered print job to the print agent's iframe.
    Route::get('/print-jobs/{job}/content', [ReportPrintController::class, 'jobContent'])
        ->name('admin.print-jobs.content');
});
