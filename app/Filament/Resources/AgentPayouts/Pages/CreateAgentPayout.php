<?php

namespace App\Filament\Resources\AgentPayouts\Pages;

use App\Filament\Resources\AgentPayouts\AgentPayoutResource;
use App\Models\AgentCommission;
use Filament\Resources\Pages\CreateRecord;

class CreateAgentPayout extends CreateRecord
{
    protected static string $resource = AgentPayoutResource::class;

    /**
     * @var array<int, int>
     */
    protected array $commissionIds = [];

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $this->commissionIds = $data['commission_ids'] ?? [];
        unset($data['commission_ids']);

        if (! isset($data['total_amount']) || $data['total_amount'] === '' || $data['total_amount'] === null) {
            $data['total_amount'] = AgentCommission::query()
                ->whereIn('id', $this->commissionIds)
                ->sum('commission_amount');
        }

        return $data;
    }

    protected function afterCreate(): void
    {
        if ($this->commissionIds === []) {
            return;
        }

        $payout = $this->getRecord();

        $items = collect($this->commissionIds)->map(function (int $commissionId) use ($payout): array {
            return [
                'agent_payout_id' => $payout->id,
                'agent_commission_id' => $commissionId,
                'created_at' => now(),
                'updated_at' => now(),
            ];
        })->all();

        $payout->items()->insert($items);
    }
}
