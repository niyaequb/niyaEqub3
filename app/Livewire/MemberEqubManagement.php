<?php

namespace App\Livewire;

use App\Enums\EqubPaymentMethod;
use App\Enums\EqubPaymentStatus;
use App\Models\EqubMembership;
use App\Models\EqubPayment;
use App\Models\Member;
use Carbon\Carbon;
use Illuminate\Contracts\View\View;
use Livewire\Component;
use Livewire\WithPagination;

class MemberEqubManagement extends Component
{
    use WithPagination;

    public Member $member;

    public ?int $selectedMembershipId = null;

    public string $paymentViewMode = 'list'; // 'list' | 'calendar'

    public ?int $calendarYear = null;

    public ?int $calendarMonth = null;

    public ?int $modalPaymentId = null;

    public bool $modalIsCreate = false;

    public bool $showPaymentModal = false;

    public $amount = '';

    public $payment_date = '';

    public $payment_method = 'manual';

    protected $queryString = ['selectedMembershipId' => ['except' => null]];

    public function mount(Member $member): void
    {
        $this->member = $member->loadMissing('equbMemberships.equbGroup.package');
        if ($this->calendarYear === null) {
            $this->calendarYear = (int) now()->format('Y');
        }
        if ($this->calendarMonth === null) {
            $this->calendarMonth = (int) now()->format('n');
        }
    }

    public function selectMembership(?int $id): void
    {
        $this->selectedMembershipId = $id;
        $this->resetPage();
        $this->modalPaymentId = null;
        $this->modalIsCreate = false;
    }

    public function setPaymentViewMode(string $mode): void
    {
        $this->paymentViewMode = $mode;
    }

    public function getMembershipsProperty()
    {
        return $this->member->equbMemberships()
            ->with(['equbGroup.package'])
            ->latest('join_date')
            ->get();
    }

    public function getSelectedMembershipProperty(): ?EqubMembership
    {
        if (! $this->selectedMembershipId) {
            return null;
        }

        return EqubMembership::query()
            ->where('member_id', $this->member->id)
            ->where('id', $this->selectedMembershipId)
            ->with(['equbGroup.package', 'payments'])
            ->first();
    }

