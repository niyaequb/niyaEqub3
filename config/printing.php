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

];
