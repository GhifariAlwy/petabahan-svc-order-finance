<?php

use App\Domain\Finance\Services\CommissionCalculator;

it('correctly calculates 50:50 commission across all 6 boundary points', function () {
    $calculator = app(CommissionCalculator::class);

    // 1. Boundary 1: Rp 349.999 -> Tier 1 (7.000 = 3.500 + 3.500)
    $res1 = $calculator->calculate(349999);
    expect($res1['total_commission'])->toBe(7000)
        ->and($res1['merchant_fee'])->toBe(3500)
        ->and($res1['supplier_commission'])->toBe(3500);

    // 2. Boundary 2: Rp 350.000 -> Tier 2 (10.000 = 5.000 + 5.000)
    $res2 = $calculator->calculate(350000);
    expect($res2['total_commission'])->toBe(10000)
        ->and($res2['merchant_fee'])->toBe(5000)
        ->and($res2['supplier_commission'])->toBe(5000);

    // 3. Boundary 3: Rp 500.000 -> Tier 2 (10.000 = 5.000 + 5.000)
    $res3 = $calculator->calculate(500000);
    expect($res3['total_commission'])->toBe(10000)
        ->and($res3['merchant_fee'])->toBe(5000)
        ->and($res3['supplier_commission'])->toBe(5000);

    // 4. Boundary 4: Rp 500.001 -> Tier 3 (13.000 = 6.500 + 6.500)
    $res4 = $calculator->calculate(500001);
    expect($res4['total_commission'])->toBe(13000)
        ->and($res4['merchant_fee'])->toBe(6500)
        ->and($res4['supplier_commission'])->toBe(6500);

    // 5. Boundary 5: Rp 1.000.000 -> Tier 3 (13.000 = 6.500 + 6.500)
    $res5 = $calculator->calculate(1000000);
    expect($res5['total_commission'])->toBe(13000)
        ->and($res5['merchant_fee'])->toBe(6500)
        ->and($res5['supplier_commission'])->toBe(6500);

    // 6. Boundary 6: Rp 1.000.001 -> Tier 4 (15.000 = 7.500 + 7.500)
    $res6 = $calculator->calculate(1000001);
    expect($res6['total_commission'])->toBe(15000)
        ->and($res6['merchant_fee'])->toBe(7500)
        ->and($res6['supplier_commission'])->toBe(7500);
});
