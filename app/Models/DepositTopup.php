<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DepositTopup extends Model
{
    protected $table = 'deposit_topups';

    protected $guarded = [];

    protected $casts = [
        'amount' => 'integer',
        'approved_at' => 'datetime',
    ];
}
