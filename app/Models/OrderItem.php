<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OrderItem extends Model
{
    protected $table = 'order_items';

    protected $guarded = [];

    protected $casts = [
        'qty' => 'integer',
        'unit_price_applied' => 'integer',
        'line_total' => 'integer',
        'is_available_confirmed' => 'boolean',
    ];

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }
}
