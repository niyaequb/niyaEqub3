<?php

namespace App\Filament\Resources\UserResource\Pages;

use App\Filament\Resources\UserResource;
use App\Models\User;
use Filament\Actions;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;

class EditUser extends EditRecord
{
    protected static string $resource = UserResource::class;

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }

    protected function getHeaderActions(): array
    {
        if ($this->record->hasRole('Super Admin')) {
            return [];
        }

        return [
            Actions\DeleteAction::make()
                ->visible(fn () => Auth::check() && (Auth::user()->can('users.delete') ?? true)),
        ];
    }

    protected function mutateFormDataBeforeFill(array $data): array
    {
        $data['phone_verified'] = ! is_null($this->record->phone_verified_at);
        $data['email_verified'] = ! is_null($this->record->email_verified_at);

        return $data;
    }

    protected function handleRecordUpdate($record, array $data): User
    {
        // Block editing Super Admin
        if ($record->hasRole('Super Admin')) {
            Notification::make()
                ->title('Cannot edit Super Admin user.')
                ->danger()
                ->send();
            $this->getRedirectUrl();
        }

        if (! empty($data['password'])) {
            $data['password'] = Hash::make($data['password']);
        } else {
            unset($data['password']);
        }

        $record->update([
            'name' => $data['name'],
            'phone' => $data['phone'],
            'email' => $data['email'] ?? null,
            'type' => $data['type'],
            'is_active' => $data['is_active'] ?? false,
            'email_verified_at' => ! empty($data['email_verified_at']) ? now() : null,
            'phone_verified_at' => ! empty($data['phone_verified_at']) ? now() : null,
            'profile_picture' => $data['profile_picture'] ?? $record->profile_picture,
            ...(! empty($data['password']) ? [
                'password' => $data['password'],
            ] : []),
        ]);

        return $record;
    }
}