    /**
     * Due dates for the selected membership: start_date + n * frequency_days.
     * Limited to ~2 years for performance.
     *
     * @return array<int, array{date: Carbon, paid: bool, payment: EqubPayment|null}>
     */
    public function getDueDatesProperty(): array
    {
        $m = $this->selectedMembership;
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

    /**
     * Calendar cells for the current month: list of days with due-date info.
     *
     * @return array<int, array{day: int, isCurrentMonth: bool, dueStatus: string|null, duePayment: mixed}>
     */
    public function getCalendarDaysProperty(): array
    {
        $m = $this->selectedMembership;
        $year = $this->calendarYear;
        $month = $this->calendarMonth;
        $start = Carbon::createFromDate($year, $month, 1);
        $end = $start->copy()->endOfMonth();
        $dueDates = $this->dueDates;
        $dueMap = [];
        $today = now()->format('Y-m-d');
        foreach ($dueDates as $d) {
            $key = $d['date']->format('Y-m-d');
            $dueMap[$key] = $d;
        }
        $days = [];
        $firstWeekday = (int) $start->format('N');
        for ($i = 1; $i < $firstWeekday; $i++) {
            $prev = $start->copy()->subDays($firstWeekday - $i);
            $key = $prev->format('Y-m-d');
            $status = null;
            if (isset($dueMap[$key])) {
                $status = $dueMap[$key]['paid'] ? 'paid' : ($key < $today ? 'unpaid' : 'future');
            }
            $days[] = [
                'day' => (int) $prev->format('j'),
                'date' => $key,
                'isCurrentMonth' => false,
                'dueStatus' => $status,
                'duePayment' => $dueMap[$key]['payment'] ?? null,
            ];
        }
        for ($d = 1; $d <= $end->day; $d++) {
            $date = Carbon::createFromDate($year, $month, $d);
            $key = $date->format('Y-m-d');
            $status = null;
            if (isset($dueMap[$key])) {
                $status = $dueMap[$key]['paid'] ? 'paid' : ($key < $today ? 'unpaid' : 'future');
            }
            $days[] = [
                'day' => $d,
                'date' => $key,
                'isCurrentMonth' => true,
                'dueStatus' => $status,
                'duePayment' => $dueMap[$key]['payment'] ?? null,
            ];
        }
        $remaining = 42 - count($days);
        for ($i = 1; $i <= $remaining; $i++) {
            $next = $end->copy()->addDays($i);
            $key = $next->format('Y-m-d');
            $status = null;
            if (isset($dueMap[$key])) {
                $status = $dueMap[$key]['paid'] ? 'paid' : ($key < $today ? 'unpaid' : 'future');
            }
            $days[] = [
                'day' => (int) $next->format('j'),
                'date' => $key,
                'isCurrentMonth' => false,
                'dueStatus' => $status,
                'duePayment' => $dueMap[$key]['payment'] ?? null,
            ];
        }

        return array_slice($days, 0, 42);
    }

    public function prevMonth(): void
    {
        $d = Carbon::createFromDate($this->calendarYear, $this->calendarMonth, 1)->subMonth();
        $this->calendarYear = (int) $d->format('Y');
        $this->calendarMonth = (int) $d->format('n');
    }

    public function nextMonth(): void
    {
        $d = Carbon::createFromDate($this->calendarYear, $this->calendarMonth, 1)->addMonth();
        $this->calendarYear = (int) $d->format('Y');
        $this->calendarMonth = (int) $d->format('n');
    }

    public function goToToday(): void
    {
        $this->calendarYear = (int) now()->format('Y');
        $this->calendarMonth = (int) now()->format('n');
    }

    public function openPaymentModal(?int $paymentId = null, ?string $dueDate = null): void
    {
        $this->modalPaymentId = $paymentId;
        $this->modalIsCreate = $paymentId === null;
        $this->showPaymentModal = true;
        if ($this->modalIsCreate && $dueDate) {
            $this->payment_date = $dueDate;
            $this->amount = (string) ($this->selectedMembership?->contribution_amount ?? '');
        } else {
            $this->payment_date = '';
            $this->amount = '';
        }
        $this->payment_method = 'manual';
    }

    public function closePaymentModal(): void
    {
        $this->modalPaymentId = null;
        $this->modalIsCreate = false;
        $this->showPaymentModal = false;
        $this->amount = '';
        $this->payment_date = '';
    }

    public function saveManualPayment(): void
    {
        $this->validate([
            'amount' => 'required|numeric|min:0',
            'payment_date' => 'required|date',
            'payment_method' => 'required|in:manual,offline',
        ]);
        $m = $this->selectedMembership;
        if (! $m || $m->member_id !== $this->member->id) {
            return;
        }
        EqubPayment::create([
            'equb_membership_id' => $m->id,
            'amount' => $this->amount,
            'payment_date' => $this->payment_date,
            'payment_method' => $this->payment_method === 'offline' ? EqubPaymentMethod::Offline : EqubPaymentMethod::Manual,
            'status' => EqubPaymentStatus::Paid,
        ]);
        $this->closePaymentModal();
        $this->dispatch('payment-saved');
    }

    public function getPaymentForModalProperty(): ?EqubPayment
    {
        if ($this->modalPaymentId === null) {
            return null;
        }

        return EqubPayment::query()
            ->where('id', $this->modalPaymentId)
            ->whereHas('membership', fn ($q) => $q->where('member_id', $this->member->id))
            ->with('membership.equbGroup.package')
            ->first();
    }

    /**
     * Events in FullCalendar format for the selected membership's due dates.
     *
     * @return array<int, array{id: string, title: string, start: string, allDay: bool, backgroundColor: string, extendedProps: array}>
     */
    public function getCalendarEventsForFullCalendarProperty(): array
    {
        $dueDates = $this->dueDates;
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

    /** Return calendar events for FullCalendar (callable from JS). */
    public function getCalendarEventsForFullCalendar(): array
    {
        return $this->calendarEventsForFullCalendar;
    }

    public function render(): View
    {
        return view('livewire.member-equb-management');
    }
}
