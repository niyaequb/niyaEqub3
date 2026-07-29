<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class EqubDraw extends Model
{
    protected $fillable = [
        'equb_group_id',
        'draw_date',
        'round_number',
        'winners_count',
        'mode',
        'executed_by_admin_id',
        'winner_membership_id',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'draw_date' => 'datetime',
            'winners_count' => 'integer',
            'round_number' => 'integer',
        ];
    }

    public function equbGroup(): BelongsTo
    {
        return $this->belongsTo(EqubGroup::class, 'equb_group_id');
    }

    public function executedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'executed_by_admin_id');
    }

    public function winnerMembership(): BelongsTo
    {
        return $this->belongsTo(EqubMembership::class, 'winner_membership_id');
    }

    /**
     * Every winner of this round. `winner_membership_id` above still points at
     * the first of them, so single-winner code keeps working unchanged.
     */
    public function winners(): HasMany
    {
        return $this->hasMany(EqubDrawWinner::class, 'equb_draw_id')->orderBy('position');
    }

    /** True when this round produced a winner group rather than one winner. */
    public function isGroupDraw(): bool
    {
        return (int) $this->winners_count > 1;
    }
}
