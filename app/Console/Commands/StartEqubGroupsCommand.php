<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class StartEqubGroupsCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'equb:start-groups';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Automatically start and initialize Equb groups that reach their start date.';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        if (! config('services.equb.auto_start_enabled')) {
            $this->info('Automatic Equb group start is disabled in settings.');
            return;
        }

        Log::info('Starting Equb groups initialization process.');
        $groups = \App\Models\EqubGroup::where('status', \App\Enums\EqubGroupStatus::Registration)
            ->where('equb_start_date', '<=', now())
            ->get();

        if ($groups->isEmpty()) {
            $this->info('No groups to start today.');
            return;
        }

        $service = app(\App\Services\EqubGroupService::class);

        foreach ($groups as $group) {
            $this->info("Initializing Equb group: {$group->name} (ID: {$group->id})");
            $service->initialize($group);
        }

        $this->info('Finished initializing groups.');
        Log::info('Finished Equb groups initialization process.');
    }
}
