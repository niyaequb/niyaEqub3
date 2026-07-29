<?php

namespace App\Filament\Resources\Members\Pages;

use App\Filament\Resources\Members\MemberResource;
use Filament\Actions\Action;
use Filament\Resources\Pages\ViewRecord;

class ViewMember extends ViewRecord
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
            Action::make('manage_equbs')
                ->label('Manage Equbs')
                ->icon('heroicon-o-rectangle-stack')
                ->url(fn (): string => MemberResource::getUrl('equbs', ['record' => $this->record])),
            ...parent::getHeaderActions(),
        ];
    }
}
