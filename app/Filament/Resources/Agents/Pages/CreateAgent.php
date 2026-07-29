<?php

namespace App\Filament\Resources\Agents\Pages;

use App\Filament\Resources\Agents\AgentResource;
use App\Models\Agent;
use App\Models\User;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class CreateAgent extends CreateRecord
{
    protected static string $resource = AgentResource::class;

    /**
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordCreation(array $data): Agent
    {
        $userData = $data['user'] ?? [];
        $agentData = Arr::except($data, ['user']);

        $user = User::create([
            'name' => $userData['name'],
            'phone' => $userData['phone'],
            'email' => $userData['email'] ?? null,
            'password' => Hash::make($userData['password']),
            'type' => 'agent',
            'phone_verified_at' => null,
            'is_active' => true,
        ]);

        $referralCode = filled($agentData['referral_code'] ?? null)
            ? $agentData['referral_code']
            : $this->generateReferralCode();

        return Agent::create([
            'user_id' => $user->id,
            'referral_code' => $referralCode,
            'commission_rule_id' => $agentData['commission_rule_id'] ?? null,
            'is_active' => (bool) ($agentData['is_active'] ?? true),
            'bank_name' => $agentData['bank_name'] ?? null,
            'account_number' => $agentData['account_number'] ?? null,
            'account_holder_name' => $agentData['account_holder_name'] ?? null,
            'joined_at' => now(),
        ]);
    }

    private function generateReferralCode(): string
    {
        do {
            $code = Str::upper(Str::random(8));
        } while (Agent::query()->where('referral_code', $code)->exists());

        return $code;
    }
}
