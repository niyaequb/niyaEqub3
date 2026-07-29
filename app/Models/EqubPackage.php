<?php

namespace App\Models;

use App\Enums\EqubDurationType;
use App\Enums\EqubPackageType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class EqubPackage extends Model
{
    protected $fillable = [
        'name',
        'type',
        'fixed_contribution_amount',
        'min_contribution_amount',
        'max_contribution_amount',
        'contribution_frequency_days',
        'duration_type',
        'duration_days',
        'max_members',
        'terms_content',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'type' => EqubPackageType::class,
            'duration_type' => EqubDurationType::class,
            'fixed_contribution_amount' => 'decimal:2',
            'min_contribution_amount' => 'decimal:2',
            'max_contribution_amount' => 'decimal:2',
            'is_active' => 'boolean',
        ];
    }

    public function groups(): HasMany
    {
        return $this->hasMany(EqubGroup::class, 'equb_package_id');
    }

    public function isNormal(): bool
    {
        return $this->type === EqubPackageType::Normal;
    }

    public function isFlexible(): bool
    {
        return $this->type === EqubPackageType::Flexible;
    }
}
