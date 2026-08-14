<?php

namespace App\Enums;

use Carbon\CarbonImmutable;

/**
 * The reporting windows offered on the Equb Payment Report page.
 *
 * Each case knows three things: the window it covers by default, the bucket
 * size its trend chart uses, and the label format for those buckets. Keeping
 * that here means the page, the charts, the PDF and the scheduled printer all
 * agree on what "weekly" means without repeating the logic.
 */
enum ReportPeriod: string
{
    case Daily = 'daily';
    case Weekly = 'weekly';
    case Monthly = 'monthly';
    case Custom = 'custom';

    public function label(): string
    {
        return match ($this) {
            self::Daily => __('filament.equb_report.period_daily'),
            self::Weekly => __('filament.equb_report.period_weekly'),
            self::Monthly => __('filament.equb_report.period_monthly'),
            self::Custom => __('filament.equb_report.period_custom'),
        };
    }

    /**
     * The default window for this period, anchored on $on (normally today).
     *
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    public function range(?CarbonImmutable $on = null): array
    {
        $on ??= CarbonImmutable::now();

        return match ($this) {
            self::Daily => [$on->startOfDay(), $on->endOfDay()],
            self::Weekly => [$on->startOfWeek(), $on->endOfWeek()],
            self::Monthly => [$on->startOfMonth(), $on->endOfMonth()],
            // Custom has no implied window; the caller supplies from/to and we
            // fall back to the last 30 days when it does not.
            self::Custom => [$on->subDays(29)->startOfDay(), $on->endOfDay()],
        };
    }

    /**
     * The equivalent window immediately before $start, used for growth figures.
     * A daily report compares against yesterday, weekly against last week and
     * so on. Custom ranges shift back by their own length.
     *
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    public function previousRange(CarbonImmutable $start, CarbonImmutable $end): array
    {
        return match ($this) {
            self::Daily => [$start->subDay(), $end->subDay()],
            self::Weekly => [$start->subWeek(), $end->subWeek()],
            self::Monthly => [$start->subMonthNoOverflow(), $start->subMonthNoOverflow()->endOfMonth()],
            self::Custom => (function () use ($start, $end): array {
                // +1 second so a 1-31 Jan window steps back to 1-31 Dec rather
                // than overlapping the boundary by a day.
                $length = $start->diffInSeconds($end) + 1;

                return [$start->subSeconds($length), $start->subSecond()];
            })(),
        };
    }

    /** Bucket size for the trend chart: hour, day or month. */
    public function granularity(?CarbonImmutable $start = null, ?CarbonImmutable $end = null): string
    {
        if ($this !== self::Custom) {
            return match ($this) {
                self::Daily => 'hour',
                self::Weekly, self::Monthly => 'day',
                default => 'day',
            };
        }

        // Custom ranges pick a bucket that keeps the chart readable: a couple
        // of days reads best hour by hour, a quarter day by day, a year or
        // more month by month.
        $days = ($start && $end) ? $start->diffInDays($end) : 30;

        return match (true) {
            $days <= 2 => 'hour',
            $days <= 92 => 'day',
            default => 'month',
        };
    }

    /** How a bucket start is written on the chart axis and in the PDF. */
    public static function bucketLabelFormat(string $granularity): string
    {
        return match ($granularity) {
            'hour' => 'H:i',
            'month' => 'M Y',
            default => 'd M',
        };
    }
}
