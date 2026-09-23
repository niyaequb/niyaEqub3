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

        // How the result was arrived at. See the add_audit_fields migration:
        // with the seed and the snapshot, a round can be recomputed from
        // scratch and checked against what was recorded.
        'random_seed',
        'pool_size',
        'excluded_count',
        'total_weight',
        'winner_weight',
        'winner_odds',
        'eligibility_snapshot',
    ];

    protected function casts(): array
    {
        return [
            'draw_date' => 'datetime',
            'winners_count' => 'integer',
            'round_number' => 'integer',
            'pool_size' => 'integer',
            'excluded_count' => 'integer',
            'total_weight' => 'decimal:4',
            'winner_weight' => 'decimal:4',
            'winner_odds' => 'decimal:3',
            'eligibility_snapshot' => 'array',
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

    /** Recorded with enough detail that the result can be re-derived. */
    public function isAuditable(): bool
    {
        return filled($this->random_seed) && filled($this->eligibility_snapshot);
    }

    /**
     * The entries as they stood when this round ran.
     *
     * @return \Illuminate\Support\Collection<int, array<string, mixed>>
     */
    public function snapshotEntries(): \Illuminate\Support\Collection
    {
        return collect($this->eligibility_snapshot['entries'] ?? []);
    }

    /** The rules that were in force at the time, not the ones in force now. */
    public function snapshotRules(): array
    {
        return (array) ($this->eligibility_snapshot['rules'] ?? []);
    }

    /**
     * Total money handed out in this round, across every winner.
     *
     * Summed off the loaded relation rather than with a fresh aggregate query,
     * because the draws table reads this on every row: as a query it is one
     * round trip per row on a page of fifty, and the relation is eager-loaded
     * by the resource anyway.
     */
    public function totalAwarded(): float
    {
        return (float) $this->winners->sum('amount_won');
    }
}
