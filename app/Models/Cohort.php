<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Cohort extends Model
{
    protected $fillable = [
        'equb_group_id',
        'name',
        'month',
        'year',
        'win_weight',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'win_weight' => 'decimal:2',
    ];

    public function equbGroup(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(EqubGroup::class);
    }

    public function memberships(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(EqubMembership::class);
    }
}
