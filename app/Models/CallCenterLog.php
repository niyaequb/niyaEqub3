<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CallCenterLog extends Model
{
    /** Outcome code => label shown to the call center. */
    public const OUTCOMES = [
        'promised_to_pay' => 'Promised to pay',
        'paid_already' => 'Says already paid',
        'will_pay_today' => 'Will pay today',
        'financial_problem' => 'Financial problem',
        'wants_to_leave' => 'Wants to leave the Equb',
        'no_answer' => 'No answer',
        'phone_off' => 'Phone switched off',
        'wrong_number' => 'Wrong number',
        'call_back_later' => 'Asked to call back later',
        'refused' => 'Refused to pay',
        'other' => 'Other',
    ];

    public const OUTCOME_COLORS = [
        'promised_to_pay' => 'success',
        'paid_already' => 'info',
        'will_pay_today' => 'success',
        'financial_problem' => 'warning',
        'wants_to_leave' => 'danger',
        'no_answer' => 'gray',
        'phone_off' => 'gray',
        'wrong_number' => 'danger',
        'call_back_later' => 'warning',
        'refused' => 'danger',
        'other' => 'gray',
    ];

    protected $fillable = [
        'equb_membership_id',
        'member_id',
        'called_by',
        'outcome',
        'promised_payment_date',
        'feedback',
        'arrears_at_call',
        'days_unpaid_at_call',
    ];

    protected function casts(): array
    {
        return [
            'promised_payment_date' => 'date',
            'arrears_at_call' => 'decimal:2',
        ];
    }

    public function membership(): BelongsTo
    {
        return $this->belongsTo(EqubMembership::class, 'equb_membership_id');
    }

    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class);
    }

    public function caller(): BelongsTo
    {
        return $this->belongsTo(User::class, 'called_by');
    }

    public function outcomeLabel(): string
    {
        return self::OUTCOMES[$this->outcome] ?? ucfirst(str_replace('_', ' ', (string) $this->outcome));
    }
}
