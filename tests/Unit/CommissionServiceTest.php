<?php

namespace Tests\Unit;

use App\Enums\CommissionStatus;
use App\Enums\CommissionTrigger;
use App\Enums\CommissionType;
use App\Enums\PaymentStatus;
use App\Models\Agent;
use App\Models\CommissionRule;
use App\Models\Member;
use App\Models\Payment;
use App\Services\CommissionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CommissionServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_resolve_rule_prefers_agent_override(): void
    {
        $agent = Agent::factory()->create();
        $globalRule = CommissionRule::factory()->create([
            'trigger' => CommissionTrigger::Signup,
            'agent_id' => null,
        ]);
        $overrideRule = CommissionRule::factory()->create([
            'trigger' => CommissionTrigger::Signup,
            'agent_id' => null,
        ]);
        $agent->update(['commission_rule_id' => $overrideRule->id]);

        $service = app(CommissionService::class);
        $rule = $service->resolveRuleForAgent($agent, CommissionTrigger::Signup);

        $this->assertNotNull($rule);
        $this->assertSame($overrideRule->id, $rule->id);
        $this->assertNotSame($globalRule->id, $rule->id);
    }

    public function test_record_signup_commission_creates_ledger_once(): void
    {
        $agent = Agent::factory()->create();
        $member = Member::factory()->for($agent, 'agent')->create();
        $rule = CommissionRule::factory()->create([
            'trigger' => CommissionTrigger::Signup,
            'commission_type' => CommissionType::Fixed,
            'commission_value' => 50,
            'agent_id' => null,
        ]);

        $service = app(CommissionService::class);
        $commission = $service->recordSignupCommission($member);

        $this->assertNotNull($commission);
        $this->assertSame($agent->id, $commission->agent_id);
        $this->assertSame($member->id, $commission->member_id);
        $this->assertSame($rule->id, $commission->commission_rule_id);
        $this->assertSame(CommissionTrigger::Signup, $commission->source);
        $this->assertSame(0.0, (float) $commission->base_amount);
        $this->assertSame(50.0, (float) $commission->commission_amount);
        $this->assertSame(CommissionStatus::Pending, $commission->status);

        $duplicate = $service->recordSignupCommission($member);
        $this->assertNull($duplicate);
    }

    public function test_record_payment_commission_tracks_first_and_recurring(): void
    {
        $agent = Agent::factory()->create();
        $member = Member::factory()->for($agent, 'agent')->create();
        CommissionRule::factory()->create([
            'trigger' => CommissionTrigger::FirstPayment,
            'commission_type' => CommissionType::Percentage,
            'commission_value' => 10,
            'agent_id' => null,
        ]);
        CommissionRule::factory()->create([
            'trigger' => CommissionTrigger::Payment,
            'commission_type' => CommissionType::Percentage,
            'commission_value' => 5,
            'agent_id' => null,
        ]);

        $service = app(CommissionService::class);

        $paymentOne = Payment::factory()->create([
            'member_id' => $member->id,
            'amount' => 100,
            'status' => PaymentStatus::Completed,
        ]);

        $firstCommission = $service->recordPaymentCommission($paymentOne);
        $this->assertNotNull($firstCommission);
        $this->assertSame(CommissionTrigger::FirstPayment, $firstCommission->source);
        $this->assertSame(10.0, (float) $firstCommission->commission_amount);

        $paymentTwo = Payment::factory()->create([
            'member_id' => $member->id,
            'amount' => 200,
            'status' => PaymentStatus::Completed,
        ]);

        $recurringCommission = $service->recordPaymentCommission($paymentTwo);
        $this->assertNotNull($recurringCommission);
        $this->assertSame(CommissionTrigger::Payment, $recurringCommission->source);
        $this->assertSame(10.0, (float) $recurringCommission->commission_amount);
    }
}
