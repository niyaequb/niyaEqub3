<?php

namespace App\Services;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;

/**
 * Sends a rendered report to a printer.
 *
 * Four connection types, because "the printer" means four different things
 * depending on how it is attached:
 *
 *   SYSTEM  The printer is installed on the machine running this app — USB,
 *           Wi-Fi, Ethernet, it makes no difference, because the job goes
 *           through the operating system's print spooler and the vendor
 *           driver. This is the only route that works for a USB printer, and
 *           the only one that works for host-based printers (most entry-level
 *           LaserJets) which cannot interpret raw PDF or PCL themselves.
 *
 *   SHARE   A printer shared from another Windows PC on the LAN, addressed as
 *           \\PC-NAME\PrinterShare. The remote machine's driver does the
 *           rendering, so this also works for host-based printers.
 *
 *   RAW     TCP port 9100 (JetDirect). For printers with their own network
 *           card that speak PostScript, PCL or ESC/POS directly.
 *
 *   IPP     TCP port 631 (CUPS and modern network printers), for when 9100 is
 *           closed or the printer sits behind a print server with named queues.
 *
 * The important constraint, and the reason the connection type is an explicit
 * choice rather than something guessed: SYSTEM requires the app and the printer
 * to be on the same machine. That holds for a local install or an on-premise
 * server; it does not hold for a cloud host, which cannot see a printer in an
 * office no matter what is typed into a form. For that case the Print Agent
 * page is the answer, and this class is not involved.
 */
class PrinterService
{
    public const CONNECTION_SYSTEM = 'system';

    public const CONNECTION_SHARE = 'share';

    public const CONNECTION_RAW = 'raw';

    public const CONNECTION_IPP = 'ipp';

    /** Seconds to wait for a socket to open before giving up. */
    protected const CONNECT_TIMEOUT = 6;

    /** Seconds to wait on socket read/write once connected. */
    protected const STREAM_TIMEOUT = 20;

    /** Cache of the discovery call, which shells out and is not free. */
    protected ?Collection $discovered = null;

    /**
     * Why the last discovery found nothing.
     *
     * "Found 0 printers" is a useless thing to tell someone standing next to a
     * working printer, so the reason is kept and shown in the UI instead of
     * being swallowed into a log file nobody reads.
     */
    protected ?string $discoveryError = null;

    public function discoveryError(): ?string
    {
        return $this->discoveryError;
    }

    // =================================================================
    // Public API
    // =================================================================

    /**
     * Send a rendered document to a printer.
     *
     * @param  string  $payload  Raw bytes: PDF, PostScript, PCL or ESC/POS
     * @param  array<string, mixed>  $printer
     * @return array{ok: bool, message: string, detail?: string}
     */
    public function send(string $payload, array $printer, string $jobName = 'Equb Report', string $mime = 'application/pdf'): array
    {
        $connection = $this->normalizeConnection($printer['connection'] ?? null);
        $copies = max(1, min(10, (int) ($printer['copies'] ?? 1)));

        try {
            return match ($connection) {
                self::CONNECTION_SYSTEM => $this->sendToSystemPrinter($payload, $printer, $jobName, $mime, $copies),
                self::CONNECTION_SHARE => $this->sendToShare($payload, $printer, $copies),
                self::CONNECTION_IPP => $this->repeat($copies, fn () => $this->sendIpp($payload, $printer, $jobName, $mime)),
                default => $this->repeat($copies, fn () => $this->sendRaw($payload, $printer)),
            };
        } catch (\Throwable $e) {
            Log::error('[Printer] Send failed', [
                'connection' => $connection,
                'target' => $printer['name'] ?? $printer['host'] ?? null,
                'error' => $e->getMessage(),
            ]);

            return [
                'ok' => false,
                'message' => __('filament.equb_report.printer_failed'),
                'detail' => $e->getMessage(),
            ];
        }
    }

    /**
     * Check a printer is reachable without printing anything.
     *
     * @param  array<string, mixed>  $printer
     * @return array{ok: bool, message: string, detail?: string}
     */
    public function test(array $printer): array
    {
        $connection = $this->normalizeConnection($printer['connection'] ?? null);

        return match ($connection) {
            self::CONNECTION_SYSTEM => $this->testSystemPrinter($printer),
            self::CONNECTION_SHARE => $this->testShare($printer),
            self::CONNECTION_IPP => $this->testIpp($printer),
            default => $this->testRaw($printer),
        };
    }

    /**
     * Printers installed on the machine running this app.
     *
     * Presented as a dropdown rather than a text field, because a printer's
     * Windows display name has to match exactly — spaces, hyphens and all —
     * and asking someone to retype "HP LaserJet MFP M129-M134" by hand is
     * asking for a support ticket.
     *
     * @return Collection<int, array{name: string, is_default: bool, status: ?string, port: ?string}>
     */
    public function discover(bool $fresh = false): Collection
    {
        if (! $fresh && $this->discovered !== null) {
            return $this->discovered;
        }

        // Enumerating printers means spawning PowerShell or lpstat, which costs
        // the best part of a second. A form that re-renders on every keystroke
        // would pay that repeatedly, so the result is held briefly. The window
        // is short enough that a printer plugged in mid-session shows up on the
        // next modal, and the Refresh button forces it sooner.
        $cacheKey = 'printers.discovered';

        if ($fresh) {
            Cache::forget($cacheKey);
        }

        $result = Cache::remember($cacheKey, now()->addSeconds(60), function (): array {
            try {
                return $this->isWindows() ? $this->discoverWindows() : $this->discoverCups();
            } catch (\Throwable $e) {
                Log::warning('[Printer] Discovery failed', ['error' => $e->getMessage()]);

                return ['rows' => [], 'error' => $e->getMessage()];
            }
        });

        $this->discoveryError = $result['error'] ?? null;

        return $this->discovered = collect($result['rows'] ?? []);
    }

