<?php

namespace App\Filament\Resources\EqubMemberships\Pages;

use App\Filament\Resources\EqubMemberships\EqubMembershipResource;
use App\Services\EqubMembershipService;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreateEqubMembership extends CreateRecord
{
    protected static string $resource = EqubMembershipResource::class;

    protected function handleRecordCreation(array $data): Model
    {
        $service = app(EqubMembershipService::class);
        $result = $service->joinEqub(
            (int) $data['member_id'],
            (int) $data['equb_group_id'],
            (float) $data['contribution_amount'],
            isset($data['contribution_frequency_days']) ? (int) $data['contribution_frequency_days'] : null
        );

        if (! $result['success']) {
            $validator = \Illuminate\Support\Facades\Validator::make([], []);
            $validator->errors()->add('equb_group_id', $result['message']);

            throw new \Illuminate\Validation\ValidationException($validator);
        }

        return $result['membership'];
    }
}
