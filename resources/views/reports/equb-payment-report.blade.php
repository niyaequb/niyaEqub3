{{--
    Printable Equb payment report.

    One template serves three consumers: dompdf (PDF download and scheduled
    print), the browser print dialog, and the print agent's hidden iframe.
    That rules out Tailwind and external stylesheets — dompdf resolves neither
    — so everything here is plain inline CSS that all three understand.

    Laid out to finish on as few pages as possible: the transaction list is the
    point of the document, so the aggregate blocks above it stay compact and
    the period-bucketed trend table is gone entirely. Each row carries its own
    exact date and time, which is what the trend was approximating anyway.

    $report   array from EqubReportService::build()
    $paper    a4 | a5 | thermal80 | thermal58
    $fontFace optional @font-face block for Amharic glyphs
    $brand    array{name, tagline}
--}}
@php
    $paper = $paper ?? 'a4';
    $thermal = str_starts_with($paper, 'thermal');
    $meta = $report['meta'];
    $summary = $report['summary'];
    $growth = $report['growth'] ?? [];
    $currency = $meta['currency'] ?? 'ETB';

    $money = fn ($v) => number_format((float) $v, 2);
    $pct = function ($v) {
        if ($v === null) {
            return '—';
        }
        return ($v > 0 ? '+' : '') . number_format((float) $v, 1) . '%';
    };
    $width = match ($paper) {
        'thermal58' => '48mm',
        'thermal80' => '72mm',
        default => '100%',
    };
