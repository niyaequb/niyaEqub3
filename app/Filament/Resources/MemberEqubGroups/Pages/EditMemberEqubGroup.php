<?php

namespace App\Filament\Resources\MemberEqubGroups\Pages;

use App\Filament\Resources\MemberEqubGroups\MemberEqubGroupResource;
use App\Models\EqubMembership;
use App\Services\MemberEqubGroupService;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;

class EditMemberEqubGroup extends EditRecord
{
    protected static string $resource = MemberEqubGroupResource::class;

    public function getTitle(): string
    {
        return __('filament.member_equb_group.edit').' — '.$this->record->name;
    }

    /**
     * Show the people already in the group so the admin can add more without
     * losing the current list.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeFill(array $data): array
    {
        $data['member_ids'] = EqubMembership::query()
            ->where('equb_group_id', $this->record->id)
            ->where('member_id', '!=', $this->record->owner_member_id)
            ->pluck('member_id')
            ->all();

        return $data;
    }

    /**
     * `member_ids` is not a column — it is an invitation list. Pull it out
     * before the model is saved and act on it afterwards.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        $this->pendingMemberIds = array_values(array_filter(
            array_map('intval', (array) ($data['member_ids'] ?? []))
        ));

        unset($data['member_ids']);

        return $data;
    }

    /** @var array<int, int> */
    protected array $pendingMemberIds = [];

    protected function afterSave(): void
    {
        if ($this->pendingMemberIds === []) {
            return;
        }

        // Anyone already in the group is skipped inside the service, so this
        // only ever enrols genuinely new people.
        $result = app(MemberEqubGroupService::class)->addMembersDirectly(
            $this->record,
            $this->pendingMemberIds,
        );

        if (($result['added'] ?? 0) > 0) {
            Notification::make()
                ->title(__('filament.member_equb_group.members_added', ['count' => $result['added']]))
                ->success()
                ->send();
        }

        if (($result['skipped'] ?? []) !== []) {
            Notification::make()
                ->title(__('filament.member_equb_group.some_members_skipped'))
                ->body(implode("\n", $result['skipped']))
                ->warning()
                ->send();
        }
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('ledger')
                ->label(__('filament.member_equb_group.view_ledger'))
                ->icon('heroicon-o-banknotes')
                ->color('gray')
                ->url(fn (): string => MemberEqubGroupResource::getUrl('ledger', ['record' => $this->record])),

            DeleteAction::make(),
        ];
    }

    protected function getRedirectUrl(): string
    {
        return static::getResource()::getUrl('index');
    }
}
