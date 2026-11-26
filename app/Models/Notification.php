<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Notification extends Model
{
    protected $table = 'notifications';

    protected $guarded = [];

    protected $casts = [
        'data' => 'array',
        'channel_status' => 'array',
        'read_at' => 'datetime',
    ];
}
