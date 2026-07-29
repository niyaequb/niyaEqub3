<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

class CheckCompletedMemberships extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:check-completed-memberships';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Command description';

    /**
     * Execute the console command.
     */
    public function handle(\App\Services\EqubMembershipService $membershipService)
    {
        $this->info('Checking for completed memberships...');

        $activeMemberships = \App\Models\EqubMembership::where('status', \App\Enums\EqubMembershipStatus::Active)
            ->where('has_won', true)
            ->get();

        $count = 0;
        foreach ($activeMemberships as $membership) {
            if ($membershipService->completeIfEligible($membership)) {
                $count++;
            }
        }

        $this->info("Successfully completed $count memberships.");
    }
}
