<?php

namespace App\Domain\Shipping\Services;

use App\Models\Order;
use App\Models\PlatformSetting;

class VehicleSelector
{
    public const CLASS_MOTORCYCLE = 'motorcycle';
    public const CLASS_CAR = 'car';
    public const DEFAULT_MAX_WEIGHT_MOTORCYCLE_KG = 20.0;

    /**
     * Determine recommended vehicle class based on total weight in kg.
     */
    public function selectVehicle(float $totalWeightKg): array
    {
        $maxWeightMotorcycle = $this->getMaxMotorcycleWeight();

        if ($totalWeightKg <= $maxWeightMotorcycle) {
            return [
                'vehicle_class' => self::CLASS_MOTORCYCLE,
                'max_weight_limit_kg' => $maxWeightMotorcycle,
                'allowed_courier_types' => ['instant', 'same_day'],
                'allowed_vehicle_types' => ['motorcycle'],
                'reason' => 'Total muatan <= 20 kg, aman menggunakan armada motor instan.',
            ];
        }

        return [
            'vehicle_class' => self::CLASS_CAR,
            'max_weight_limit_kg' => 1000.0,
            'allowed_courier_types' => ['car', 'mpv', 'van', 'pickup'],
            'allowed_vehicle_types' => ['car', 'van', 'pickup'],
            'reason' => 'Total muatan > 20 kg melebihi batas kurir motor. Wajib menggunakan armada mobil/pick-up demi keselamatan.',
        ];
    }

    /**
     * Calculate total weight of an order in kilograms.
     */
    public function calculateOrderWeight(Order $order): float
    {
        $totalWeight = 0.0;

        foreach ($order->items as $item) {
            // Check snapshot or weight_per_unit_kg
            $weightPerUnit = (float) ($item->weight_per_unit_kg ?? 1.0);
            $totalWeight += ((float) $item->qty) * $weightPerUnit;
        }

        return round($totalWeight, 2);
    }

    protected function getMaxMotorcycleWeight(): float
    {
        $setting = PlatformSetting::where('key', 'shipping.max_weight_instant_courier_kg')->first();
        if ($setting && isset($setting->value['max_kg'])) {
            return (float) $setting->value['max_kg'];
        }

        return self::DEFAULT_MAX_WEIGHT_MOTORCYCLE_KG;
    }
}
