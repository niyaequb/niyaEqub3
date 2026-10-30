<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EqubTermination extends Model
{
    public const REASONS = [
        'non_payment' => 'Did not pay',
        'unreachable' => 'Could not be reached',
        'member_request' => 'Member asked to leave',
        'fraud' => 'Fraud / false information',
        'other' => 'Other',
    ];

    protected $fillable = [
        'equb_membership_id',
        'member_id',
        'terminated_by',
        'reason',
        'note',
        'arrears_at_termination',
        'paid_at_termination',
        'days_unpaid',
        'had_won',
        'reinstated_at',
        'reinstated_by',
    ];

    protected function casts(): array
    {
        return [
            'arrears_at_termination' => 'decimal:2',
            'paid_at_termination' => 'decimal:2',
            'had_won' => 'boolean',
            'reinstated_at' => 'datetime',
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

    public function terminatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'terminated_by');
    }

    public function reinstatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reinstated_by');
    }

    public function reasonLabel(): string
    {
        return self::REASONS[$this->reason] ?? ucfirst(str_replace('_', ' ', (string) $this->reason));
    }
}
