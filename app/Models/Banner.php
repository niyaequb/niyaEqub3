<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Banner extends Model
{
    protected $fillable = [
        'image_path',
        'title',
        'subtitle',
        'link',
        'is_active'
    ];

    protected $casts = [
        'is_active' => 'boolean'
    ];
}
