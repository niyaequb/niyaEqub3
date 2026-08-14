<?php

namespace App\Filament\Pages;

use App\Models\ReportPrintJob;
use App\Services\ReportPrintService;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

/**
 * The bridge between the server and a printer on someone's desk.
 *
 * The server cannot open a connection to a USB printer — there is no route to
 * it. So instead of the server pushing, the office pulls: leave this page open
 * on the machine the printer is attached to, and it polls the queue, loads each
 * waiting report into a hidden iframe, and calls print() on it. Whatever printer
 * that browser can reach, this page can print to.
 *
 * Claiming is what stops two open tabs printing the same report twice: the claim
 * is a conditional UPDATE, so only one tab wins the row.
 */
class PrintAgent extends Page
{
    protected string $view = 'filament.pages.print-agent';

    protected static ?int $navigationSort = 8;

    /** Identifies this browser tab so claims can be traced back to a machine. */
    public string $agentId = '';

    /** Set to true by the operator; nothing prints until it is. */
    public bool $listening = false;

    /** The job currently loaded in the iframe, if any. */
    public ?int $activeJobId = null;

    public ?string $activeJobTitle = null;

    public int $printedThisSession = 0;

    public ?string $lastPrintedAt = null;

    /** Surfaced in the UI so a silent failure is never invisible. */
    public ?string $lastError = null;

    public static function getNavigationIcon(): ?string
    {
        return 'heroicon-o-printer';
    }

    public static function getNavigationLabel(): string
    {
        return __('filament.print_agent.title');
    }

    public function getTitle(): string
    {
        return __('filament.print_agent.title');
    }

    public function getSubheading(): ?string
    {
        return __('filament.print_agent.subtitle');
    }

    public static function getNavigationGroup(): ?string
    {
        return __('filament.nav.equb');
    }

    public static function shouldRegisterNavigation(): bool
    {
        return static::canAccess();
    }

    public static function canAccess(): bool
    {
        return Auth::check() && (
            Auth::user()->hasRole('Super Admin')
            || Auth::user()->can('admin.pages.print-agent')
        );
    }

    public function mount(): void
    {
        // Operator name plus randomness: when two branches both run an agent,
        // a claim can be traced back to a desk.
        $this->agentId = Str::limit(Auth::user()?->name ?? 'agent', 20, '').'-'.Str::random(6);
    }

    public function toggleListening(): void
    {
        $this->listening = ! $this->listening;
        $this->lastError = null;

        if ($this->listening) {
            // Don't make the operator wait a full poll cycle to see it work.
            $this->tick();

            return;
        }

        $this->releaseActiveJob();
    }

    /**
     * Polled by the browser while listening. Claims the oldest waiting job and
     * tells the front end to print it.
     *
     * Driven by wire:poll rather than a JavaScript timer: Livewire pauses
     * polling when the tab is hidden and resumes on focus, which is exactly
     * the behaviour wanted from a tab left open all day.
     */
    public function tick(): void
    {
        if (! $this->listening || $this->activeJobId !== null) {
            return;
        }

        $job = ReportPrintJob::query()
            ->queued()
            ->forAgent()
            ->oldest()
            ->first();

        if (! $job) {
            return;
        }

        if (! $job->claim($this->agentId)) {
            // Another tab won the row; try again next poll.
            return;
        }

        if (! $job->fileExists()) {
            $job->markFailed('Rendered file is missing from storage.');
            $this->lastError = __('filament.print_agent.file_missing', ['title' => $job->title]);

            return;
        }

        $this->activeJobId = $job->id;
        $this->activeJobTitle = $job->title;

        // A browser event rather than a watched property: the front end needs
        // to act once, when a job arrives, not on every re-render.
        $this->dispatch(
            'print-job-ready',
            url: route('admin.print-jobs.content', ['job' => $job->id]),
            title: $job->title,
            copies: max(1, (int) $job->copies),
        );
    }

    /** Called by the browser once the print dialog has been dismissed. */
    public function confirmPrinted(): void
    {
        if ($this->activeJobId === null) {
            return;
        }

        ReportPrintJob::find($this->activeJobId)?->markPrinted();

        $this->printedThisSession++;
        $this->lastPrintedAt = now()->format('g:i:s A');
        $this->lastError = null;
        $this->clearActive();

        // Chain straight into the next job so a backlog drains in one go
        // instead of one report per poll interval.
        $this->tick();
    }

