<?php

namespace App\Filament\Resources\RoleResource\Pages;

use App\Filament\Resources\RoleResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Support\Facades\Auth;

class EditRole extends EditRecord
{
    protected static string $resource = RoleResource::class;

    public function mount(int | string $record): void
    {
        parent::mount($record);

        // Prevent editing Super Admin role - redirect to list
        if ($this->record->name === 'Super Admin') {
            $this->notification()
                ->title('Cannot Edit Super Admin')
                ->warning()
                ->body('The Super Admin role cannot be modified.')
                ->send();

            $this->redirect(RoleResource::getUrl('index'));
        }
    }

    protected function getHeaderActions(): array
    {
        // No actions for Super Admin role
        if ($this->record->name === 'Super Admin') {
            return [];
        }

        return [
            Actions\DeleteAction::make()
                ->visible(fn () =>
                    Auth::check() &&
                    (Auth::user()->can('roles.delete') ?? true)
                ),
        ];
    }

    protected function mutateFormDataBeforeFill(array $data): array
    {
        // If Super Admin, ensure it has all permissions and disable editing
        if ($this->record->name === 'Super Admin') {
            $this->form->disabled();

            // Ensure Super Admin has all permissions
            $allPermissions = \Spatie\Permission\Models\Permission::all();
            $this->record->syncPermissions($allPermissions);

            // Set all permissions in form data
            $data['permissions'] = $allPermissions->pluck('id')->toArray();
        }
        return $data;
    }

    protected function beforeSave(): void
    {
        // Prevent saving changes to Super Admin role
        if ($this->record->name === 'Super Admin') {
            $this->halt();
            $this->notification()
                ->title('Cannot Edit Super Admin')
                ->danger()
                ->body('The Super Admin role cannot be modified.')
                ->send();
        }
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        // Prevent saving changes to Super Admin role
        if ($this->record->name === 'Super Admin') {
            return $data; // Return unchanged data
        }

        // Store permissions separately to prevent them from being saved as a column
        // We'll sync them in afterSave
        if (isset($data['permissions'])) {
            $this->permissionsToSync = $data['permissions'];
            unset($data['permissions']);
        }

        return $data;
    }

    protected $permissionsToSync = null;

    protected function afterSave(): void
    {
        // Sync permissions after the role is saved (for non-Super Admin roles)
        if ($this->record->name !== 'Super Admin' && $this->permissionsToSync !== null) {
            $this->record->syncPermissions($this->permissionsToSync);
        }
    }
}

