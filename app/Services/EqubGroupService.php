<?php

namespace App\Services;

use App\Enums\EqubDurationType;
use App\Enums\EqubGroupStatus;
use App\Enums\EqubMembershipStatus;
use App\Models\EqubGroup;
use Carbon\Carbon;

class EqubGroupService
{
    /**
     * Initialize an Equb group: calculate member count, end date, and prize pool.
     */
    public function initialize(EqubGroup $group): void
    {
        $activeMemberships = $group->memberships()
            ->where('status', EqubMembershipStatus::Active)
            ->get();

        $memberCount = $activeMemberships->count();

        // if ($memberCount === 0) {
        //     throw new \RuntimeException('Cannot start Equb: No members have joined yet.');
        // }

        $startDate = $group->equb_start_date ?? now();

        // If manually started or cron picks up today, we ensure start date is not in future
        if ($startDate->isFuture()) {
             $startDate = now();
        }

        $endDate = $this->calculateEndDate(
            $group->duration_type,
            $group->duration_value,
            $group->duration_unit,
            $group->contribution_frequency_days,
            $memberCount,
            $startDate
        );

        $prizePool = (float)($group->fixed_contribution_amount * $memberCount);

        $group->update([
            'status' => EqubGroupStatus::Running,
            'registration_close_at' => $group->registration_close_at ?? now(),
            'equb_start_date' => $startDate,
            'equb_end_date' => $endDate,
            'current_members_count' => $memberCount,
            'total_amount_per_draw' => $prizePool,
        ]);

        $group->memberships()
            ->where('status', EqubMembershipStatus::Active)
            ->update([
                'calculated_end_date' => $endDate,
            ]);
    }

    /**
     * Calculate the end date based on duration type and member count.
     */
    protected function calculateEndDate(
        EqubDurationType $type,
        ?int $durationValue,
        ?\App\Enums\EqubDurationUnit $durationUnit,
        ?int $frequencyDays,
        int $memberCount,
        Carbon $startDate
    ): Carbon {
        if ($type === EqubDurationType::PerMember) {
            $totalDays = $memberCount * ($frequencyDays ?? 0);
            return $startDate->copy()->addDays($totalDays - 1); // Subtract 1 day to end on the last contribution day
        }

        // Fixed duration
        $endDate = $startDate->copy();

        switch ($durationUnit) {
            case \App\Enums\EqubDurationUnit::Weeks:
                $endDate->addWeeks($durationValue ?? 0);
                break;
            case \App\Enums\EqubDurationUnit::Months:
                $endDate->addMonths($durationValue ?? 0);
                break;
            case \App\Enums\EqubDurationUnit::Days:
            default:
                $endDate->addDays($durationValue ?? 0);
                break;
        }

        return $endDate;
    }
}