    public function reportFailure(string $reason = 'The browser could not print the document.'): void
    {
        if ($this->activeJobId === null) {
            return;
        }

        ReportPrintJob::find($this->activeJobId)?->markFailed($reason);

        $this->lastError = $reason;
        $this->clearActive();
    }

    /** Puts an in-flight job back so another agent can take it. */
    public function releaseActiveJob(): void
    {
        if ($this->activeJobId === null) {
            return;
        }

        ReportPrintJob::find($this->activeJobId)?->requeue();

        $this->clearActive();
    }

    protected function clearActive(): void
    {
        $this->activeJobId = null;
        $this->activeJobTitle = null;
    }

    /**
     * Jobs abandoned by a tab that was closed mid-print.
     *
     * Run from the UI rather than automatically on every poll: reclaiming is a
     * write, and doing one every ten seconds for the life of an open tab is a
     * lot of pointless traffic. The scheduler also does this each minute.
     */
    public function releaseStuckJobs(): void
    {
        $released = app(ReportPrintService::class)->releaseStaleClaims();

        Notification::make()
            ->title(__('filament.print_agent.released', ['count' => $released]))
            ->status($released > 0 ? 'success' : 'info')
            ->send();
    }

    public function queuedCount(): int
    {
        return ReportPrintJob::query()->queued()->forAgent()->count();
    }

    /** @return Collection<int, ReportPrintJob> */
    public function waitingJobs(): Collection
    {
        return ReportPrintJob::query()
            ->forAgent()
            ->whereIn('status', [ReportPrintJob::STATUS_QUEUED, ReportPrintJob::STATUS_PRINTING])
            ->oldest()
            ->limit(10)
            ->get();
    }

    /** @return Collection<int, ReportPrintJob> */
    public function recentlyPrinted(): Collection
    {
        return ReportPrintJob::query()
            ->forAgent()
            ->whereIn('status', [ReportPrintJob::STATUS_PRINTED, ReportPrintJob::STATUS_FAILED])
            ->latest('updated_at')
            ->limit(5)
            ->get();
    }

    public function schedulerIsRunning(): bool
    {
        return app(ReportPrintService::class)->schedulerIsRunning();
    }

    public function schedulerLastSeen(): ?string
    {
        return app(ReportPrintService::class)->schedulerHeartbeat()?->diffForHumans();
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('toggle')
                ->label(fn (): string => $this->listening
                    ? __('filament.print_agent.stop')
                    : __('filament.print_agent.start'))
                ->icon(fn (): string => $this->listening ? 'heroicon-o-pause-circle' : 'heroicon-o-play-circle')
                ->color(fn (): string => $this->listening ? 'danger' : 'success')
                ->action(fn () => $this->toggleListening()),

            Action::make('testPage')
                ->label(__('filament.print_agent.test_print'))
                ->icon('heroicon-o-document-text')
                ->color('gray')
                ->action(function (): void {
                    // Queues today's report so the whole chain can be proved
                    // before it is trusted to run at 08:00 unattended.
                    app(ReportPrintService::class)->queue(
                        ['period' => 'daily', 'from' => now()->toDateString()],
                        [
                            'source' => 'manual',
                            'delivery' => 'agent',
                            'format' => 'html',
                            'paper' => 'a4',
                            'title' => __('filament.print_agent.test_job_title'),
                            'created_by' => Auth::id(),
                            'generated_by' => Auth::user()?->name,
                        ],
                    );

                    Notification::make()
                        ->title(__('filament.print_agent.test_queued'))
                        ->body($this->listening
                            ? __('filament.print_agent.test_queued_body')
                            : __('filament.print_agent.test_queued_idle'))
                        ->success()
                        ->send();

                    if ($this->listening) {
                        $this->tick();
                    }
                }),

            Action::make('releaseStuck')
                ->label(__('filament.print_agent.release_stuck'))
                ->icon('heroicon-o-arrow-path')
                ->color('gray')
                ->action(fn () => $this->releaseStuckJobs()),
        ];
    }
}
