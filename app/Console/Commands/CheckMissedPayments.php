<?php

namespace App\Console\Commands;

use App\Enums\EqubMembershipStatus;
use App\Enums\EqubPaymentStatus;
use App\Models\EqubMembership;
use App\Services\FcmService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class CheckMissedPayments extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'equb:check-missed-payments';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Check for members who missed 3 or more payments and notify them';

    /**
     * Execute the console command.
     */
    public function handle(FcmService $fcmService)
    {
        $this->info('Checking for missed payments...');

        $memberships = EqubMembership::where('status', EqubMembershipStatus::Active)
            ->with(['equbGroup', 'member.user'])
            ->get();

        $notifiedCount = 0;

        /** @var \App\Models\EqubMembership $membership */
        foreach ($memberships as $membership) {
            $group = $membership->equbGroup;
            if (!$group) continue;

            $joinDate = $membership->join_date;
            $frequency = $membership->contribution_frequency_days;
            
            if ($frequency <= 0) continue;

            if (now()->lessThan($joinDate)) {
                $expectedInstallments = 0;
            } else {
                $daysSinceJoin = $joinDate->diffInDays(now());
                $expectedInstallments = (int) floor($daysSinceJoin / $frequency) + 1;
            }

            // Cap by total rounds in the group
            $totalRounds = (int) ($group->duration_value ?? 0);
            if ($totalRounds > 0 && $expectedInstallments > $totalRounds) {
                $expectedInstallments = $totalRounds;
            }

            $paidInstallments = $membership->payments()
                ->where('status', EqubPaymentStatus::Paid)
                ->count();

            $overdueCount = $expectedInstallments - $paidInstallments;

            if ($overdueCount >= 3) {
                // Check if we should notify (e.g. once every 3 days)
                if (!$membership->last_overdue_notified_at || $membership->last_overdue_notified_at->diffInDays(now()) >= 3) {
                    $this->notifyMember($membership, $overdueCount, $fcmService);
                    $membership->update(['last_overdue_notified_at' => now()]);
                    $notifiedCount++;
                }
            }
        }

        $this->info("Done. Notified {$notifiedCount} memberships.");
    }

    protected function notifyMember(EqubMembership $membership, int $overdueCount, FcmService $fcmService)
    {
        $user = $membership->member->user;
        if (!$user || !$user->fcm_token) {
            Log::warning("[OverdueNotification] User or FCM token missing for membership ID: {$membership->id}");
            return;
        }

        $groupName = $membership->equbGroup->name ?? 'Niya Equb';
        $title = "Overdue Payments Alert";
        $body = "You have {$overdueCount} overdue payments for {$groupName}. Please make a payment to stay active.";

        $data = [
            'type' => 'overdue_payment',
            'membership_id' => (string) $membership->id,
            'overdue_count' => (string) $overdueCount,
        ];

        $fcmService->sendToToken($user->fcm_token, $data, $title, $body);

        $smsService = app()->make(\App\Services\SmsService::class);
        $smsService->sendSms($user->phone, $body);
        Log::info("[OverdueNotification] Sent notification to user {$user->id} for membership {$membership->id}. Overdue: {$overdueCount}");
    }
}
