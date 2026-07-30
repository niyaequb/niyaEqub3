<?php

namespace App\Filament\Resources\MemberEqubGroups\Pages;

use App\Enums\EqubGroupModerationStatus;
use App\Filament\Resources\MemberEqubGroups\MemberEqubGroupResource;
use App\Models\Member;
use App\Services\MemberEqubGroupService;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

class CreateMemberEqubGroup extends CreateRecord
{
    protected static string $resource = MemberEqubGroupResource::class;

    public function getTitle(): string
    {
        return __('filament.member_equb_group.create');
    }

    /**
     * Creation goes through MemberEqubGroupService rather than a plain
     * EqubGroup::create(), so the owner's membership, cohort, contribution
     * lock and end date are built exactly as they are in the mobile app.
     */
    protected function handleRecordCreation(array $data): Model
    {
        $owner = Member::find($data['owner_member_id'] ?? null);

        if (! $owner) {
            throw ValidationException::withMessages([
                'data.owner_member_id' => __('filament.member_equb_group.owner_missing'),
            ]);
        }

        // People picked in the panel are added straight away; the admin is
        // recording a group that already exists in real life.
        $memberIds = array_values(array_filter(
            array_map('intval', (array) ($data['member_ids'] ?? []))
        ));

        $result = app(MemberEqubGroupService::class)->create($owner, $data);

        if (! $result['success']) {
            $message = $result['message'] ?? __('filament.member_equb_group.create_failed');

            Notification::make()->title($message)->danger()->send();

            throw ValidationException::withMessages(['data.name' => $message]);
        }

        $group = $result['group'];

        // Created by an admin, so it does not sit in the approval queue.
        $group->update([
            'moderation_status' => EqubGroupModerationStatus::Approved,
            'approved_at' => now(),
            'approved_by_admin_id' => Auth::id(),
        ]);

        // Invite everyone the admin picked, exactly as the app would.
        if ($memberIds !== []) {
            $added = app(MemberEqubGroupService::class)
                ->addMembersDirectly($group->fresh(), $memberIds);

            if (($added['skipped'] ?? []) !== []) {
                Notification::make()
                    ->title(__('filament.member_equb_group.some_members_skipped'))
                    ->body(implode("\n", $added['skipped']))
                    ->warning()
                    ->send();
            }
        }

        return $group->refresh();
    }

    protected function getCreatedNotificationTitle(): ?string
    {
        return __('filament.member_equb_group.created_notice');
    }

    protected function getRedirectUrl(): string
    {
        return static::getResource()::getUrl('index');
    }
}
