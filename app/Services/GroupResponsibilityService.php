<?php

namespace App\Services;

use App\Enums\EqubGroupStatus;
use App\Enums\EqubMembershipStatus;
use App\Enums\EqubPaymentStatus;
use App\Models\EqubGroup;
use App\Models\EqubMembership;
use App\Models\Member;
use Illuminate\Support\Collection;

/**
 * "My Responsibility People" — seats a member holds in a Group Equb on behalf
 * of someone who has no Niya account.
 *
 * The rule the whole feature turns on: a seat is counted like any other member
 * (head-count, pot, rounds, draw eligibility) but every obligation attached to
 * it belongs to the sponsor who added it. Nobody is invited, nobody has to
 * accept, and nobody but the sponsor can be chased for the money.
 *
 * Seats are stored as memberships with a null member_id — see
 * EqubMembership's "My Responsibility People" section — so the ledger, the
 * draw engine and the payment schedule count them correctly without knowing
 * this class exists.
 */
class GroupResponsibilityService
{
    public function __construct(
        protected EqubMembershipService $membershipService,
        protected SmsService $smsService,
    ) {}

    /**
     * How many seats one sponsor may hold in a single group.
     *
     * There is a cap because a seat is a real financial obligation the sponsor
     * takes on alone: ten seats in a daily Equb is ten contributions every
     * single day. The limit is a guard rail against someone quietly signing up
     * for more than they can carry, not an arbitrary product restriction.
     */
    public function seatLimit(): int
    {
        return max(1, (int) config('services.equb.max_responsibility_people', 10));
    }

    // -----------------------------------------------------------------
    // Reads
    // -----------------------------------------------------------------

    /**
     * Seats in this group. The owner sees every seat in the circle; anyone
     * else sees only the ones they are answerable for.
     *
     * @return Collection<int, EqubMembership>
     */
    public function forGroup(EqubGroup $group, ?Member $viewer, bool $onlyMine = false): Collection
    {
        $query = $group->memberships()
            ->responsibilitySeats()
            ->with(['sponsor.user'])
            ->withSum(['payments as paid_total' => fn ($q) => $q->where('status', EqubPaymentStatus::Paid)], 'amount')
            ->withCount(['payments as paid_rounds' => fn ($q) => $q->where('status', EqubPaymentStatus::Paid)]);

        $seesEverything = $viewer !== null && $group->isOwnedBy($viewer->id) && ! $onlyMine;

        if (! $seesEverything) {
            $query->where('sponsor_member_id', $viewer?->id ?? 0);
        }

        return $query->orderBy('id')->get();
    }

    /** Seats [$sponsor] is answerable for in this group. */
    public function countFor(EqubGroup $group, Member $sponsor): int
    {
        return $group->memberships()
            ->responsibilitySeats()
            ->sponsoredBy($sponsor->id)
            ->whereIn('status', [EqubMembershipStatus::Active, EqubMembershipStatus::Completed])
            ->count();
    }

    // -----------------------------------------------------------------
    // Permission
    // -----------------------------------------------------------------

    /**
     * The creator can always add seats. Anyone else needs the group to allow
     * member invites — the same switch that decides whether a member may bring
     * other people in at all, because a seat is exactly that with the sponsor
     * keeping the bill.
     */
    public function canManage(EqubGroup $group, ?Member $member): bool
    {
        if (! $member) {
            return false;
        }

        if ($group->isOwnedBy($member->id)) {
            return true;
        }

        if (! $group->allow_member_invites) {
            return false;
        }

        return $group->memberships()
            ->where('member_id', $member->id)
            ->whereIn('status', [EqubMembershipStatus::Active, EqubMembershipStatus::Completed])
            ->exists();
    }

    /** Whoever removes a seat must either own the group or sponsor the seat. */
    public function canManageSeat(EqubGroup $group, EqubMembership $seat, ?Member $member): bool
    {
        if (! $member) {
            return false;
        }

        if ((int) $seat->sponsor_member_id === (int) $member->id) {
            return true;
        }

        return $group->isOwnedBy($member->id);
    }

    // -----------------------------------------------------------------
    // Writes
    // -----------------------------------------------------------------

    /**
     * Add one person the sponsor is answerable for.
     *
     * @param  array{name: string, phone?: string|null, relation?: string|null, note?: string|null}  $person
     * @return array{success: bool, message: string, membership?: EqubMembership}
     */
    public function add(EqubGroup $group, Member $sponsor, array $person): array
    {
        if (! $this->canManage($group, $sponsor)) {
            return ['success' => false, 'message' => 'Only the group creator can add people to this Equb.'];
        }

        if (in_array($group->status, [EqubGroupStatus::Completed, EqubGroupStatus::Cancelled], true)) {
            return ['success' => false, 'message' => 'This Equb is closed.'];
        }

        $name = trim((string) ($person['name'] ?? ''));

        if ($name === '') {
            return ['success' => false, 'message' => 'Give this person a name.'];
        }

        $limit = $this->seatLimit();

        if ($this->countFor($group, $sponsor) >= $limit) {
            return [
                'success' => false,
                'message' => "You can be responsible for up to {$limit} people in one Equb. "
                    .'Remove someone first, or ask another member to take them on.',
            ];
        }

        // Two seats with the same name under the same sponsor is nearly always
        // a double tap, and the cost of getting it wrong is a second
        // contribution every round that nobody meant to take on.
        if ($this->hasSeatNamed($group, $sponsor, $name)) {
            return [
                'success' => false,
                'message' => $name.' is already on your list for this Equb.',
            ];
        }

        $person['name'] = $name;
        $person['phone'] = $this->normalisePhone($person['phone'] ?? null);

        $result = $this->membershipService->createResponsibilitySeat($group, $sponsor, $person);

        if (! $result['success']) {
            return ['success' => false, 'message' => $result['message'] ?? 'Could not add this person.'];
        }

        return [
            'success' => true,
            'message' => $name.' was added. Their contributions are yours to pay.',
            'membership' => $result['membership'],
        ];
    }

