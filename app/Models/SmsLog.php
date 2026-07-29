<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class SmsLog extends Model
{
    use HasFactory;

    protected $fillable = [
        'phone',
        'message',
        'status',
        'response',
        'provider',
        'reference',
        'sendable_type',
        'sendable_id',
    ];

    protected $casts = [
        'response' => 'array',
    ];

    public function sendable(): MorphTo
    {
        return $this->morphTo();
    }

    public function isSuccess(): bool
    {
        return $this->status === 'success';
    }

    public function isError(): bool
    {
        return $this->status === 'error';
    }

    public function isPending(): bool
    {
        return $this->status === 'pending';
    }
}
