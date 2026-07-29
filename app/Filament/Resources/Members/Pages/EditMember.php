<?php

namespace App\Filament\Resources\Members\Pages;

use App\Filament\Resources\Members\MemberResource;
use App\Models\Member;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Hash;

class EditMember extends EditRecord
{
    protected static string $resource = MemberResource::class;

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeFill(array $data): array
    {
        $user = $this->record->user;

        if ($user) {
            $data['user'] = [
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
                ->action(function (Member $record): void {
                    $record->user?->delete();
                }),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordUpdate($record, array $data): Member
    {
        $userData = $data['user'] ?? [];
        $memberData = Arr::except($data, ['user']);

        if (array_key_exists('full_name', $memberData)) {
            $userData['name'] = $memberData['full_name'];
        }

        if (array_key_exists('password', $userData) && filled($userData['password'])) {
            $userData['password'] = Hash::make($userData['password']);
        } else {
            unset($userData['password']);
        }

        if ($userData !== []) {
            $record->user?->update($userData);
        }

        $record->update($memberData);

        return $record;
    }
}
