<?php

namespace App\Filament\Resources\Members\Concerns;

use App\Enums\EqubPaymentMethod;
use App\Enums\EqubPaymentStatus;
use App\Models\EqubMembership;
use App\Models\EqubPayment;
use Carbon\Carbon;

trait HasMemberEqubManagement
{
    public ?int $equbSelectedMembershipId = null;

    public string $equbPaymentViewMode = 'list';

    public ?int $equbCalendarYear = null;

    public ?int $equbCalendarMonth = null;

    public ?int $equbModalPaymentId = null;

    public bool $equbModalIsCreate = false;

    public bool $equbShowPaymentModal = false;

    public $equbAmount = '';

    public $equbPaymentDate = '';

    public $equbPaymentMethod = 'manual';

    public function equbSelectMembership(?int $id): void
    {
        $this->equbSelectedMembershipId = $id;
        $this->equbModalPaymentId = null;
        $this->equbModalIsCreate = false;
    }

    public function setEqubPaymentViewMode(string $mode): void
    {
        $this->equbPaymentViewMode = $mode;
    }

    public function getEqubMembershipsProperty()
    {
        $member = $this->getRecord();

        return $member->equbMemberships()
            ->with(['equbGroup.package'])
            ->latest('join_date')
            ->get();
    }

    public function getEqubSelectedMembershipProperty(): ?EqubMembership
    {
        if (! $this->equbSelectedMembershipId) {
            return null;
        }
        $member = $this->getRecord();

        return EqubMembership::query()
            ->where('member_id', $member->id)
            ->where('id', $this->equbSelectedMembershipId)
            ->with(['equbGroup.package', 'payments'])
            ->first();
    }

    /** @return array<int, array{date: Carbon, paid: bool, payment: EqubPayment|null}> */
    public function getEqubDueDatesProperty(): array
    {
        $m = $this->equbSelectedMembership;
        if (! $m) {
            return [];
        }
        $group = $m->equbGroup;
        $start = $group->equb_start_date ?? $m->join_date;
        if (! $start) {
            return [];
        }
        $start = Carbon::parse($start)->startOfDay();
        $freq = (int) $m->contribution_frequency_days ?: 1;
        $end = $group->equb_end_date ? Carbon::parse($group->equb_end_date) : now()->addYears(2);
        $payments = $m->payments()->where('status', EqubPaymentStatus::Paid)->get();
        $dueDates = [];
        $n = 0;
        $max = 365;
        while ($n < $max) {
            $date = $start->copy()->addDays($n * $freq);
            if ($date->gt($end)) {
                break;
            }
            $payment = $payments->first(fn (EqubPayment $p) => $p->payment_date->format('Y-m-d') === $date->format('Y-m-d'));
            $dueDates[] = [
                'date' => $date,
                'paid' => $payment !== null,
                'payment' => $payment,
            ];
            $n++;
        }

        return $dueDates;
    }

    public function getEqubCalendarEventsForFullCalendarProperty(): array
    {
        $dueDates = $this->equbDueDates;
        $today = now()->format('Y-m-d');
        $events = [];
        foreach ($dueDates as $d) {
            $key = $d['date']->format('Y-m-d');
            $paid = $d['paid'];
            $status = $paid ? 'paid' : ($key < $today ? 'unpaid' : 'future');
            $events[] = [
                'id' => 'due-'.$key,
                'title' => $paid ? 'Paid' : ($key < $today ? 'Overdue' : 'Due'),
                'start' => $key,
                'allDay' => true,
                'backgroundColor' => $paid ? '#22c55e' : ($key < $today ? '#ef4444' : '#0ea5e9'),
                'extendedProps' => [
                    'date' => $key,
                    'status' => $status,
                    'paymentId' => $d['payment']?->id,
                ],
            ];
        }

        return $events;
    }

    public function getEqubCalendarEventsForFullCalendar(): array
    {
        return $this->equbCalendarEventsForFullCalendar;
    }

    public function equbOpenPaymentModal(?int $paymentId = null, ?string $dueDate = null): void
    {
        $this->equbModalPaymentId = $paymentId;
        $this->equbModalIsCreate = $paymentId === null;
        $this->equbShowPaymentModal = true;
        if ($this->equbModalIsCreate && $dueDate) {
            $this->equbPaymentDate = $dueDate;
            $this->equbAmount = (string) ($this->equbSelectedMembership?->contribution_amount ?? '');
        } else {
            $this->equbPaymentDate = '';
            $this->equbAmount = '';
        }
        $this->equbPaymentMethod = 'manual';
    }

    public function equbClosePaymentModal(): void
    {
        $this->equbModalPaymentId = null;
        $this->equbModalIsCreate = false;
        $this->equbShowPaymentModal = false;
        $this->equbAmount = '';
        $this->equbPaymentDate = '';
    }

    public function equbSaveManualPayment(): void
    {
        $this->validate([
            'equbAmount' => 'required|numeric|min:0',
            'equbPaymentDate' => 'required|date',
            'equbPaymentMethod' => 'required|in:manual,offline',
        ]);
        $m = $this->equbSelectedMembership;
        $member = $this->getRecord();
        if (! $m || $m->member_id != $member->id) {
            return;
        }
        EqubPayment::create([
            'equb_membership_id' => $m->id,
            'amount' => $this->equbAmount,
            'payment_date' => $this->equbPaymentDate,
            'payment_method' => $this->equbPaymentMethod === 'offline' ? EqubPaymentMethod::Offline : EqubPaymentMethod::Manual,
            'status' => EqubPaymentStatus::Paid,
        ]);
        $this->equbClosePaymentModal();
        $this->dispatch('payment-saved');
    }

    public function getEqubPaymentForModalProperty(): ?EqubPayment
    {
        if ($this->equbModalPaymentId === null) {
            return null;
        }
        $member = $this->getRecord();

        return EqubPayment::query()
            ->where('id', $this->equbModalPaymentId)
            ->whereHas('membership', fn ($q) => $q->where('member_id', $member->id))
            ->with('membership.equbGroup.package')
            ->first();
    }
}
