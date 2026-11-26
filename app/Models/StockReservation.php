<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StockReservation extends Model
{
    protected $table = 'stock_reservations';

    protected $guarded = [];

    protected $casts = [
        'qty' => 'integer',
        'expires_at' => 'datetime',
    ];

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }
}
