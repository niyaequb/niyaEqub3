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

        // Created by an admin, so the phone is trusted: mark it verified,
        // otherwise the agent app refuses to log in ("Phone number not verified").
        $user = User::create([
            'name' => $userData['name'],
            'phone' => self::formatPhone($userData['phone']),
            'email' => filled($userData['email'] ?? null) ? $userData['email'] : null,
            'city' => $userData['city'] ?? null,
            'password' => Hash::make($userData['password']),
            'type' => 'agent',
            'phone_verified_at' => now(),
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

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('view', ['record' => $this->getRecord()]);
    }

    protected function getCreatedNotificationTitle(): ?string
    {
        return 'Agent created. They can log in to the agent app right away with this phone and password.';
    }

    /**
     * Same format the apps use (+2519XXXXXXXX), so the agent can log in
     * whether the admin typed 09..., 9... or +2519...
     */
    public static function formatPhone(string $number): string
    {
        $number = preg_replace('/\D/', '', $number);
        $number = ltrim($number, '0');

        if (! str_starts_with($number, '251')) {
            $number = '251'.$number;
        }

        return '+'.$number;
    }

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        if (filled($data['user']['phone'] ?? null)) {
            $phone = self::formatPhone($data['user']['phone']);

            if (User::query()->where('phone', $phone)->exists()) {
                \Filament\Notifications\Notification::make()
                    ->danger()
                    ->title('This phone number is already registered.')
                    ->body('Use a different number, or delete the old account first.')
                    ->send();

                $this->halt();
            }
        }

        return $data;
    }

    private function generateReferralCode(): string
    {
        do {
            $code = Str::upper(Str::random(8));
        } while (Agent::query()->where('referral_code', $code)->exists());

        return $code;
    }
}
