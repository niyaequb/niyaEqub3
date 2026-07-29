<?php

namespace App\Filament\Resources\Members\Pages;

use App\Filament\Resources\Members\MemberResource;
use App\Models\Member;
use App\Models\User;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Hash;

class CreateMember extends CreateRecord
{
    protected static string $resource = MemberResource::class;

    /**
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordCreation(array $data): Member
    {
        $userData = $data['user'] ?? [];
        $memberData = Arr::except($data, ['user']);

        $user = User::create([
            'name' => $memberData['full_name'],
            'phone' => $userData['phone'],
            'email' => $userData['email'] ?? null,
            'password' => Hash::make($userData['password']),
            'type' => 'member',
            'phone_verified_at' => now(),
            'is_active' => true,
        ]);

        $memberData['user_id'] = $user->id;

        return Member::create($memberData);
    }
}
