<?php

namespace App\Filament\Resources\UserResource\Pages;

use App\Filament\Resources\UserResource;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Support\Facades\Hash;
use App\Models\User;

class CreateUser extends CreateRecord
{
     protected static string $resource = UserResource::class;

    protected function handleRecordCreation(array $data): User
    {
            return User::create([
            'name' => $data['name'],
            'phone' => $data['phone'],
            'email' => $data['email'] ?? null,
            'password' => ! empty($data['password']) ? Hash::make($data['password']) : null,
            'type' => $data['type'],
            'is_active' => $data['is_active'] ?? false,
            'email_verified_at' => ! empty($data['email_verified_at']) ? now() : null,
            'phone_verified_at' => ! empty($data['phone_verified_at']) ? now() : null,
            'profile_picture' => $data['profile_picture'],
        ]);
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }

}
