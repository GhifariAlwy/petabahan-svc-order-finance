<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shipments', function (Blueprint $table) {
            $table->string('driver_name', 128)->nullable()->after('courier_type');
            $table->string('driver_phone', 32)->nullable()->after('driver_name');
            $table->string('vehicle_number', 32)->nullable()->after('driver_phone');
            $table->text('handover_notes')->nullable()->after('pickup_proof');
            $table->text('handover_photo_path')->nullable()->after('handover_notes');
        });
    }

    public function down(): void
    {
        Schema::table('shipments', function (Blueprint $table) {
            $table->dropColumn([
                'driver_name',
                'driver_phone',
                'vehicle_number',
                'handover_notes',
                'handover_photo_path',
            ]);
        });
    }
};
