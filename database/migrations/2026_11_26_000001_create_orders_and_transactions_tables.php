<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('orders', function (Blueprint $table) {
            $table->id();
            $table->string('order_code', 32)->unique();
            $table->unsignedBigInteger('merchant_id')->index();
            $table->unsignedBigInteger('supplier_id')->index();
            $table->unsignedBigInteger('shopping_list_id')->nullable()->index();
            $table->string('status', 32)->default('DRAFT')->index();
            $table->string('payment_scheme', 16)->default('dp'); // dp, full
            $table->string('fulfillment_type', 16)->default('pickup'); // pickup, courier
            $table->bigInteger('subtotal_materials')->default(0);
            $table->bigInteger('merchant_fee')->default(0);
            $table->bigInteger('supplier_commission')->default(0);
            $table->bigInteger('total_commission')->default(0);
            $table->bigInteger('shipping_cost_estimate')->default(0);
            $table->bigInteger('shipping_cost_actual')->default(0);
            $table->bigInteger('grand_total_merchant')->default(0);
            $table->bigInteger('amount_paid')->default(0);
            $table->decimal('total_weight_kg', 8, 2)->default(0);
            $table->timestamp('accepted_at')->nullable();
            $table->timestamp('booking_expires_at')->nullable()->index();
            $table->timestamp('payment_expires_at')->nullable()->index();
            $table->timestamp('settlement_expires_at')->nullable()->index();
            $table->timestamp('completed_at')->nullable();
            $table->text('cancel_reason')->nullable();
            $table->timestamps();
        });

        Schema::create('order_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained('orders')->cascadeOnDelete();
            $table->unsignedBigInteger('product_id')->index();
            $table->string('material_name_snapshot');
            $table->integer('qty');
            $table->string('unit', 16);
            $table->bigInteger('unit_price_applied');
            $table->unsignedBigInteger('tier_id_applied')->nullable();
            $table->bigInteger('line_total');
            $table->boolean('is_available_confirmed')->default(true);
            $table->timestamps();
        });

        Schema::create('order_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained('orders')->cascadeOnDelete();
            $table->string('type', 16); // first, settlement
            $table->bigInteger('amount');
            $table->bigInteger('included_merchant_fee')->default(0);
            $table->bigInteger('included_shipping')->default(0);
            $table->string('proof_path');
            $table->string('status', 16)->default('uploaded'); // uploaded, confirmed, rejected
            $table->unsignedBigInteger('confirmed_by')->nullable();
            $table->timestamp('confirmed_at')->nullable();
            $table->text('reject_reason')->nullable();
            $table->timestamps();
        });

        Schema::create('stock_reservations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained('orders')->cascadeOnDelete();
            $table->unsignedBigInteger('product_id')->index();
            $table->integer('qty');
            $table->string('status', 16)->default('soft_hold'); // soft_hold, reserved, released, consumed
            $table->timestamp('expires_at')->nullable()->index();
            $table->timestamps();
        });

        Schema::create('order_status_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained('orders')->cascadeOnDelete();
            $table->string('from_status', 32);
            $table->string('to_status', 32);
            $table->unsignedBigInteger('actor_id')->nullable();
            $table->text('note')->nullable();
            $table->timestamp('created_at')->useCurrent()->index();
        });

        Schema::create('disputes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained('orders');
            $table->unsignedBigInteger('opened_by');
            $table->text('reason');
            $table->jsonb('evidence')->nullable();
            $table->string('status', 16)->default('opened'); // opened, in_review, resolved, rejected
            $table->text('resolution')->nullable();
            $table->unsignedBigInteger('resolved_by')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('disputes');
        Schema::dropIfExists('order_status_logs');
        Schema::dropIfExists('stock_reservations');
        Schema::dropIfExists('order_payments');
        Schema::dropIfExists('order_items');
        Schema::dropIfExists('orders');
    }
};
