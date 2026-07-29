<?php

namespace App\Services;

use App\Enums\CommissionStatus;
use App\Enums\CommissionTrigger;
use App\Enums\CommissionType;
use App\Enums\EqubPaymentStatus;
use App\Enums\PaymentStatus;
use App\Models\Agent;
use App\Models\AgentCommission;
use App\Models\CommissionRule;
use App\Models\EqubPayment;
use App\Models\Member;
use App\Models\Payment;
use Illuminate\Support\Facades\Log;

class CommissionService
{
    public function resolveRuleForAgent(?Agent $agent, CommissionTrigger $trigger): ?CommissionRule
    {
        if (! $agent || ! $agent->is_active) {
            Log::debug('[Commission] resolveRule: agent missing or inactive', [
                'agent_id' => $agent?->id,
                'is_active' => $agent?->is_active,
                'trigger' => $trigger->value,
            ]);
            return null;
        }

        Log::debug('[Commission] resolveRule: checking agent', [
            'agent_id' => $agent->id,
            'trigger' => $trigger->value,
            'commission_rule_id (override)' => $agent->commission_rule_id,
        ]);

        if ($agent->commission_rule_id) {
            $overrideRule = CommissionRule::query()
                ->whereKey($agent->commission_rule_id)
                ->where('trigger', $trigger)
                ->where('is_active', true)
                ->first();

            if ($overrideRule) {
                Log::debug('[Commission] resolveRule: using OVERRIDE rule', ['rule_id' => $overrideRule->id, 'rule_name' => $overrideRule->name]);
                return $overrideRule;
            }
            Log::debug('[Commission] resolveRule: override rule not found or trigger mismatch, falling through');
        }

        $agentRule = CommissionRule::query()
            ->where('agent_id', $agent->id)
            ->where('trigger', $trigger)
            ->where('is_active', true)
            ->first();

        if ($agentRule) {
            Log::debug('[Commission] resolveRule: using AGENT-SPECIFIC rule', ['rule_id' => $agentRule->id, 'rule_name' => $agentRule->name]);
            return $agentRule;
        }

        $globalRule = CommissionRule::query()
            ->whereNull('agent_id')
            ->where('trigger', $trigger)
            ->where('is_active', true)
            ->first();

        Log::debug('[Commission] resolveRule: using GLOBAL rule', ['rule_id' => $globalRule?->id, 'rule_name' => $globalRule?->name]);
        return $globalRule;
    }

    public function recordSignupCommission(Member $member): ?AgentCommission
    {
        Log::info('[Commission] recordSignupCommission: start', ['member_id' => $member->id]);

        $agent = $member->agent;

        if (! $agent) {
            Log::warning('[Commission] recordSignupCommission: member has no agent', ['member_id' => $member->id]);
            return null;
        }

        $rule = $this->resolveRuleForAgent($agent, CommissionTrigger::Signup);

        if (! $rule) {
            Log::warning('[Commission] recordSignupCommission: no signup rule found', ['agent_id' => $agent->id]);
            return null;
        }

        $alreadyRecorded = AgentCommission::query()
            ->where('member_id', $member->id)
            ->where('source', CommissionTrigger::Signup)
            ->exists();

        if ($alreadyRecorded) {
            Log::info('[Commission] recordSignupCommission: already recorded, skipping', ['member_id' => $member->id]);
            return null;
        }

        $commissionAmount = $this->calculateCommission($rule, 0);

        Log::info('[Commission] recordSignupCommission: creating commission', [
            'agent_id' => $agent->id,
            'member_id' => $member->id,
            'rule_id' => $rule->id,
            'commission_amount' => $commissionAmount,
        ]);

        return AgentCommission::query()->create([
            'agent_id' => $agent->id,
            'member_id' => $member->id,
            'commission_rule_id' => $rule->id,
            'source' => CommissionTrigger::Signup,
            'reference_id' => null,
            'base_amount' => 0,
            'commission_amount' => $commissionAmount,
            'status' => CommissionStatus::Pending,
            'created_at' => now(),
        ]);
    }

