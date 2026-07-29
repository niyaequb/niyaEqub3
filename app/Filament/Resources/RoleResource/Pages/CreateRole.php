<?php

namespace App\Filament\Resources\RoleResource\Pages;

use App\Filament\Resources\RoleResource;
use Filament\Resources\Pages\CreateRecord;

class CreateRole extends CreateRecord
{
    protected static string $resource = RoleResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        // Store permissions separately to prevent them from being saved as a column
        // Filament's relationship handling will sync them after the record is created
        if (isset($data['permissions'])) {
            $this->permissionsToSync = $data['permissions'];
            unset($data['permissions']);
        }

        return $data;
    }

    protected $permissionsToSync = [];

    protected function afterCreate(): void
    {
        // Sync permissions after the role is created
        if (!empty($this->permissionsToSync)) {
            $this->record->syncPermissions($this->permissionsToSync);
        }
    }
}


