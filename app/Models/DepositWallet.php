<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class DepositWallet extends Model
{
    protected $table = 'deposit_wallets';

    protected $guarded = [];

    protected $casts = [
        'balance' => 'integer',
        'min_required_balance' => 'integer',
    ];

    public function transactions(): HasMany
    {
        return $this->hasMany(DepositTransaction::class, 'wallet_id');
    }
}