    /** @return array<string, string> Printer name => label, for a Select field. */
    public function discoverOptions(bool $fresh = false): array
    {
        return $this->discover($fresh)
            ->mapWithKeys(fn (array $p): array => [
                $p['name'] => $p['name'].($p['is_default'] ? ' — '.__('filament.equb_report.system_default') : ''),
            ])
            ->all();
    }

    /**
     * Whether local spooler printing is even possible here.
     *
     * False on a cloud host with no printing subsystem, which is exactly when
     * the option should not be offered.
     */
    public function systemPrintingAvailable(): bool
    {
        return $this->discover()->isNotEmpty();
    }

    /** @return array<string, string> */
    public static function connectionOptions(): array
    {
        return [
            self::CONNECTION_SYSTEM => __('filament.equb_report.connection_system'),
            self::CONNECTION_SHARE => __('filament.equb_report.connection_share'),
            self::CONNECTION_RAW => __('filament.equb_report.connection_raw'),
            self::CONNECTION_IPP => __('filament.equb_report.connection_ipp'),
        ];
    }

    /** Retained for the network protocol sub-choice. */
    public static function protocolOptions(): array
    {
        return [
            self::CONNECTION_RAW => __('filament.equb_report.protocol_raw'),
            self::CONNECTION_IPP => __('filament.equb_report.protocol_ipp'),
        ];
    }

    // =================================================================
    // System printer (local spooler)
    // =================================================================

    /**
     * @param  array<string, mixed>  $printer
     * @return array{ok: bool, message: string, detail?: string}
     */
    protected function sendToSystemPrinter(string $payload, array $printer, string $jobName, string $mime, int $copies): array
    {
        $name = trim((string) ($printer['name'] ?? ''));

        if ($name === '') {
            return ['ok' => false, 'message' => __('filament.equb_report.printer_no_name')];
        }

        $file = $this->spoolToTempFile($payload, $mime);

        try {
            return $this->isWindows()
                ? $this->spoolWindows($file, $name, $jobName, $copies)
                : $this->spoolCups($file, $name, $jobName, $copies, $printer);
        } finally {
            // The report contains member names and payment amounts, so the
            // scratch copy goes away whether the job succeeded or not.
            @unlink($file);
        }
    }

    /** @return array{ok: bool, message: string, detail?: string} */
    protected function spoolWindows(string $file, string $name, string $jobName, int $copies): array
    {
        $helper = $this->windowsHelper();

        // No dedicated helper: hand the file to whatever application Windows
        // has registered against the PrintTo verb for .pdf. Works on many
        // machines and needs nothing installed, so it is worth trying before
        // telling someone to go and download something.
        if ($helper === null) {
            $shell = $this->spoolViaShellVerb($file, $name, $copies);

            if ($shell['ok']) {
                return $shell;
            }

            return [
                'ok' => false,
                'message' => __('filament.equb_report.printer_no_helper'),
                'detail' => __('filament.equb_report.printer_no_helper_detail', [
                    'path' => storage_path('app'.DIRECTORY_SEPARATOR.'bin'),
                    'url' => config('printing.helper_download_url'),
                ]).' ('.($shell['detail'] ?? $shell['message']).')',
            ];
        }

        $basename = strtolower(basename($helper));

        for ($i = 0; $i < $copies; $i++) {
            // Argument arrays, never a command string: no shell is spawned, so
            // a printer name containing quotes or ampersands is data rather
            // than something that could be executed.
            $command = match (true) {
                str_contains($basename, 'sumatra') => [
                    $helper, '-print-to', $name, '-silent', '-exit-when-done', $file,
                ],
                str_contains($basename, 'pdftoprinter') => [
                    $helper, $file, $name,
                ],
                str_contains($basename, 'acrord32'), str_contains($basename, 'acrobat') => [
                    $helper, '/N', '/T', $file, $name,
                ],
                default => [$helper, $file, $name],
            };

            $result = Process::timeout((int) config('printing.spool_timeout', 90))->run($command);

            if ($result->failed()) {
                return [
                    'ok' => false,
                    'message' => __('filament.equb_report.printer_spool_failed', ['printer' => $name]),
                    'detail' => $this->describeExitCode($result->exitCode(), $helper)
                        .$this->tail($result->errorOutput() ?: $result->output()),
                ];
            }
        }

        return [
            'ok' => true,
            'message' => __('filament.equb_report.printer_queued', ['printer' => $name, 'copies' => $copies]),
            'detail' => __('filament.equb_report.printer_via_helper', ['helper' => basename($helper)]),
        ];
    }

