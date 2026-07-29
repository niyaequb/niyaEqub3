<?php

namespace App\Filament\Resources\Agents\Pages;

use App\Filament\Resources\Agents\AgentResource;
use App\Models\Agent;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Hash;

class EditAgent extends EditRecord
{
    protected static string $resource = AgentResource::class;

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeFill(array $data): array
    {
        $user = $this->record->user;

        if ($user) {
            $data['user'] = [
                'name' => $user->name,
                'phone' => $user->phone,
                'email' => $user->email,
            ];
        }

        return $data;
    }

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make()
                ->action(function (Agent $record): void {
                    $record->user?->delete();
                }),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        /** @var Agent $record */
        $userData = $data['user'] ?? [];
        $agentData = Arr::except($data, ['user']);

        if ($userData !== []) {
            $userPayload = [
                'name' => $userData['name'] ?? $record->user?->name,
                'phone' => $userData['phone'] ?? $record->user?->phone,
                'email' => $userData['email'] ?? $record->user?->email,
            ];

            if (array_key_exists('password', $userData) && filled($userData['password'])) {
                $userPayload['password'] = Hash::make($userData['password']);
            }

            $record->user?->update($userPayload);
        }

        $agentUpdate = [
            'commission_rule_id' => $agentData['commission_rule_id'] ?? null,
            'is_active' => (bool) ($agentData['is_active'] ?? true),
            'bank_name' => $agentData['bank_name'] ?? null,
            'account_number' => $agentData['account_number'] ?? null,
            'account_holder_name' => $agentData['account_holder_name'] ?? null,
        ];
        if (filled($agentData['referral_code'] ?? null)) {
            $agentUpdate['referral_code'] = $agentData['referral_code'];
        }
        $record->update($agentUpdate);

        return $record;
    }
}