    /**
     * Add several at once — what the create-group screen sends.
     *
     * Partial success is the right behaviour here: a group is being created in
     * the same request, so failing the whole thing because the fourth name was
     * a duplicate would throw away the group as well. Whatever could not be
     * added comes back in `skipped` for the caller to show.
     *
     * @param  array<int, array<string, mixed>>  $people
     * @return array{added: int, skipped: array<int, string>}
     */
    public function addMany(EqubGroup $group, Member $sponsor, array $people): array
    {
        $added = 0;
        $skipped = [];

        foreach ($people as $person) {
            if (! is_array($person)) {
                continue;
            }

            $result = $this->add($group, $sponsor, $person);

            if ($result['success']) {
                $added++;

                continue;
            }

            $skipped[] = $result['message'];
        }

        return ['added' => $added, 'skipped' => $skipped];
    }

    /**
     * Correct a name, phone or relation. Nothing about money can be edited —
     * the contribution comes from the Equb and the seat has already been
     * counted into the pot.
     *
     * @return array{success: bool, message: string, membership?: EqubMembership}
     */
    public function update(EqubMembership $seat, array $data): array
    {
        if (! $seat->isResponsibilitySeat()) {
            return ['success' => false, 'message' => 'That is a member of the Equb, not a person you are responsible for.'];
        }

        $changes = [];

        if (array_key_exists('name', $data)) {
            $name = trim((string) $data['name']);

            if ($name === '') {
                return ['success' => false, 'message' => 'Give this person a name.'];
            }

            $changes['responsibility_name'] = $name;
        }

        foreach (['phone' => 'responsibility_phone', 'relation' => 'responsibility_relation', 'note' => 'responsibility_note'] as $input => $column) {
            if (array_key_exists($input, $data)) {
                $value = $data[$input] === null ? null : trim((string) $data[$input]);
                $changes[$column] = ($value === '' ? null : $value);
            }
        }

        if ($changes === []) {
            return ['success' => true, 'message' => 'Nothing to change.', 'membership' => $seat];
        }

        if (isset($changes['responsibility_phone'])) {
            $changes['responsibility_phone'] = $this->normalisePhone($changes['responsibility_phone']);
        }

        $seat->update($changes);

        return [
            'success' => true,
            'message' => 'Details updated.',
            'membership' => $seat->fresh(['sponsor.user']),
        ];
    }

    /**
     * Take a seat back out of the circle.
     *
     * Guarded by exactly the same rule as a member leaving: once the seat has
     * contributed or been paid out, that money belongs to the other members
     * and cannot be released by deleting a row. See
     * EqubMembership::exitBlockReason().
     *
     * @return array{success: bool, message: string}
     */
    public function remove(EqubGroup $group, EqubMembership $seat, Member $actor): array
    {
        if (! $seat->isResponsibilitySeat() || (int) $seat->equb_group_id !== (int) $group->id) {
            return ['success' => false, 'message' => 'That person is not on this Equb.'];
        }

        if (! $this->canManageSeat($group, $seat, $actor)) {
            return ['success' => false, 'message' => 'Only the person responsible for them can remove them.'];
        }

        $name = $seat->displayName();
        $blocked = $seat->exitBlockReason();

        if ($blocked !== null) {
            // exitBlockReason() is written in the second person for a member
            // leaving their own Equb. Restate it about the seat, because the
            // sponsor is being told about someone else.
            return [
                'success' => false,
                'message' => $seat->hasReceivedPayout()
                    ? "{$name} has already received a payout from this Equb, so their place cannot be removed. "
                        .'Contact support if there is a dispute.'
                    : "{$name} has already contributed to this Equb. Contact support to arrange a refund first.",
            ];
        }

        $result = $this->membershipService->leaveEqub($seat);

        return [
            'success' => (bool) $result['success'],
            'message' => $result['success']
                ? "{$name} was removed from this Equb."
                : ($result['message'] ?? 'Could not remove this person.'),
        ];
    }

    // -----------------------------------------------------------------
    // Internals
    // -----------------------------------------------------------------

    protected function hasSeatNamed(EqubGroup $group, Member $sponsor, string $name): bool
    {
        return $group->memberships()
            ->responsibilitySeats()
            ->sponsoredBy($sponsor->id)
            ->whereIn('status', [EqubMembershipStatus::Active, EqubMembershipStatus::Completed])
            ->whereRaw('LOWER(responsibility_name) = ?', [mb_strtolower($name)])
            ->exists();
    }

    /**
     * The phone is optional and purely a contact detail — it never creates an
     * account and never receives an invitation. Stored in the same +2519…
     * shape as every other number so the two are comparable.
     */
    protected function normalisePhone(?string $phone): ?string
    {
        $phone = trim((string) $phone);

        if ($phone === '') {
            return null;
        }

        try {
            return $this->smsService->formatPhoneNumber($phone);
        } catch (\Throwable) {
            return $phone;
        }
    }
}