    public function recordPaymentCommission(Payment $payment): ?AgentCommission
    {
        Log::info('[Commission] recordPaymentCommission: start', ['payment_id' => $payment->id, 'amount' => $payment->amount]);

        $member = $payment->member;
        $agent = $member?->agent;

        if (! $member || ! $agent) {
            Log::warning('[Commission] recordPaymentCommission: no member or agent', [
                'payment_id' => $payment->id,
                'member_id' => $member?->id,
                'agent_id' => $agent?->id,
            ]);
            return null;
        }

        $alreadyRecorded = AgentCommission::query()
            ->where('reference_id', $payment->id)
            ->exists();

        if ($alreadyRecorded) {
            Log::info('[Commission] recordPaymentCommission: already recorded, skipping', ['payment_id' => $payment->id]);
            return null;
        }

        $paymentsCount = Payment::query()
            ->where('member_id', $member->id)
            ->where('status', PaymentStatus::Completed)
            ->count();

        $trigger = $paymentsCount === 1
            ? CommissionTrigger::FirstPayment
            : CommissionTrigger::Payment;

        Log::info('[Commission] recordPaymentCommission: trigger determined', [
            'member_id' => $member->id,
            'completed_payments_count' => $paymentsCount,
            'trigger' => $trigger->value,
        ]);

        $rule = $this->resolveRuleForAgent($agent, $trigger);

        // // Fallback to standard payment rule if no exclusive first-payment rule is defined
        // if (! $rule && $trigger === CommissionTrigger::FirstPayment) {
        //     $rule = $this->resolveRuleForAgent($agent, CommissionTrigger::Payment);
        // }

        if (! $rule) {
            Log::warning('[Commission] recordPaymentCommission: no rule found for trigger', [
                'agent_id' => $agent->id,
                'trigger' => $trigger->value,
            ]);
            return null;
        }

        $commissionAmount = $this->calculateCommission($rule, (float) $payment->amount);

        Log::info('[Commission] recordPaymentCommission: creating commission', [
            'agent_id' => $agent->id,
            'member_id' => $member->id,
            'rule_id' => $rule->id,
            'trigger' => $trigger->value,
            'base_amount' => $payment->amount,
            'commission_amount' => $commissionAmount,
        ]);

        return AgentCommission::query()->create([
            'agent_id' => $agent->id,
            'member_id' => $member->id,
            'commission_rule_id' => $rule->id,
            'source' => $trigger,
            'reference_id' => $payment->id,
            'base_amount' => $payment->amount,
            'commission_amount' => $commissionAmount,
            'status' => CommissionStatus::Pending,
            'created_at' => now(),
        ]);
    }

    public function recordEqubPaymentCommission(EqubPayment $equbPayment): ?AgentCommission
    {
        Log::info('[Commission] recordEqubPaymentCommission: start', ['equb_payment_id' => $equbPayment->id, 'amount' => $equbPayment->amount]);

        $membership = $equbPayment->membership;
        $member = $membership?->member;
        $agent = $member?->agent;

        if (! $member || ! $agent) {
            Log::warning('[Commission] recordEqubPaymentCommission: no member or agent', [
                'equb_payment_id' => $equbPayment->id,
                'equb_membership_id' => $membership?->id,
                'member_id' => $member?->id,
                'agent_id' => $agent?->id,
            ]);
            return null;
        }

        // Count how many paid Equb payments this member has (including the current one)
        $paidCount = EqubPayment::query()
            ->whereHas('membership', fn ($q) => $q->where('member_id', $member->id))
            ->where('status', EqubPaymentStatus::Paid)
            ->count();

        $trigger = $paidCount === 1
            ? CommissionTrigger::FirstPayment
            : CommissionTrigger::Payment;

        Log::info('[Commission] recordEqubPaymentCommission: trigger determined', [
            'member_id' => $member->id,
            'agent_id' => $agent->id,
            'paid_equb_payments_count' => $paidCount,
            'trigger' => $trigger->value,
        ]);

        $rule = $this->resolveRuleForAgent($agent, $trigger);

        // Fallback to standard payment rule if no exclusive first-payment rule is defined
        if (! $rule && $trigger === CommissionTrigger::FirstPayment) {
            Log::info('[Commission] recordEqubPaymentCommission: no first_payment rule, falling back to payment rule');
            $rule = $this->resolveRuleForAgent($agent, CommissionTrigger::Payment);
        }

        if (! $rule) {
            Log::warning('[Commission] recordEqubPaymentCommission: no rule found, aborting', [
                'agent_id' => $agent->id,
                'trigger' => $trigger->value,
            ]);
            return null;
        }

        $commissionAmount = $this->calculateCommission($rule, (float) $equbPayment->amount);

        Log::info('[Commission] recordEqubPaymentCommission: creating commission', [
            'agent_id' => $agent->id,
            'member_id' => $member->id,
            'rule_id' => $rule->id,
            'trigger' => $trigger->value,
            'base_amount' => $equbPayment->amount,
            'commission_amount' => $commissionAmount,
        ]);

        return AgentCommission::query()->create([
            'agent_id'          => $agent->id,
            'member_id'         => $member->id,
            'commission_rule_id'=> $rule->id,
            'source'            => $trigger,
            'reference_id'      => null,  // EqubPayment is not in the payments table
            'base_amount'       => $equbPayment->amount,
            'commission_amount' => $commissionAmount,
            'status'            => CommissionStatus::Pending,
            'created_at'        => now(),
        ]);
    }

    public function calculateCommission(CommissionRule $rule, float $baseAmount): float
    {
        return match ($rule->commission_type) {
            CommissionType::Fixed => (float) $rule->commission_value,
            CommissionType::Percentage => round(($baseAmount * (float) $rule->commission_value) / 100, 2),
        };
    }
}
