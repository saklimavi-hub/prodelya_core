<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class SystemHeartbeat extends Model
{
    use HasFactory;

    public const STATUS_SEEN = 'seen';
    public const STATUS_SUCCESS = 'success';
    public const STATUS_FAILURE = 'failure';

    protected $fillable = [
        'key',
        'label',
        'status',
        'last_seen_at',
        'last_success_at',
        'last_failure_at',
        'failure_count',
        'meta_json',
    ];

    protected $casts = [
        'last_seen_at' => 'datetime',
        'last_success_at' => 'datetime',
        'last_failure_at' => 'datetime',
        'meta_json' => 'array',
    ];
}
