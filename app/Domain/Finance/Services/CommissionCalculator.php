<?php

namespace App\Domain\Finance\Services;

use App\Models\CommissionRule;
use Illuminate\Support\Facades\DB;

class CommissionCalculator
{
    /**
     * Calculate 50:50 flat tiered commission from subtotal materials
     *
     * Tier 1: 0 - 349,999 -> Total 7,000 (Merchant 3,500, Supplier 3,500)
     * Tier 2: 350,000 - 500,000 -> Total 10,000 (Merchant 5,000, Supplier 5,000)
     * Tier 3: 500,001 - 1,000,000 -> Total 13,000 (Merchant 6,500, Supplier 6,500)
     * Tier 4: >= 1,000,001 -> Total 15,000 (Merchant 7,500, Supplier 7,500)
     *
     * @return array{total_commission: int, merchant_fee: int, supplier_commission: int, tier_id: ?int}
     */
    public function calculate(int $subtotalMaterials): array
    {
        // Try reading active rules from database
        try {
            $rule = CommissionRule::query()
                ->where('is_active', true)
                ->where('min_amount', '<=', $subtotalMaterials)
                ->where(function ($q) use ($subtotalMaterials) {
                    $q->whereNull('max_amount')
                      ->orWhere('max_amount', '>=', $subtotalMaterials);
                })
                ->orderByDesc('min_amount')
                ->first();

            if ($rule) {
                return [
                    'total_commission' => (int) $rule->total_commission,
                    'merchant_fee' => (int) $rule->merchant_share,
                    'supplier_commission' => (int) $rule->supplier_share,
                    'tier_id' => (int) $rule->id,
                ];
            }
        } catch (\Throwable $e) {
            // Fallback to in-memory tier calculation if DB query fails
        }

        return $this->calculateFallback($subtotalMaterials);
    }

    /**
     * Hard-coded fallback for boundary safety
     */
    public function calculateFallback(int $subtotalMaterials): array
    {
        if ($subtotalMaterials < 350000) {
            return [
                'total_commission' => 7000,
                'merchant_fee' => 3500,
                'supplier_commission' => 3500,
                'tier_id' => 1,
            ];
        }

        if ($subtotalMaterials <= 500000) {
            return [
                'total_commission' => 10000,
                'merchant_fee' => 5000,
                'supplier_commission' => 5000,
                'tier_id' => 2,
            ];
        }

        if ($subtotalMaterials <= 1000000) {
            return [
                'total_commission' => 13000,
                'merchant_fee' => 6500,
                'supplier_commission' => 6500,
                'tier_id' => 3,
            ];
        }

        return [
            'total_commission' => 15000,
            'merchant_fee' => 7500,
            'supplier_commission' => 7500,
            'tier_id' => 4,
        ];
    }
}
