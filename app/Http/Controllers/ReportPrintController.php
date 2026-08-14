<?php

namespace App\Http\Controllers;

use App\Models\ReportPrintJob;
use App\Services\EqubReportService;
use App\Services\ReportRenderService;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * Serves report documents to printers and print dialogs.
 *
 * Everything here returns a person's name, phone number and payment history,
 * so every entry point re-checks authorisation. Being inside /admin is not
 * itself a permission.
 */
class ReportPrintController extends Controller
{
    public function __construct(
        protected EqubReportService $reports,
        protected ReportRenderService $renderer,
    ) {}

    /**
     * Print-ready HTML for the "Print now" button.
     *
     * Returns HTML rather than a PDF because the browser's own print dialog is
     * the only path that reaches a printer the server cannot see — which, for
     * a cloud-hosted panel, is every printer the office actually owns.
     */
    public function __invoke(Request $request): Response
    {
        $this->authorizeReports();

        $validated = $request->validate([
            'period' => ['nullable', 'string', 'in:daily,weekly,monthly,custom'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
            'equb_group_ids' => ['nullable', 'array'],
            'equb_group_ids.*' => ['integer'],
            'equb_package_ids' => ['nullable', 'array'],
            'equb_package_ids.*' => ['integer'],
            'agent_ids' => ['nullable', 'array'],
            'agent_ids.*' => ['integer'],
            'payment_methods' => ['nullable', 'array'],
            'payment_methods.*' => ['string', 'in:chapa,offline,manual'],
            'statuses' => ['nullable', 'array'],
            'statuses.*' => ['string', 'in:pending,paid,failed'],
            'min_amount' => ['nullable', 'numeric'],
            'max_amount' => ['nullable', 'numeric'],
            'search' => ['nullable', 'string', 'max:120'],
            'paper' => ['nullable', 'string'],
            'autoprint' => ['nullable'],
        ]);

        $report = $this->reports->build($validated);

        $html = $this->renderer->html($report, [
            'paper' => $validated['paper'] ?? 'a4',
            'generated_by' => Auth::user()?->name,
        ]);

        // Opening the print dialog on load is what makes the button feel like
        // a print button. Deferred to `load` so the fonts and table layout
        // have settled — printing mid-layout drops rows in Chrome.
        if ($request->boolean('autoprint')) {
            $html = str_replace(
                '</body>',
                <<<'HTML'
                <script>
                    window.addEventListener('load', function () {
                        setTimeout(function () { window.print(); }, 350);
                    });
                </script>
                </body>
                HTML,
                $html,
            );
        }

        return response($html, 200, [
            'Content-Type' => 'text/html; charset=UTF-8',
            // Contains member data: never let a proxy or the browser keep it.
            'Cache-Control' => 'no-store, no-cache, must-revalidate, private',
        ]);
    }

    /**
     * Serves a queued print job's rendered document to the print agent.
     */
    public function jobContent(Request $request, ReportPrintJob $job): Response
    {
        $this->authorizeReports();

        $contents = $job->fileContents();

        abort_if($contents === null, 404, 'The rendered report is no longer on disk.');

        $mime = match ($job->format) {
            'pdf' => 'application/pdf',
            'escpos' => 'text/plain; charset=UTF-8',
            default => 'text/html; charset=UTF-8',
        };

        return response($contents, 200, [
            'Content-Type' => $mime,
            'Content-Disposition' => 'inline; filename="'.\Illuminate\Support\Str::slug($job->title).'.'.
                match ($job->format) { 'pdf' => 'pdf', 'escpos' => 'txt', default => 'html' }.'"',
            'Cache-Control' => 'no-store, no-cache, must-revalidate, private',
            // The agent loads this into an iframe on the same origin; block
            // anyone else framing it.
            'X-Frame-Options' => 'SAMEORIGIN',
        ]);
    }

    /**
     * Same rule the report page enforces, applied again at the document.
     * A URL that renders member data must not be reachable by an admin who
     * cannot open the report itself.
     */
    protected function authorizeReports(): void
    {
        $user = Auth::user();

        if (! $user || ! ($user->hasRole('Super Admin') || $user->can('admin.pages.equb-reports'))) {
            throw new AccessDeniedHttpException('You do not have permission to view Equb reports.');
        }
    }
}
