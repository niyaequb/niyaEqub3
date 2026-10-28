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
        // Only a Super Admin may change a Super Admin account.
        if ($record->hasRole('Super Admin') && ! Auth::user()?->hasRole('Super Admin')) {
            Notification::make()
                ->title('Only a Super Admin can edit this account.')
                ->danger()
                ->send();

            $this->halt();
        }

        // Keep the original verification date when the toggle stays on.
        $phoneVerifiedAt = ! empty($data['phone_verified_at'])
            ? ($record->phone_verified_at ?? now())
            : null;

        $updates = [
            'name' => $data['name'],
            'phone' => $data['phone'],
            'email' => $data['email'] ?? null,
            'city' => $data['city'] ?? $record->city,
            'referral_code' => $data['referral_code'] ?? $record->referral_code,
            'type' => $data['type'],
            'is_active' => $data['is_active'] ?? false,
            'phone_verified_at' => $phoneVerifiedAt,
            'profile_picture' => $data['profile_picture'] ?? $record->profile_picture,
        ];

        // Never lock a Super Admin out by deactivating or demoting them.
        if ($record->hasRole('Super Admin')) {
            $updates['is_active'] = true;
        }

        if (! empty($data['password'])) {
            // The model casts password as 'hashed', so pass it plain.
            $updates['password'] = $data['password'];
        }

        $record->update($updates);

        return $record;
    }
}