    /**
     * Print by invoking the shell's PrintTo verb.
     *
     * Windows routes this to whichever application is registered for PDFs.
     * Nothing to install, but nothing to rely on either — plenty of machines
     * have no PrintTo handler at all — so it is a fallback, never the plan.
     *
     * @return array{ok: bool, message: string, detail?: string}
     */
    protected function spoolViaShellVerb(string $file, string $name, int $copies): array
    {
        $script = <<<'PS'
            param($File, $Printer, $Copies)
            $ErrorActionPreference = 'Stop'
            for ($i = 0; $i -lt [int]$Copies; $i++) {
                Start-Process -FilePath $File -Verb PrintTo -ArgumentList "`"$Printer`"" -PassThru -WindowStyle Hidden | Out-Null
            }
            Start-Sleep -Seconds 3
            'ok'
            PS;

        // Arguments are injected as PowerShell variables rather than
        // interpolated into the script text, so a printer name cannot alter
        // what the script does.
        $full = '$File = '.$this->psLiteral($file).'; '
            .'$Printer = '.$this->psLiteral($name).'; '
            .'$Copies = '.(int) $copies.'; '
            .preg_replace('/^\s*param\([^)]*\)\s*/m', '', $script);

        $utf16 = @mb_convert_encoding($full, 'UTF-16LE', 'UTF-8');

        if ($utf16 === false) {
            return ['ok' => false, 'message' => 'Could not encode print command'];
        }

        $result = Process::timeout((int) config('printing.spool_timeout', 90))->run([
            'powershell', '-NoProfile', '-NonInteractive', '-ExecutionPolicy', 'Bypass',
            '-EncodedCommand', base64_encode($utf16),
        ]);

        if ($result->failed() || ! str_contains($result->output(), 'ok')) {
            return [
                'ok' => false,
                'message' => __('filament.equb_report.printer_no_printto'),
                'detail' => $this->tail($result->errorOutput() ?: $result->output())
                    ?: 'no PrintTo handler registered for .pdf',
            ];
        }

        return [
            'ok' => true,
            'message' => __('filament.equb_report.printer_queued', ['printer' => $name, 'copies' => $copies]),
            'detail' => __('filament.equb_report.printer_via_shell'),
        ];
    }

    /** Quote a value as a PowerShell single-quoted string literal. */
    protected function psLiteral(string $value): string
    {
        return "'".str_replace("'", "''", $value)."'";
    }

    /**
     * Turn a Windows exit code into something an admin can act on.
     *
     * A bare "exit code -1073740791" tells nobody anything; that value is
     * 0xC0000409, meaning the helper crashed rather than refused, which points
     * at the helper and not at the printer.
     */
    protected function describeExitCode(?int $code, string $helper): string
    {
        if ($code === null || $code === 0) {
            return '';
        }

        $hex = sprintf('0x%08X', $code & 0xFFFFFFFF);
        $name = basename($helper);

        // The 0xC00000xx family is a Windows exception, i.e. a crash.
        if (($code & 0xFFFFFFFF) >= 0xC0000000) {
            return __('filament.equb_report.printer_helper_crashed', [
                'helper' => $name,
                'code' => $hex,
                'url' => config('printing.helper_download_url'),
            ]).' ';
        }

        return $name.' exited with '.$code.' ('.$hex.'). ';
    }

    /** Last couple of lines of command output, for an error body. */
    protected function tail(string $output, int $lines = 3): string
    {
        $output = $this->decodeOutput($output);

        $rows = array_values(array_filter(array_map('trim', preg_split('/\R/', $output) ?: [])));

        return implode(' ', array_slice($rows, -$lines));
    }

    /**
     * Make command output safe to show a human.
     *
     * PowerShell writes its streams as UTF-16LE on Windows. Passed through
     * unchanged it renders as a wall of replacement diamonds, which is worse
     * than no message at all — it looks like the app is broken rather than the
     * printer.
     */
    protected function decodeOutput(string $output): string
    {
        if ($output === '') {
            return '';
        }

        // Interleaved null bytes are the giveaway for UTF-16 text.
        if (str_contains($output, "\0")) {
            $converted = @mb_convert_encoding($output, 'UTF-8', 'UTF-16LE');

            if ($converted !== false) {
                $output = $converted;
            }
        }

        if (! mb_check_encoding($output, 'UTF-8')) {
            $output = mb_convert_encoding($output, 'UTF-8', 'Windows-1252');
        }

        return trim($output);
    }

    /**
     * Connection types that can actually work on this machine right now.
     *
     * An option that is guaranteed to fail is worse than a missing one: it
     * costs someone a failed print job to discover what a filtered list could
     * have told them for free.
     *
     * @return array<string, string>
     */
    public function availableConnectionOptions(): array
    {
        $options = self::connectionOptions();

        if ($this->isWindows()) {
            // Local spooling on Windows needs a PDF helper that is not part of
            // the operating system. Without one, hide the option.
            if ($this->windowsHelper() === null) {
                unset($options[self::CONNECTION_SYSTEM]);
            }
        } else {
            // \\PC\Share paths are a Windows concept.
            unset($options[self::CONNECTION_SHARE]);

            if (! $this->systemPrintingAvailable()) {
                unset($options[self::CONNECTION_SYSTEM]);
            }
        }

        return $options;
    }

    /**
     * @param  array<string, mixed>  $printer
     * @return array{ok: bool, message: string, detail?: string}
     */
    protected function spoolCups(string $file, string $name, string $jobName, int $copies, array $printer): array
    {
        $command = ['lp', '-d', $name, '-n', (string) $copies, '-t', mb_substr($jobName, 0, 60)];

        if ($media = $this->cupsMedia($printer['paper'] ?? null)) {
            $command[] = '-o';
            $command[] = 'media='.$media;
        }

        // `--` terminates option parsing so a filename cannot be read as a flag.
        $command[] = '--';
        $command[] = $file;

        $result = Process::timeout((int) config('printing.spool_timeout', 90))->run($command);

        if ($result->failed()) {
            return [
                'ok' => false,
                'message' => __('filament.equb_report.printer_spool_failed', ['printer' => $name]),
                'detail' => trim($result->errorOutput() ?: $result->output()) ?: 'Exit code '.$result->exitCode(),
            ];
        }

        return [
            'ok' => true,
            'message' => __('filament.equb_report.printer_queued', ['printer' => $name, 'copies' => $copies]),
            'detail' => trim($result->output()) ?: null,
        ];
    }

    /**
     * @param  array<string, mixed>  $printer
     * @return array{ok: bool, message: string, detail?: string}
     */
    protected function testSystemPrinter(array $printer): array
    {
        $name = trim((string) ($printer['name'] ?? ''));

        if ($name === '') {
            return ['ok' => false, 'message' => __('filament.equb_report.printer_no_name')];
        }

        $printers = $this->discover(fresh: true);

        if ($printers->isEmpty()) {
            return [
                'ok' => false,
                'message' => __('filament.equb_report.printer_none_installed'),
                'detail' => $this->discoveryError(),
            ];
        }

        $match = $printers->firstWhere('name', $name);

        if (! $match) {
            return [
                'ok' => false,
                'message' => __('filament.equb_report.printer_not_installed', ['printer' => $name]),
                'detail' => __('filament.equb_report.printer_available_list', [
                    'list' => $printers->pluck('name')->implode(', '),
                ]),
            ];
        }

        if ($this->isWindows() && $this->windowsHelper() === null) {
            // Not fatal any more — the PrintTo fallback may still work — but
            // worth flagging, because that fallback is the unreliable path.
            return [
                'ok' => true,
                'message' => __('filament.equb_report.printer_ready_no_helper', ['printer' => $name]),
                'detail' => __('filament.equb_report.printer_no_helper_detail', [
                    'path' => storage_path('app'.DIRECTORY_SEPARATOR.'bin'),
                    'url' => config('printing.helper_download_url'),
                ]),
            ];
        }

        // A printer that is installed but switched off still counts as a
        // failure worth surfacing — the job would sit in the queue silently.
        if (($match['status'] ?? null) === 'offline') {
            return [
                'ok' => false,
                'message' => __('filament.equb_report.printer_offline', ['printer' => $name]),
                'detail' => __('filament.equb_report.printer_offline_detail'),
            ];
        }

        return [
            'ok' => true,
            'message' => __('filament.equb_report.printer_ready', ['printer' => $name]),
            'detail' => trim(
                ($match['port'] ? __('filament.equb_report.printer_on_port', ['port' => $match['port']]).' ' : '')
                .__('filament.equb_report.printer_via_helper', ['helper' => $this->helperName()])
            ),
        ];
    }

    // =================================================================
    // Windows share
    // =================================================================

    /**
     * @param  array<string, mixed>  $printer
     * @return array{ok: bool, message: string, detail?: string}
     */
    protected function sendToShare(string $payload, array $printer, int $copies): array
    {
        $path = $this->normalizeUnc($printer['name'] ?? $printer['host'] ?? '');

        if ($path === null) {
            return [
                'ok' => false,
                'message' => __('filament.equb_report.printer_bad_share'),
                'detail' => __('filament.equb_report.printer_bad_share_detail'),
            ];
        }

        for ($i = 0; $i < $copies; $i++) {
            // Copying bytes at a share bypasses the local driver, so the
            // remote printer has to understand the payload itself. Works for
            // PostScript, PCL and ESC/POS; a host-based printer will eject
            // blank paper instead, which is why the form steers those to the
            // system connection.
            $handle = @fopen($path, 'wb');

            if ($handle === false) {
                $error = error_get_last()['message'] ?? 'unknown error';

                return [
                    'ok' => false,
                    'message' => __('filament.equb_report.printer_share_unreachable', ['path' => $path]),
                    'detail' => $error,
                ];
            }

            $written = @fwrite($handle, $payload);
            @fclose($handle);

            if ($written === false || $written < strlen($payload)) {
                return [
                    'ok' => false,
                    'message' => __('filament.equb_report.printer_write_failed'),
                    'detail' => 'Wrote '.(int) $written.' of '.strlen($payload).' bytes.',
                ];
            }
        }

        return [
            'ok' => true,
            'message' => __('filament.equb_report.printer_queued', ['printer' => $path, 'copies' => $copies]),
        ];
    }

    /**
     * @param  array<string, mixed>  $printer
     * @return array{ok: bool, message: string, detail?: string}
     */
    protected function testShare(array $printer): array
    {
        $path = $this->normalizeUnc($printer['name'] ?? $printer['host'] ?? '');

        if ($path === null) {
            return [
                'ok' => false,
                'message' => __('filament.equb_report.printer_bad_share'),
                'detail' => __('filament.equb_report.printer_bad_share_detail'),
            ];
        }

        // Resolve just the host portion. A full open would create a real print
        // job, and a connection test must not put paper through the machine.
        $host = explode('\\', ltrim($path, '\\'))[0] ?? '';

        if ($host !== '' && ! filter_var($host, FILTER_VALIDATE_IP) && gethostbyname($host) === $host) {
            return [
                'ok' => false,
                'message' => __('filament.equb_report.printer_host_unresolved', ['host' => $host]),
                'detail' => __('filament.equb_report.printer_host_unresolved_detail'),
            ];
        }

        return [
            'ok' => true,
            'message' => __('filament.equb_report.printer_share_resolved', ['path' => $path]),
            'detail' => __('filament.equb_report.printer_share_test_caveat'),
        ];
    }

    // =================================================================
    // RAW / JetDirect
    // =================================================================

    /**
     * @param  array<string, mixed>  $printer
     * @return array{ok: bool, message: string, detail?: string}
     */
    protected function sendRaw(string $payload, array $printer): array
    {
        $host = trim((string) ($printer['host'] ?? ''));
        $port = $this->resolvePort($printer, self::CONNECTION_RAW);

        if ($guard = $this->guardNetworkHost($host)) {
            return $guard;
        }

        $socket = @fsockopen($host, $port, $errno, $errstr, self::CONNECT_TIMEOUT);

        if (! $socket) {
            return [
                'ok' => false,
                'message' => __('filament.equb_report.printer_unreachable', ['host' => "{$host}:{$port}"]),
                'detail' => $errstr ?: "errno {$errno}",
            ];
        }

        stream_set_timeout($socket, self::STREAM_TIMEOUT);

        $total = strlen($payload);
        $written = 0;

        // fwrite can short-write against a slow printer buffer, so loop until
        // the whole job is out rather than assuming one call sends everything.
        while ($written < $total) {
            $chunk = @fwrite($socket, substr($payload, $written));

            if ($chunk === false || $chunk === 0) {
                $info = stream_get_meta_data($socket);
                fclose($socket);

                return [
                    'ok' => false,
                    'message' => __('filament.equb_report.printer_write_failed'),
                    'detail' => ($info['timed_out'] ?? false)
                        ? 'Stream timed out after '.self::STREAM_TIMEOUT.'s'
                        : "Wrote {$written} of {$total} bytes",
                ];
            }

            $written += $chunk;
        }

        fflush($socket);
        fclose($socket);

        return ['ok' => true, 'message' => __('filament.equb_report.printer_sent', ['copies' => 1])];
    }

    /**
     * @param  array<string, mixed>  $printer
     * @return array{ok: bool, message: string, detail?: string}
     */
    protected function testRaw(array $printer): array
    {
        $host = trim((string) ($printer['host'] ?? ''));
        $port = $this->resolvePort($printer, self::CONNECTION_RAW);

        if ($guard = $this->guardNetworkHost($host)) {
            return $guard;
        }

        $started = microtime(true);
        $socket = @fsockopen($host, $port, $errno, $errstr, self::CONNECT_TIMEOUT);

        if (! $socket) {
            return [
                'ok' => false,
                'message' => __('filament.equb_report.printer_unreachable', ['host' => "{$host}:{$port}"]),
                'detail' => $errstr ?: "errno {$errno}",
            ];
        }

        fclose($socket);

        return [
            'ok' => true,
            'message' => __('filament.equb_report.printer_reachable', [
                'host' => "{$host}:{$port}",
                'ms' => (int) round((microtime(true) - $started) * 1000),
            ]),
        ];
    }

    // =================================================================
    // IPP
    // =================================================================

    /**
     * IPP/1.1 Print-Job (operation 0x0002).
     *
     * @param  array<string, mixed>  $printer
     * @return array{ok: bool, message: string, detail?: string}
     */
    protected function sendIpp(string $payload, array $printer, string $jobName, string $mime): array
    {
        if ($guard = $this->guardNetworkHost(trim((string) ($printer['host'] ?? '')))) {
            return $guard;
        }

        $body = $this->ippHeader(0x0002)
            .$this->ippOperationAttributes($this->ippUri($printer), $jobName, $printer)
            .$this->ippAttribute(0x49, 'document-format', $mime)
            .chr(0x03) // end-of-attributes
            .$payload;

        return $this->postIpp($printer, $body, __('filament.equb_report.printer_sent', ['copies' => 1]));
    }

    /**
     * @param  array<string, mixed>  $printer
     * @return array{ok: bool, message: string, detail?: string}
     */
    protected function testIpp(array $printer): array
    {
        if ($guard = $this->guardNetworkHost(trim((string) ($printer['host'] ?? '')))) {
            return $guard;
        }

        // Get-Printer-Attributes (0x000B) asks the printer to describe itself
        // and prints nothing, which is what a test button should do.
        $body = $this->ippHeader(0x000B)
            .$this->ippOperationAttributes($this->ippUri($printer), 'Connection test', $printer)
            .chr(0x03);

        return $this->postIpp($printer, $body, __('filament.equb_report.printer_reachable', [
            'host' => $this->ippUri($printer),
            'ms' => 0,
        ]));
    }

    /**
     * @param  array<string, mixed>  $printer
     * @return array{ok: bool, message: string, detail?: string}
     */
    protected function postIpp(array $printer, string $body, string $successMessage): array
    {
        $host = (string) $printer['host'];
        $port = $this->resolvePort($printer, self::CONNECTION_IPP);
        $queue = trim((string) ($printer['queue'] ?? ''), '/');
        $url = "http://{$host}:{$port}/".($queue !== '' ? "printers/{$queue}" : '');

        $response = Http::withHeaders(['Content-Type' => 'application/ipp'])
            ->withOptions(['connect_timeout' => self::CONNECT_TIMEOUT])
            ->timeout(self::STREAM_TIMEOUT)
            ->withBody($body, 'application/ipp')
            ->post($url);

        if ($response->failed()) {
            return [
                'ok' => false,
                'message' => __('filament.equb_report.printer_failed'),
                'detail' => "HTTP {$response->status()} from {$url}",
            ];
        }

        $status = $this->ippStatusCode($response->body());

        // Codes below 0x0100 are the "successful-ok" family; above that is a
        // genuine rejection — bad queue, no media, and so on.
        if ($status !== null && $status >= 0x0100) {
            return [
                'ok' => false,
                'message' => __('filament.equb_report.printer_rejected'),
                'detail' => 'IPP status 0x'.str_pad(dechex($status), 4, '0', STR_PAD_LEFT),
            ];
        }

        return ['ok' => true, 'message' => $successMessage];
    }

    protected function ippHeader(int $operationId): string
    {
        return chr(0x01).chr(0x01)                // IPP version 1.1
            .pack('n', $operationId)
            .pack('N', random_int(1, 0x7FFFFFFF)) // request-id
            .chr(0x01);                           // operation-attributes-tag
    }

    /** @param  array<string, mixed>  $printer */
    protected function ippOperationAttributes(string $uri, string $jobName, array $printer): string
    {
        // Order is load-bearing in IPP: charset and natural-language first,
        // then printer-uri. Printers reject requests that arrive out of order.
        return $this->ippAttribute(0x47, 'attributes-charset', 'utf-8')
            .$this->ippAttribute(0x48, 'attributes-natural-language', 'en')
            .$this->ippAttribute(0x45, 'printer-uri', $uri)
            .$this->ippAttribute(0x42, 'requesting-user-name', (string) ($printer['user'] ?? 'niya-ekub'))
            .$this->ippAttribute(0x42, 'job-name', mb_substr($jobName, 0, 60));
    }

    protected function ippAttribute(int $tag, string $name, string $value): string
    {
        return chr($tag)
            .pack('n', strlen($name)).$name
            .pack('n', strlen($value)).$value;
    }

    protected function ippStatusCode(string $response): ?int
    {
        if (strlen($response) < 4) {
            return null;
        }

        return unpack('nversion/nstatus', substr($response, 0, 4))['status'] ?? null;
    }

    /** @param  array<string, mixed>  $printer */
    protected function ippUri(array $printer): string
    {
        $host = (string) $printer['host'];
        $port = $this->resolvePort($printer, self::CONNECTION_IPP);
        $queue = trim((string) ($printer['queue'] ?? ''), '/');

        return "ipp://{$host}:{$port}/".($queue !== '' ? "printers/{$queue}" : '');
    }

    // =================================================================
    // Discovery
    // =================================================================

    /**
     * Enumerate printers installed on Windows.
     *
     * Three strategies, tried in order, because no single one is reliable
     * everywhere: WMI can be disabled or broken, the PrintManagement module
     * is not on every SKU, and the registry is always there.
     *
     * @return array{rows: array<int, array<string, mixed>>, error: ?string}
     */
    protected function discoverWindows(): array
    {
        $errors = [];

        foreach (['powershellCim', 'powershellGetPrinter', 'registry'] as $strategy) {
            $result = $this->{$strategy}();

            if ($result['rows'] !== []) {
                return ['rows' => $result['rows'], 'error' => null];
            }

            if ($result['error']) {
                $errors[] = $strategy.': '.$result['error'];
            }
        }

        Log::warning('[Printer] Windows discovery found nothing', ['attempts' => $errors]);

        return [
            'rows' => [],
            'error' => $errors === []
                ? 'Windows reported no installed printers.'
                : implode(' | ', $errors),
        ];
    }

    /** WMI via CIM — the richest source, gives port and offline state. */
    protected function powershellCim(): array
    {
        // Built without pipelines in the JSON step and shipped as an encoded
        // command, so nothing in it can be misread on the way to PowerShell.
        $script = <<<'PS'
            $ErrorActionPreference = 'SilentlyContinue'
            $printers = @(Get-CimInstance -ClassName Win32_Printer)
            if ($printers.Count -eq 0) { $printers = @(Get-WmiObject -Class Win32_Printer) }
            $out = New-Object System.Collections.ArrayList
            foreach ($p in $printers) {
                [void]$out.Add([pscustomobject]@{
                    Name = [string]$p.Name
                    Default = [bool]$p.Default
                    PortName = [string]$p.PortName
                    WorkOffline = [bool]$p.WorkOffline
                })
            }
            ConvertTo-Json -InputObject @($out) -Compress -Depth 3
            PS;

        return $this->runPowerShellJson($script);
    }

    /** The PrintManagement module — present on Windows 8 and later. */
    protected function powershellGetPrinter(): array
    {
        $script = <<<'PS'
            $ErrorActionPreference = 'SilentlyContinue'
            Import-Module PrintManagement
            $printers = @(Get-Printer)
            $out = New-Object System.Collections.ArrayList
            foreach ($p in $printers) {
                [void]$out.Add([pscustomobject]@{
                    Name = [string]$p.Name
                    Default = $false
                    PortName = [string]$p.PortName
                    WorkOffline = ($p.PrinterStatus -eq 'Offline')
                })
            }
            ConvertTo-Json -InputObject @($out) -Compress -Depth 3
            PS;

        return $this->runPowerShellJson($script);
    }

    /**
     * Read the printer list straight out of the registry.
     *
     * The last resort, and the one that cannot really fail: every installed
     * printer has an entry here regardless of whether WMI, the spooler service
     * or PowerShell modules are cooperating.
     */
    protected function registry(): array
    {
        $result = Process::timeout((int) config('printing.discovery_timeout', 20))->run([
            'reg', 'query',
            'HKCU\Software\Microsoft\Windows NT\CurrentVersion\Devices',
        ]);

        if ($result->failed()) {
            return ['rows' => [], 'error' => trim($result->errorOutput()) ?: 'reg query failed'];
        }

        // Find the default so the dropdown can mark it.
        $default = null;
        $defaultResult = Process::timeout(10)->run([
            'reg', 'query',
            'HKCU\Software\Microsoft\Windows NT\CurrentVersion\Windows',
            '/v', 'Device',
        ]);

        if ($defaultResult->successful()
            && preg_match('/Device\s+REG_SZ\s+([^,\r\n]+)/i', $defaultResult->output(), $m)) {
            $default = trim($m[1]);
        }

        $rows = [];

        foreach (preg_split('/\R/', $result->output()) ?: [] as $line) {
            // "    HP LaserJet MFP M129-M134    REG_SZ    winspool,USB002"
            if (! preg_match('/^\s{4,}(.+?)\s+REG_SZ\s+(.*)$/', $line, $m)) {
                continue;
            }

            $name = trim($m[1]);

            if ($name === '') {
                continue;
            }

            $port = null;
            $parts = explode(',', trim($m[2]));

            if (count($parts) > 1) {
                $port = trim($parts[1]);
            }

            $rows[] = [
                'name' => $name,
                'is_default' => $default !== null && $name === $default,
                // The registry says nothing about online state, so claim
                // nothing rather than guessing "ready".
                'status' => null,
                'port' => $port,
            ];
        }

        usort($rows, fn ($a, $b) => ($b['is_default'] <=> $a['is_default']) ?: strcmp($a['name'], $b['name']));

        return ['rows' => $rows, 'error' => $rows === [] ? 'No printers in registry' : null];
    }

    /**
     * Run a PowerShell script and decode its JSON output.
     *
     * -EncodedCommand takes a base64 UTF-16LE script, which sidesteps Windows
     * command-line quoting entirely. Passing the script as a plain argument is
     * what broke the first version of this: the pipes inside it were consumed
     * by cmd.exe before PowerShell ever saw them.
     *
     * @return array{rows: array<int, array<string, mixed>>, error: ?string}
     */
    protected function runPowerShellJson(string $script): array
    {
        $utf16 = @mb_convert_encoding($script, 'UTF-16LE', 'UTF-8');

        if ($utf16 === false) {
            return ['rows' => [], 'error' => 'Could not encode PowerShell script'];
        }

        $result = Process::timeout((int) config('printing.discovery_timeout', 20))->run([
            'powershell',
            '-NoProfile',
            '-NonInteractive',
            '-ExecutionPolicy', 'Bypass',
            '-EncodedCommand', base64_encode($utf16),
        ]);

        if ($result->failed()) {
            return [
                'rows' => [],
                'error' => $this->tail($result->errorOutput()) ?: 'exit code '.$result->exitCode(),
            ];
        }

        $output = trim($this->decodeOutput($result->output()));

        if ($output === '' || $output === 'null') {
            return ['rows' => [], 'error' => 'no output'];
        }

        $decoded = json_decode($output, true);

        if (! is_array($decoded)) {
            return ['rows' => [], 'error' => 'unparseable output: '.mb_substr($output, 0, 120)];
        }

        // A single printer can still decode to a bare object on older
        // PowerShell versions despite the @() wrapper.
        if (array_key_exists('Name', $decoded)) {
            $decoded = [$decoded];
        }

        $rows = collect($decoded)
            ->filter(fn ($row) => is_array($row) && filled($row['Name'] ?? null))
            ->map(fn (array $row): array => [
                'name' => (string) $row['Name'],
                'is_default' => (bool) ($row['Default'] ?? false),
                'status' => ($row['WorkOffline'] ?? false) ? 'offline' : 'ready',
                'port' => $row['PortName'] ?? null,
            ])
            ->sortByDesc('is_default')
            ->values()
            ->all();

        return ['rows' => $rows, 'error' => $rows === [] ? 'no printers returned' : null];
    }

    /** @return array{rows: array<int, array<string, mixed>>, error: ?string} */
    protected function discoverCups(): array
    {
        $list = Process::timeout((int) config('printing.discovery_timeout', 20))->run(['lpstat', '-p']);

        if ($list->failed()) {
            return [
                'rows' => [],
                'error' => trim($list->errorOutput())
                    ?: 'lpstat is unavailable — is CUPS installed and running?',
            ];
        }

        $default = Process::timeout((int) config('printing.discovery_timeout', 20))->run(['lpstat', '-d']);
        $defaultName = null;

        if ($default->successful() && preg_match('/:\s*(\S+)/', $default->output(), $m)) {
            $defaultName = $m[1];
        }

        $printers = [];

        foreach (preg_split('/\R/', $list->output()) ?: [] as $line) {
            // "printer HP_LaserJet is idle.  enabled since ..."
            if (! preg_match('/^printer\s+(\S+)\s+is\s+(\S+?)\.?\s*$|^printer\s+(\S+)\s+is\s+(\S+)/', trim($line), $m)) {
                continue;
            }

            $name = $m[1] ?: ($m[3] ?? '');
            $state = strtolower($m[2] ?: ($m[4] ?? ''));

            if ($name === '') {
                continue;
            }

            $printers[] = [
                'name' => $name,
                'is_default' => $name === $defaultName,
                'status' => str_contains($state, 'disabled') ? 'offline' : 'ready',
                'port' => null,
            ];
        }

        usort($printers, fn ($a, $b) => ($b['is_default'] <=> $a['is_default']) ?: strcmp($a['name'], $b['name']));

        return [
            'rows' => $printers,
            'error' => $printers === [] ? 'lpstat returned no destinations' : null,
        ];
    }

    /**
     * Absolute path to a usable Windows PDF print helper, or null.
     *
     * Candidates may be glob patterns, because SumatraPDF ships as
     * SumatraPDF-3.5.2-64.exe and expecting someone to rename it before the
     * feature works is a trap.
     */
    protected function windowsHelper(): ?string
    {
        // An explicit path is taken at face value, Adobe included — if someone
        // has configured it deliberately, that is their call.
        if ($configured = config('printing.windows_helper')) {
            return is_file($configured) ? $configured : null;
        }

        $candidates = (array) config('printing.windows_helper_candidates', []);

        // Per-user installs live under LOCALAPPDATA, which cannot be written as
        // a literal in a config file.
        if ($local = getenv('LOCALAPPDATA')) {
            $candidates[] = $local.'\SumatraPDF\SumatraPDF*.exe';
            $candidates[] = $local.'\Programs\SumatraPDF\SumatraPDF*.exe';
        }

        foreach ($candidates as $candidate) {
            $path = str_starts_with($candidate, 'storage/')
                ? base_path($candidate)
                : $candidate;

            if (! str_contains($path, '*') && is_file($path)) {
                return $path;
            }

            foreach (glob($path) ?: [] as $match) {
                if (is_file($match)) {
                    return $match;
                }
            }
        }

        return null;
    }

    /**
     * Filename of the print helper in use, or null when there is none.
     * Surfaced in the form so an admin can see the state before pressing
     * Submit rather than discovering it from a failed job.
     */
    public function helperName(): ?string
    {
        if (! $this->isWindows()) {
            return 'lp (CUPS)';
        }

        $helper = $this->windowsHelper();

        return $helper ? basename($helper) : null;
    }

    // =================================================================
    // Helpers
    // =================================================================

    /**
     * Catch the most common mistake this form invites: pasting a printer's
     * Windows display name into a field that wants a network address. The
     * bare DNS failure ("No such host is known") gives no hint about what to
     * do instead, so say it plainly.
     *
     * @return array{ok: bool, message: string, detail?: string}|null
     */
    protected function guardNetworkHost(string $host): ?array
    {
        if ($host === '') {
            return ['ok' => false, 'message' => __('filament.equb_report.printer_no_host')];
        }

        if (filter_var($host, FILTER_VALIDATE_IP)) {
            return null;
        }

        // Spaces are illegal in hostnames, so this is a display name.
        if (preg_match('/\s/', $host)) {
            return [
                'ok' => false,
                'message' => __('filament.equb_report.printer_looks_like_name', ['name' => $host]),
                'detail' => __('filament.equb_report.printer_looks_like_name_detail'),
            ];
        }

        if (gethostbyname($host) === $host) {
            return [
                'ok' => false,
                'message' => __('filament.equb_report.printer_host_unresolved', ['host' => $host]),
                'detail' => __('filament.equb_report.printer_host_unresolved_detail'),
            ];
        }

        return null;
    }

    /** Accepts "\\PC\Share", "//PC/Share" or "PC\Share"; returns UNC or null. */
    protected function normalizeUnc(string $value): ?string
    {
        $value = trim(str_replace('/', '\\', trim($value)));

        if ($value === '') {
            return null;
        }

        $value = '\\\\'.ltrim($value, '\\');
        $parts = array_values(array_filter(explode('\\', $value), 'strlen'));

        return count($parts) >= 2 ? '\\\\'.$parts[0].'\\'.$parts[1] : null;
    }

    /** Writes the payload somewhere the spooler can read it. */
    protected function spoolToTempFile(string $payload, string $mime): string
    {
        $extension = match (true) {
            str_contains($mime, 'pdf') => '.pdf',
            str_contains($mime, 'html') => '.html',
            default => '.prn',
        };

        $path = tempnam(sys_get_temp_dir(), 'equbrpt').$extension;
        file_put_contents($path, $payload);

        return $path;
    }

    protected function cupsMedia(?string $paper): ?string
    {
        return match ($paper) {
            'a4' => 'A4',
            'a5' => 'A5',
            // Thermal rolls are a custom size; leaving it unset lets the CUPS
            // queue's own default apply, which is what the driver was set up for.
            default => null,
        };
    }

    /**
     * @param  callable(): array{ok: bool, message: string, detail?: string}  $callback
     * @return array{ok: bool, message: string, detail?: string}
     */
    protected function repeat(int $copies, callable $callback): array
    {
        for ($i = 0; $i < $copies; $i++) {
            $result = $callback();

            if (! $result['ok']) {
                return $result;
            }
        }

        return ['ok' => true, 'message' => __('filament.equb_report.printer_sent', ['copies' => $copies])];
    }

    /** @param  array<string, mixed>  $printer */
    protected function resolvePort(array $printer, string $connection): int
    {
        $port = (int) ($printer['port'] ?? 0);

        if ($port > 0 && $port <= 65535) {
            return $port;
        }

        return $connection === self::CONNECTION_IPP ? 631 : 9100;
    }

    protected function normalizeConnection(?string $value): string
    {
        return in_array($value, [
            self::CONNECTION_SYSTEM,
            self::CONNECTION_SHARE,
            self::CONNECTION_RAW,
            self::CONNECTION_IPP,
        ], true) ? $value : self::CONNECTION_RAW;
    }

    protected function isWindows(): bool
    {
        return PHP_OS_FAMILY === 'Windows';
    }
}
