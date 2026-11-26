<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CommissionRule extends Model
{
    protected $table = 'commission_rules';

    protected $guarded = [];

    protected $casts = [
        'min_amount' => 'integer',
        'max_amount' => 'integer',
        'total_commission' => 'integer',
        'merchant_share' => 'integer',
        'supplier_share' => 'integer',
        'is_active' => 'boolean',
        'effective_from' => 'datetime',
    ];
}
