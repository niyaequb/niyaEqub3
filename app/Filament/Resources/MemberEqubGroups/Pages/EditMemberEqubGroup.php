<?php

namespace App\Filament\Resources\MemberEqubGroups\Pages;

use App\Filament\Resources\MemberEqubGroups\MemberEqubGroupResource;
use App\Models\EqubMembership;
use App\Models\Member;
use App\Services\GroupResponsibilityService;
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
            ->whereNotNull('member_id')
            ->where('member_id', '!=', $this->record->owner_member_id)
            ->pluck('member_id')
            ->all();

        // The owner's "My Responsibility People", so the admin can see who is
        // already being carried and add to the list without losing it.
        //
        // Only the owner's own places are shown: another member's places are
        // that member's business, and editing them from here would silently
        // move somebody else's obligation.
        $data['responsibility_people'] = EqubMembership::query()
            ->where('equb_group_id', $this->record->id)
            ->whereNull('member_id')
            ->where('sponsor_member_id', $this->record->owner_member_id)
            ->orderBy('id')
            ->get()
            ->map(fn (EqubMembership $seat): array => [
                // Carried so afterSave can tell an existing place from a newly
                // typed one. Without it, saving the form a second time would
                // open duplicates of everyone already there.
                'membership_id' => $seat->id,
                'name' => $seat->responsibility_name,
                'phone' => $seat->responsibility_phone,
                'relation' => $seat->responsibility_relation,
                'note' => $seat->responsibility_note,
            ])
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

        $this->pendingResponsibility = array_values(array_filter(
            (array) ($data['responsibility_people'] ?? []),
            fn ($row): bool => is_array($row) && trim((string) ($row['name'] ?? '')) !== ''
        ));

        unset($data['member_ids'], $data['responsibility_people']);

        return $data;
    }

    /** @var array<int, int> */
    protected array $pendingMemberIds = [];

    /** @var array<int, array<string, mixed>> */
    protected array $pendingResponsibility = [];

    protected function afterSave(): void
    {
        $this->syncResponsibilityPeople();

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

    /**
     * Reconcile the owner's "My Responsibility People" with what the form
     * came back with.
     *
     * Three cases, in the order they are safe to apply:
     *
     *   kept   — a row that still carries its membership_id. Only the
     *            descriptive fields can have changed, so it is updated in
     *            place. Its place in the circle, its contribution and its
     *            payment history are untouched.
     *   added  — a row with no membership_id. A new place is opened.
     *   removed— a place that was loaded into the form and is no longer
     *            there. Removal goes through the service, which refuses once
     *            the place has contributed or been paid out: that money
     *            belongs to the other members and deleting a row is not a way
     *            to release it.
     */
    protected function syncResponsibilityPeople(): void
    {
        $owner = Member::find($this->record->owner_member_id);

        if (! $owner) {
            return;
        }

        $service = app(GroupResponsibilityService::class);

        $existing = EqubMembership::query()
            ->where('equb_group_id', $this->record->id)
            ->whereNull('member_id')
            ->where('sponsor_member_id', $owner->id)
            ->get()
            ->keyBy('id');

        $keptIds = [];
        $added = 0;
        $problems = [];

        foreach ($this->pendingResponsibility as $row) {
            $id = (int) ($row['membership_id'] ?? 0);

            if ($id > 0 && $existing->has($id)) {
                $keptIds[] = $id;

                $result = $service->update($existing->get($id), [
                    'name' => $row['name'] ?? null,
                    'phone' => $row['phone'] ?? null,
                    'relation' => $row['relation'] ?? null,
                    'note' => $row['note'] ?? null,
                ]);

                if (! $result['success']) {
                    $problems[] = $result['message'];
                }

                continue;
            }

            $result = $service->add($this->record, $owner, $row);

            if ($result['success']) {
                $added++;
            } else {
                $problems[] = $result['message'];
            }
        }

        // Anything loaded into the form and then taken out of it.
        foreach ($existing as $id => $seat) {
            if (in_array((int) $id, $keptIds, true)) {
                continue;
            }

            $result = $service->remove($this->record, $seat, $owner);

            if (! $result['success']) {
                $problems[] = $result['message'];
            }
        }

        if ($added > 0) {
            Notification::make()
                ->title(__('filament.member_equb_group.responsibility_added', ['count' => $added]))
                ->success()
                ->send();
        }

        if ($problems !== []) {
            Notification::make()
                ->title(__('filament.member_equb_group.responsibility_skipped'))
                ->body(implode("\n", $problems))
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
