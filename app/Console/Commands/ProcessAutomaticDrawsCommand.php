<?php

namespace App\Console\Commands;

use App\Models\EqubGroup;
use App\Enums\EqubDrawType;
use App\Enums\EqubGroupStatus;
use App\Services\EqubDrawService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class ProcessAutomaticDrawsCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'equb:process-automatic-draws';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Automatically process draws for eligible Equb groups based on frequency.';

    /**
     * Execute the console command.
     */
    public function handle(EqubDrawService $drawService)
    {
        if (! config('services.equb.auto_draw_enabled')) {
            $this->info('Automatic draws are disabled in settings.');
            return;
        }

        $this->info('Starting automatic draw processing...');

        $groups = EqubGroup::where('status', EqubGroupStatus::Running)
            ->whereIn('draw_type', [EqubDrawType::Automatic, EqubDrawType::Both])
            ->get();

        if ($groups->isEmpty()) {
            $this->info('No running Equb groups found with automatic draw enabled.');
            return;
        }

        foreach ($groups as $group) {
            $lastDraw = $group->draws()->latest('draw_date')->first();

            if (!$group->equb_start_date) {
                $this->warn("Group {$group->name} (ID: {$group->id}) has no start date. Skipping.");
                continue;
            }

            // Calculate next draw date: last draw date + frequency days
            if (!$lastDraw) {
                // If it's the very first draw, it's due if today >= start date
                $isDue = now()->greaterThanOrEqualTo($group->equb_start_date);
            } else {
                $nextDrawDate = $lastDraw->draw_date->copy()->addDays($group->contribution_frequency_days);
                $isDue = now()->greaterThanOrEqualTo($nextDrawDate);
            }

            if ($isDue) {
                $membersPerDraw = config('services.equb.members_per_draw', 50);
                $drawLimit = max(1, (int) ceil($group->current_members_count / $membersPerDraw));

                // Find out how many draws have already been done today to determine how many more to run.
                // This is a safety check in case the command is run multiple times.
                $alreadyDrawnToday = $group->draws()->whereDate('draw_date', now()->today())->count();
                $drawsRemaining = $drawLimit - $alreadyDrawnToday;

                if ($drawsRemaining <= 0) {
                    $this->info("Group {$group->name} (ID: {$group->id}) has already reached its daily limit of {$drawLimit} draws.");
                    continue;
                }

                $this->info("Processing automatic draws for group: {$group->name} (ID: {$group->id})");

                $result = $drawService->runRemainingDrawsForToday($group->id);

                if ($result['success']) {
                    $count = $result['draw_count'];
                    $winners = implode(', ', $result['winners']);
                    $this->info("Successfully executed {$count} draw(s). Winners: {$winners}");
                    Log::info("Automatic draws successful for Equb group {$group->id}. Draw count: {$count}. Winners: {$winners}");
                } else {
                    $this->error("No draws processed for group {$group->id}: " . ($result['message'] ?? 'Unknown reason'));
                    Log::info("No automatic draws processed for Equb group {$group->id}: " . ($result['message'] ?? 'No eligibility or limit reached'));
                }
            } else {
                $nextScheduled = $lastDraw 
                    ? $lastDraw->draw_date->copy()->addDays($group->contribution_frequency_days)->toDateString()
                    : $group->equb_start_date->toDateString();
                $this->info("Group {$group->name} (ID: {$group->id}) is not due for a draw yet. Next draw: " . $nextScheduled);
            }
        }

        $this->info('Automatic draw processing finished.');
    }
}