@endphp
<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}">
<head>
    <meta charset="utf-8">
    <title>{{ $brand['name'] ?? config('app.name') }} — {{ $meta['period_label'] }} {{ __('filament.equb_report.report') }}</title>
    <style>
        {!! $fontFace ?? '' !!}

        @page { margin: {{ $thermal ? '2mm' : '10mm 8mm' }}; }

        * { box-sizing: border-box; }

        body {
            font-family: {{ $fontFamily ?? "'DejaVu Sans', sans-serif" }};
            font-size: {{ $thermal ? '9px' : '9.5px' }};
            color: #1a1a1a;
            margin: 0;
            padding: 0;
            width: {{ $width }};
            line-height: 1.35;
        }

        h1, h2, h3 { margin: 0; font-weight: bold; }
        h1 { font-size: {{ $thermal ? '13px' : '18px' }}; letter-spacing: -0.3px; }
        h2 { font-size: {{ $thermal ? '10px' : '11px' }}; margin-bottom: 4px; padding-bottom: 2px; border-bottom: 1px solid #d4d4d4; }

        .muted { color: #6b6b6b; }
        .right { text-align: right; }
        .center { text-align: center; }
        .bold { font-weight: bold; }
        .nowrap { white-space: nowrap; }
        .mono { font-variant-numeric: tabular-nums; }

        .header {
            border-bottom: 2px solid #1a1a1a;
            padding-bottom: 6px;
            margin-bottom: 8px;
        }
        .header .title-row { width: 100%; }
        .header .brand { font-size: {{ $thermal ? '13px' : '19px' }}; font-weight: bold; letter-spacing: -0.4px; }
        .header .sub { font-size: {{ $thermal ? '8px' : '9.5px' }}; color: #4a4a4a; margin-top: 1px; }

        .filters {
            background: #f5f5f5;
            border-left: 3px solid #9a9a9a;
            padding: 4px 7px;
            margin-bottom: 7px;
            font-size: {{ $thermal ? '7px' : '8.5px' }};
        }
        .filters .label { font-weight: bold; text-transform: uppercase; letter-spacing: 0.4px; color: #5a5a5a; }

        table { width: 100%; border-collapse: collapse; }
        .kpi-table td {
            width: 20%;
            border: 1px solid #d4d4d4;
            padding: {{ $thermal ? '4px' : '5px 7px' }};
            vertical-align: top;
        }
        .kpi-label { font-size: {{ $thermal ? '7px' : '7.5px' }}; text-transform: uppercase; letter-spacing: 0.5px; color: #5a5a5a; }
        .kpi-value { font-size: {{ $thermal ? '11px' : '14px' }}; font-weight: bold; margin-top: 1px; }
        .kpi-hint { font-size: {{ $thermal ? '7px' : '7.5px' }}; color: #6b6b6b; margin-top: 1px; }

        .data-table { margin-top: 3px; }
        .data-table th {
            background: #ececec;
            border: 1px solid #cfcfcf;
            padding: {{ $thermal ? '2px 3px' : '3px 5px' }};
            text-align: left;
            font-size: {{ $thermal ? '7px' : '8px' }};
            text-transform: uppercase;
            letter-spacing: 0.3px;
        }
        .data-table td {
            border: 1px solid #dedede;
            padding: {{ $thermal ? '2px 3px' : '2.5px 5px' }};
            font-size: {{ $thermal ? '7px' : '8.5px' }};
            word-wrap: break-word;
        }
        .data-table tr.total-row td { background: #f0f0f0; font-weight: bold; border-top: 2px solid #9a9a9a; }
        .data-table tbody tr:nth-child(even) td { background: #fafafa; }

        /* Detail rows repeat the header on every page so a two-page run of
           transactions is still readable on the second sheet. */
        .data-table thead { display: table-header-group; }
        .data-table tr { page-break-inside: avoid; }

        .section { margin-bottom: 8px; page-break-inside: avoid; }

        /* Method and status splits sit side by side rather than stacked —
           they are four rows each and stacking them wastes half a page. */
        .split-table { width: 100%; border-collapse: separate; border-spacing: 0; }
        .split-table > tbody > tr > td { vertical-align: top; width: 50%; }
        .split-table > tbody > tr > td:first-child { padding-right: 6px; }

        .badge {
            display: inline-block;
            padding: 0 3px;
            border: 1px solid #b4b4b4;
            font-size: {{ $thermal ? '6px' : '7.5px' }};
            text-transform: uppercase;
            letter-spacing: 0.3px;
        }
        .badge-paid { border-color: #2f7a4d; color: #2f7a4d; }
        .badge-pending { border-color: #9a6b12; color: #9a6b12; }
        .badge-failed { border-color: #a33; color: #a33; }

        .footer {
            margin-top: 10px;
            padding-top: 5px;
            border-top: 1px solid #d4d4d4;
            font-size: {{ $thermal ? '7px' : '7.5px' }};
            color: #6b6b6b;
        }
        .sig-row { margin-top: 16px; width: 100%; }
        .sig-row td { width: 33%; padding-top: 14px; border-top: 1px solid #8a8a8a; font-size: 7.5px; color: #5a5a5a; }

        @media print {
            body { width: auto; }
            .page-break { page-break-after: always; }
        }
    </style>
</head>
<body>

{{-- ---------------------------------------------------------------- --}}
{{-- Header                                                            --}}
{{-- ---------------------------------------------------------------- --}}
<div class="header">
    @if ($thermal)
        <div class="center">
            <div class="brand">{{ $brand['name'] ?? config('app.name') }}</div>
            <div class="sub">{{ $meta['period_label'] }} {{ __('filament.equb_report.payment_report') }}</div>
            <div class="sub bold">{{ $meta['range_label'] }}</div>
        </div>
    @else
        <table class="title-row">
            <tr>
                <td style="vertical-align: bottom;">
                    <div class="brand">{{ $brand['name'] ?? config('app.name') }}</div>
                    <div class="sub">{{ $brand['tagline'] ?? __('filament.equb_report.payment_report') }}</div>
                </td>
                <td class="right" style="vertical-align: bottom;">
                    <h1>{{ $meta['period_label'] }} {{ __('filament.equb_report.report') }}</h1>
                    <div class="sub bold">{{ $meta['range_label'] }}</div>
                    <div class="sub muted">
                        {{ __('filament.equb_report.generated') }}:
                        {{ \Illuminate\Support\Carbon::parse($meta['generated_at'])->translatedFormat('d M Y, H:i') }}
                    </div>
                </td>
            </tr>
        </table>
    @endif
</div>

{{-- Filters actually applied. Printed so a report on a desk always says --}}
{{-- what it covers; an unlabelled subtotal is how wrong numbers spread. --}}
@if (! empty($meta['filters']))
    <div class="filters">
        <span class="label">{{ __('filament.equb_report.filters_applied') }}:</span>
        {{ implode('  •  ', $meta['filters']) }}
    </div>
@endif

{{-- ---------------------------------------------------------------- --}}
{{-- Headline figures                                                  --}}
{{-- ---------------------------------------------------------------- --}}
<div class="section">
    @if ($thermal)
        <table class="data-table">
            <tr><td>{{ __('filament.equb_report.collected') }}</td><td class="right bold mono">{{ $money($summary['collected']) }}</td></tr>
            <tr><td>{{ __('filament.equb_report.outstanding') }}</td><td class="right mono">{{ $money($summary['outstanding']) }}</td></tr>
            <tr><td>{{ __('filament.equb_report.transactions') }}</td><td class="right mono">{{ number_format($summary['transactions']) }}</td></tr>
            <tr><td>{{ __('filament.equb_report.paying_members') }}</td><td class="right mono">{{ number_format($summary['members']) }}</td></tr>
            <tr class="total-row"><td>{{ __('filament.equb_report.average_payment') }}</td><td class="right mono">{{ $money($summary['average_payment']) }}</td></tr>
        </table>
    @else
        <table class="kpi-table">
            <tr>
                <td>
                    <div class="kpi-label">{{ __('filament.equb_report.collected') }} ({{ $currency }})</div>
                    <div class="kpi-value mono">{{ $money($summary['collected']) }}</div>
                    <div class="kpi-hint">
                        {{ $pct($growth['collected'] ?? null) }} {{ __('filament.equb_report.vs_previous') }}
                    </div>
                </td>
                <td>
                    <div class="kpi-label">{{ __('filament.equb_report.outstanding') }} ({{ $currency }})</div>
                    <div class="kpi-value mono">{{ $money($summary['outstanding']) }}</div>
                    <div class="kpi-hint">{{ number_format($summary['pending_count']) }} {{ __('filament.equb_report.pending_payments') }}</div>
                </td>
                <td>
                    <div class="kpi-label">{{ __('filament.equb_report.transactions') }}</div>
                    <div class="kpi-value mono">{{ number_format($summary['transactions']) }}</div>
                    <div class="kpi-hint">
                        {{ number_format($summary['paid_count']) }} {{ __('filament.equb_report.settled') }} ·
                        {{ number_format($summary['failed_count']) }} {{ __('filament.equb_report.failed') }}
                    </div>
                </td>
                <td>
                    <div class="kpi-label">{{ __('filament.equb_report.paying_members') }}</div>
                    <div class="kpi-value mono">{{ number_format($summary['members']) }}</div>
                    <div class="kpi-hint">{{ number_format($summary['groups']) }} {{ __('filament.equb_report.active_groups') }}</div>
                </td>
                <td>
                    <div class="kpi-label">{{ __('filament.equb_report.average_payment') }} ({{ $currency }})</div>
                    <div class="kpi-value mono">{{ $money($summary['average_payment']) }}</div>
                    <div class="kpi-hint">{{ __('filament.equb_report.per_settled_payment') }}</div>
                </td>
            </tr>
        </table>
    @endif
</div>

{{-- ---------------------------------------------------------------- --}}
{{-- Equb type and status, side by side to save vertical space         --}}
{{-- ---------------------------------------------------------------- --}}
@if ($thermal)
    <div class="section">
        <h2>{{ __('filament.equb_report.by_type') }}</h2>
        <table class="data-table">
            <thead>
                <tr>
                    <th>{{ __('filament.equb_report.equb_type') }}</th>
                    <th class="right">{{ __('filament.equb_report.collected') }}</th>
                    <th class="right">{{ __('filament.equb_report.count') }}</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($report['by_type'] as $row)
                    <tr>
                        <td>{{ $row['label'] }}</td>
                        <td class="right mono">{{ $money($row['collected']) }}</td>
                        <td class="right mono">{{ number_format($row['transactions']) }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
@else
    <table class="split-table section">
        <tr>
            <td>
                {{-- The split that matters here: money arriving through family
                     Group Equbs versus members who joined an Equb on their own. --}}
                <h2>{{ __('filament.equb_report.by_type') }}</h2>
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>{{ __('filament.equb_report.equb_type') }}</th>
                            <th class="right">{{ __('filament.equb_report.collected') }}</th>
                            <th class="right">{{ __('filament.equb_report.members') }}</th>
                            <th class="right">{{ __('filament.equb_report.count') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($report['by_type'] as $row)
                            <tr>
                                <td>{{ $row['label'] }}</td>
                                <td class="right mono">{{ $money($row['collected']) }}</td>
                                <td class="right mono">{{ number_format($row['members']) }}</td>
                                <td class="right mono">{{ number_format($row['transactions']) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </td>
            <td>
                <h2>{{ __('filament.equb_report.by_status') }}</h2>
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>{{ __('filament.equb_report.status') }}</th>
                            <th class="right">{{ __('filament.equb_report.amount') }}</th>
                            <th class="right">{{ __('filament.equb_report.count') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($report['by_status'] as $row)
                            <tr>
                                <td><span class="badge badge-{{ $row['key'] }}">{{ $row['label'] }}</span></td>
                                <td class="right mono">{{ $money($row['amount']) }}</td>
                                <td class="right mono">{{ number_format($row['transactions']) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </td>
        </tr>
    </table>
@endif

{{-- ---------------------------------------------------------------- --}}
{{-- Equb group performance                                            --}}
{{-- ---------------------------------------------------------------- --}}
@if (! $thermal && $report['by_group']->isNotEmpty())
    <div class="section">
        <h2>{{ __('filament.equb_report.by_group') }}</h2>
        <table class="data-table">
            <thead>
                <tr>
                    <th style="width: 44%;">{{ __('filament.equb_report.equb_group') }}</th>
                    <th class="right">{{ __('filament.equb_report.collected') }}</th>
                    <th class="right">{{ __('filament.equb_report.outstanding') }}</th>
                    <th class="right">{{ __('filament.equb_report.members') }}</th>
                    <th class="right">{{ __('filament.equb_report.count') }}</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($report['by_group'] as $row)
                    <tr>
                        <td>{{ $row['label'] }}</td>
                        <td class="right mono">{{ $money($row['collected']) }}</td>
                        <td class="right mono">{{ $money($row['outstanding']) }}</td>
                        <td class="right mono">{{ number_format($row['members']) }}</td>
                        <td class="right mono">{{ number_format($row['transactions']) }}</td>
                    </tr>
                @endforeach
                <tr class="total-row">
                    <td>{{ __('filament.equb_report.total') }}</td>
                    <td class="right mono">{{ $money($report['by_group']->sum('collected')) }}</td>
                    <td class="right mono">{{ $money($report['by_group']->sum('outstanding')) }}</td>
                    <td class="right mono">—</td>
                    <td class="right mono">{{ number_format($report['by_group']->sum('transactions')) }}</td>
                </tr>
            </tbody>
        </table>
    </div>
@endif

{{-- ---------------------------------------------------------------- --}}
{{-- Group Equb performance                                            --}}
{{-- ---------------------------------------------------------------- --}}
{{-- Member-created family groups, kept separate from the platform Equb --}}
{{-- rollup above. The rollup answers "what did Raha Montly take"; this --}}
{{-- answers "what came from Mahi Family", which is the question an     --}}
{{-- admin actually gets asked.                                          --}}
@if (! $thermal && $report['by_group_equb']->isNotEmpty())
    <div class="section">
        <h2>{{ __('filament.equb_report.by_group_equb') }}</h2>
        <table class="data-table">
            <thead>
                <tr>
                    <th style="width: 24%;">{{ __('filament.equb_report.group_equb') }}</th>
                    <th style="width: 20%;">{{ __('filament.equb_report.inside_equb') }}</th>
                    <th style="width: 18%;">{{ __('filament.equb_report.created_by') }}</th>
                    <th class="right">{{ __('filament.equb_report.collected') }}</th>
                    <th class="right">{{ __('filament.equb_report.outstanding') }}</th>
                    <th class="right">{{ __('filament.equb_report.members') }}</th>
                    <th class="right">{{ __('filament.equb_report.count') }}</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($report['by_group_equb'] as $row)
                    <tr>
                        <td>
                            {{ $row['label'] }}
                            @if ($row['invite_code'])
                                <span class="muted" style="font-size: 7px;">({{ $row['invite_code'] }})</span>
                            @endif
                        </td>
                        <td>{{ $row['parent'] }}</td>
                        <td>{{ $row['owner'] }}</td>
                        <td class="right mono">{{ $money($row['collected']) }}</td>
                        <td class="right mono">{{ $money($row['outstanding']) }}</td>
                        <td class="right mono">{{ number_format($row['members']) }}</td>
                        <td class="right mono">{{ number_format($row['transactions']) }}</td>
                    </tr>
                @endforeach
                <tr class="total-row">
                    <td colspan="3">{{ __('filament.equb_report.total') }}</td>
                    <td class="right mono">{{ $money($report['by_group_equb']->sum('collected')) }}</td>
                    <td class="right mono">{{ $money($report['by_group_equb']->sum('outstanding')) }}</td>
                    <td class="right mono">—</td>
                    <td class="right mono">{{ number_format($report['by_group_equb']->sum('transactions')) }}</td>
                </tr>
            </tbody>
        </table>
    </div>
@endif

{{-- ---------------------------------------------------------------- --}}
{{-- Transaction detail                                                --}}
{{-- ---------------------------------------------------------------- --}}
@if (($includeDetails ?? true) && $report['details']->isNotEmpty())
    <div class="section" style="page-break-inside: auto;">
        <h2>
            {{ __('filament.equb_report.transaction_detail') }}
            <span class="muted" style="font-weight: normal; font-size: 7.5px;">
                ({{ number_format($report['details']->count()) }}
                @if ($summary['transactions'] > $report['details']->count())
                    {{ __('filament.equb_report.of') }} {{ number_format($summary['transactions']) }}
                @endif
                )
            </span>
        </h2>
        <table class="data-table">
            <thead>
                <tr>
                    <th style="width: 4%;">#</th>
                    <th style="width: 9%;">{{ __('filament.equb_report.date') }}</th>
                    <th style="width: 7%;">{{ __('filament.equb_report.time') }}</th>
                    <th style="width: 18%;">{{ __('filament.equb_report.member') }}</th>
                    <th style="width: 16%;">{{ __('filament.equb_report.equb_group') }}</th>
                    <th style="width: 16%;">{{ __('filament.equb_report.group_equb') }}</th>
                    <th style="width: 8%;">{{ __('filament.equb_report.method') }}</th>
                    <th style="width: 8%;">{{ __('filament.equb_report.status') }}</th>
                    <th style="width: 4%;">{{ __('filament.equb_report.reference') }}</th>
                    <th class="right" style="width: 10%;">{{ __('filament.equb_report.amount') }}</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($report['details'] as $row)
                    @php $paidAt = \Illuminate\Support\Carbon::parse($row['payment_date']); @endphp
                    <tr>
                        <td class="mono">{{ $row['id'] }}</td>
                        {{-- Exact date and time of the payment, to the second.
                             This is what an admin reconciles a cash book
                             against, so it is never rounded to a bucket. --}}
                        <td class="nowrap mono">{{ $paidAt->format('d/m/Y') }}</td>
                        <td class="nowrap mono">{{ $paidAt->format('H:i:s') }}</td>
                        <td>
                            {{ $row['member_name'] }}
                            @if ($row['member_phone'])
                                <div class="muted" style="font-size: 7px;">{{ $row['member_phone'] }}</div>
                            @endif
                        </td>
                        <td>
                            {{ $row['group_name'] }}
                            @if ($row['package_name'])
                                <div class="muted" style="font-size: 7px;">{{ $row['package_name'] }}</div>
                            @endif
                        </td>
                        {{-- Blank on a single Equb payment rather than repeating
                             the Equb name, so the two routes are distinguishable
                             at a glance down the column. --}}
                        <td>
                            @if ($row['group_equb_name'])
                                {{ $row['group_equb_name'] }}
                            @else
                                <span class="muted">{{ __('filament.equb_report.individual_equb') }}</span>
                            @endif
                        </td>
                        <td>{{ ucfirst($row['payment_method']) }}</td>
                        <td><span class="badge badge-{{ $row['status'] }}">{{ ucfirst($row['status']) }}</span></td>
                        <td class="mono" style="font-size: 6.5px; word-break: break-all;">{{ $row['reference'] ?: '—' }}</td>
                        <td class="right mono">{{ $money($row['amount']) }}</td>
                    </tr>
                @endforeach
                <tr class="total-row">
                    <td colspan="9">{{ __('filament.equb_report.total_shown') }}</td>
                    <td class="right mono">{{ $money($report['details']->sum('amount')) }}</td>
                </tr>
            </tbody>
        </table>
        @if ($summary['transactions'] > $report['details']->count())
            <div class="muted" style="margin-top: 3px; font-size: 7.5px;">
                {{ __('filament.equb_report.detail_truncated') }}
            </div>
        @endif
    </div>
@endif

{{-- ---------------------------------------------------------------- --}}
{{-- Footer                                                            --}}
{{-- ---------------------------------------------------------------- --}}
<div class="footer">
    @if ($thermal)
        <div class="center">
            <div>{{ \Illuminate\Support\Carbon::parse($meta['generated_at'])->translatedFormat('d M Y H:i') }}</div>
            <div>{{ __('filament.equb_report.system_generated') }}</div>
        </div>
    @else
        <table style="width: 100%;">
            <tr>
                <td>
                    {{ __('filament.equb_report.system_generated') }} ·
                    {{ \Illuminate\Support\Carbon::parse($meta['generated_at'])->translatedFormat('d M Y, H:i') }}
                    @isset($generatedBy)
                        · {{ __('filament.equb_report.by') }} {{ $generatedBy }}
                    @endisset
                </td>
                <td class="right">{{ $brand['name'] ?? config('app.name') }}</td>
            </tr>
        </table>

        @if ($showSignatures ?? true)
            <table class="sig-row">
                <tr>
                    <td>{{ __('filament.equb_report.prepared_by') }}</td>
                    <td style="width: 4%; border: none;"></td>
                    <td>{{ __('filament.equb_report.checked_by') }}</td>
                    <td style="width: 4%; border: none;"></td>
                    <td>{{ __('filament.equb_report.approved_by') }}</td>
                </tr>
            </table>
        @endif
    @endif
</div>

</body>
</html>
