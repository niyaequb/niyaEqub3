<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EqubDrawWinner extends Model
{
    protected $fillable = [
        'equb_draw_id',
        'equb_membership_id',
        'position',
        'amount_won',
    ];

    protected function casts(): array
    {
        return [
            'amount_won' => 'decimal:2',
            'position' => 'integer',
        ];
    }

    public function draw(): BelongsTo
    {
        return $this->belongsTo(EqubDraw::class, 'equb_draw_id');
    }

    public function membership(): BelongsTo
    {
        return $this->belongsTo(EqubMembership::class, 'equb_membership_id');
    }
}
