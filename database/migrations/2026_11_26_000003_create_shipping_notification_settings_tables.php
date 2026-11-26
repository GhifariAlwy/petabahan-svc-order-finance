<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shipments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->unique()->constrained('orders')->cascadeOnDelete();
            $table->string('vehicle_class', 32)->nullable();
            $table->string('courier_company', 64)->nullable();
            $table->string('courier_type', 64)->nullable();
            $table->string('biteship_order_id', 128)->nullable()->index();
            $table->string('biteship_tracking_id', 128)->nullable()->index();
            $table->string('waybill_id', 128)->nullable();
            $table->text('tracking_url')->nullable();
            $table->bigInteger('shipping_fee_actual')->default(0);
            $table->string('status', 32)->default('pending')->index();
            $table->text('pickup_proof')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->jsonb('raw_response')->nullable();
            $table->timestamps();
        });

        Schema::create('notifications', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id')->index();
            $table->string('type', 64);
            $table->string('title');
            $table->text('body');
            $table->jsonb('data')->nullable();
            $table->timestamp('read_at')->nullable()->index();
            $table->jsonb('channel_status')->nullable();
            $table->timestamps();
        });

        Schema::create('platform_settings', function (Blueprint $table) {
            $table->id();
            $table->string('key', 64)->unique();
            $table->jsonb('value');
            $table->string('description')->nullable();
            $table->timestamps();
        });

        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('actor_id')->nullable()->index();
            $table->string('action', 64);
            $table->string('entity_type', 64);
            $table->string('entity_id', 64)->index();
            $table->jsonb('before')->nullable();
            $table->jsonb('after')->nullable();
            $table->string('ip', 45)->nullable();
            $table->timestamp('created_at')->useCurrent()->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_logs');
        Schema::dropIfExists('platform_settings');
        Schema::dropIfExists('notifications');
        Schema::dropIfExists('shipments');
    }
};
