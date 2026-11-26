<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Order extends Model
{
    protected $table = 'orders';

    protected $guarded = [];

    protected $casts = [
        'subtotal_materials' => 'integer',
        'merchant_fee' => 'integer',
        'supplier_commission' => 'integer',
        'total_commission' => 'integer',
        'shipping_cost_estimate' => 'integer',
        'shipping_cost_actual' => 'integer',
        'grand_total_merchant' => 'integer',
        'amount_paid' => 'integer',
        'total_weight_kg' => 'float',
        'accepted_at' => 'datetime',
        'booking_expires_at' => 'datetime',
        'payment_expires_at' => 'datetime',
        'settlement_expires_at' => 'datetime',
        'completed_at' => 'datetime',
    ];

    public function items(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(OrderPayment::class);
    }

    public function stockReservations(): HasMany
    {
        return $this->hasMany(StockReservation::class);
    }

    public function statusLogs(): HasMany
    {
        return $this->hasMany(OrderStatusLog::class);
    }

    public function disputes(): HasMany
    {
        return $this->hasMany(Dispute::class);
    }

    public function shipment(): HasOne
    {
        return $this->hasOne(Shipment::class);
    }
}
