<?php

namespace App\Models;

use App\Enums\EqubInvitationStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class EqubGroupInvitation extends Model
{
    protected $fillable = [
        'equb_group_id',
        'invited_by_member_id',
        'member_id',
        'phone',
        'status',
        'token',
        'message',
        'responded_at',
        'expires_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => EqubInvitationStatus::class,
            'responded_at' => 'datetime',
            'expires_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (EqubGroupInvitation $invitation): void {
            if (empty($invitation->token)) {
                $invitation->token = (string) Str::uuid();
            }

            if (empty($invitation->expires_at)) {
                $invitation->expires_at = now()->addDays(
                    (int) config('services.equb.invitation_ttl_days', 14)
                );
            }
        });
    }

    public function equbGroup(): BelongsTo
    {
        return $this->belongsTo(EqubGroup::class, 'equb_group_id');
    }

    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class, 'member_id');
    }

    public function invitedBy(): BelongsTo
    {
        return $this->belongsTo(Member::class, 'invited_by_member_id');
    }

    public function isPending(): bool
    {
        return $this->status === EqubInvitationStatus::Pending && ! $this->isExpired();
    }

    public function isExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }

    public function scopePending($query)
    {
        return $query->where('status', EqubInvitationStatus::Pending)
            ->where(function ($q) {
                $q->whereNull('expires_at')->orWhere('expires_at', '>', now());
            });
    }
}
