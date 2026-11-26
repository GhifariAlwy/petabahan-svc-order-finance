<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class CommissionRuleSeeder extends Seeder
{
    public function run(): void
    {
        DB::table('commission_rules')->delete();

        DB::table('commission_rules')->insert([
            [
                'id' => 1,
                'min_amount' => 0,
                'max_amount' => 349999,
                'total_commission' => 7000,
                'merchant_share' => 3500,
                'supplier_share' => 3500,
                'effective_from' => now(),
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'id' => 2,
                'min_amount' => 350000,
                'max_amount' => 500000,
                'total_commission' => 10000,
                'merchant_share' => 5000,
                'supplier_share' => 5000,
                'effective_from' => now(),
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'id' => 3,
                'min_amount' => 500001,
                'max_amount' => 1000000,
                'total_commission' => 13000,
                'merchant_share' => 6500,
                'supplier_share' => 6500,
                'effective_from' => now(),
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'id' => 4,
                'min_amount' => 1000001,
                'max_amount' => null,
                'total_commission' => 15000,
                'merchant_share' => 7500,
                'supplier_share' => 7500,
                'effective_from' => now(),
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);
    }
}
