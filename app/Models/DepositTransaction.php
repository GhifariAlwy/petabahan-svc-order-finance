<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DepositTransaction extends Model
{
    public $timestamps = false;
    protected $table = 'deposit_transactions';

    protected $guarded = [];

    protected $casts = [
        'amount' => 'integer',
        'balance_after' => 'integer',
        'created_at' => 'datetime',
    ];

    public function wallet(): BelongsTo
    {
        return $this->belongsTo(DepositWallet::class, 'wallet_id');
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class, 'order_id');
    }
}
