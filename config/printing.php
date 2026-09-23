<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Report branding
    |--------------------------------------------------------------------------
    |
    | What appears at the top of every printed report and PDF. Kept separate
    | from APP_NAME on purpose: the legal entity that signs a financial report
    | is not necessarily the name you want on the login screen, and changing
    | APP_NAME also changes queue names, mail "from" headers and session
    | cookie prefixes.
    |
    */

    'brand' => [
        'name' => env('REPORT_BRAND_NAME', 'Niya Financial Technology PLC'),
        'tagline' => env('REPORT_BRAND_TAGLINE'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Windows PDF print helper
    |--------------------------------------------------------------------------
    |
    | Windows has no built-in command that prints a PDF to a named printer
    | silently. The spooler needs an application to render the page through the
    | printer driver first — which matters a great deal for host-based printers
    | (most entry-level HP LaserJets, Canon LBPs and Brother DCPs), because they
    | have no PostScript or PCL interpreter of their own and simply ignore raw
    | bytes.
    |
    | Any one of these small utilities does the job. SumatraPDF is the usual
    | choice: portable, ~10MB, no installer required. Drop the .exe in
    | storage/app/bin/ and it is picked up automatically.
    |
    | Set PRINT_HELPER_PATH in .env to point somewhere else.
    |
    */

    'windows_helper' => env('PRINT_HELPER_PATH'),

    'windows_helper_candidates' => [
        // Glob patterns are supported, so a versioned filename like
        // SumatraPDF-3.5.2-64.exe is found without being renamed first.
        'storage/app/bin/SumatraPDF*.exe',
        'storage/app/bin/PDFtoPrinter*.exe',
        'C:\Program Files\SumatraPDF\SumatraPDF*.exe',
        'C:\Program Files (x86)\SumatraPDF\SumatraPDF*.exe',

        // Adobe Acrobat and Reader are deliberately absent. Their /t and /p
        // switches were never officially supported and current builds crash on
        // them (0xC0000409, usually after an "Out of memory" dialog) rather
        // than printing. Set PRINT_HELPER_PATH explicitly to use Adobe anyway.
    ],

    /*
    |--------------------------------------------------------------------------
    | Where to get a helper
    |--------------------------------------------------------------------------
    |
    | Shown verbatim in the error when nothing is installed, so it needs to
    | stay a real, current link.
    |
    */

    'helper_download_url' => 'https://www.sumatrapdfreader.org/download-free-pdf-viewer',

    /*
    |--------------------------------------------------------------------------
    | Command timeouts (seconds)
    |--------------------------------------------------------------------------
    |
    | Discovery is quick and runs while an admin waits on a form, so it gets a
    | short leash. Spooling a long report can genuinely take a while, so that
    | gets longer.
    |
    */

    'discovery_timeout' => env('PRINT_DISCOVERY_TIMEOUT', 15),

    'spool_timeout' => env('PRINT_SPOOL_TIMEOUT', 90),

    /*
    |--------------------------------------------------------------------------
    | The print agent
    |--------------------------------------------------------------------------
    |
    | Settings for the browser-based agent — the page left open on the machine
    | the printer is attached to. Every value here has a reason to be a value
    | rather than a constant, because the right number depends on the office:
    | one PC with a USB laser is not the same problem as four branches sharing
    | a server.
    |
    | An admin can override any of these from the Print Agent page; what is
    | here is the starting point.
    |
    */

    'agent' => [

        // How often an idle agent asks for work. Ten seconds is a compromise:
        // fast enough that a manual print feels immediate, slow enough that a
        // tab left open for a working day costs about 3,000 requests rather
        // than 30,000.
        'poll_seconds' => env('PRINT_AGENT_POLL', 10),

        // How often the agent says it is still there. Shorter than the poll
        // because liveness is what the rest of the office reads, and a station
        // that has been dead for two minutes should look dead.
        'heartbeat_seconds' => env('PRINT_AGENT_HEARTBEAT', 15),

        // Silence longer than this and a station is treated as gone. Its
        // in-flight job is released and the page stops claiming it is running.
        'offline_after' => env('PRINT_AGENT_OFFLINE_AFTER', 120),

        /*
        | Silent printing, and how it is detected.
        |
        | Chrome and Edge print without showing a dialog only when launched
        | with --kiosk-printing. Nothing in the DOM exposes that flag — there
        | is no API for it and there never has been — so it is measured from
        | behaviour instead: window.print() blocks for as long as the dialog is
        | open, and returns in a handful of milliseconds when there is none.
        |
        | 400ms is well above what a silent spool takes on a slow machine and
        | far below the fastest a human can find and click Print.
        */
        'silent_threshold_ms' => env('PRINT_AGENT_SILENT_THRESHOLD', 400),

        // How long to wait for a document to load into the print surface
        // before giving up on it. A report over a long date range can be a
        // few thousand rows.
        'load_timeout_seconds' => env('PRINT_AGENT_LOAD_TIMEOUT', 45),

        /*
        | Retries.
        |
        | Escalating rather than fixed. The first retry catches a tab that was
        | closed mid-print and wants to be quick; by the third the problem is
        | usually somebody needing to put paper in, which takes longer than a
        | minute. Give up after that rather than printing the same report
        | forty times once the printer comes back.
        */
        'max_attempts' => env('PRINT_AGENT_MAX_ATTEMPTS', 3),

        'retry_backoff' => [60, 300, 900],

        // A job claimed by a station that then went quiet. Shorter than the
        // old fifteen minutes: the heartbeat now tells us within two minutes
        // that the station is gone, so there is nothing to be gained by
        // holding its job for another thirteen.
        'stale_claim_minutes' => env('PRINT_AGENT_STALE_CLAIM', 5),

        // Consecutive failed runs before a schedule switches itself off. Set
        // to 0 to let a broken schedule keep trying forever.
        'pause_schedule_after' => env('PRINT_AGENT_PAUSE_AFTER', 7),

        // Finished jobs older than this are deleted, along with the rendered
        // documents — which hold member names, phone numbers and amounts, so
        // keeping every one forever is a liability rather than an archive.
        'prune_after_days' => env('PRINT_AGENT_PRUNE_DAYS', 60),
    ],

];
