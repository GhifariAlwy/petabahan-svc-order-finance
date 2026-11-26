<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('commission_rules', function (Blueprint $table) {
            $table->id();
            $table->bigInteger('min_amount')->default(0);
            $table->bigInteger('max_amount')->nullable();
            $table->bigInteger('total_commission');
            $table->bigInteger('merchant_share');
            $table->bigInteger('supplier_share');
            $table->timestamp('effective_from')->useCurrent();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('deposit_wallets', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('supplier_id')->unique();
            $table->bigInteger('balance')->default(0);
            $table->bigInteger('min_required_balance')->default(50000);
            $table->timestamps();
        });

        Schema::create('deposit_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('wallet_id')->constrained('deposit_wallets');
            $table->string('type', 32); // topup, commission, courier_fee, refund, adjustment
            $table->string('direction', 8); // credit, debit
            $table->bigInteger('amount');
            $table->bigInteger('balance_after');
            $table->unsignedBigInteger('order_id')->nullable()->index();
            $table->string('reference_id')->nullable();
            $table->string('idempotency_key', 128)->unique();
            $table->text('note')->nullable();
            $table->timestamp('created_at')->useCurrent()->index();
        });

        Schema::create('deposit_topups', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('supplier_id')->index();
            $table->bigInteger('amount');
            $table->string('proof_path');
            $table->string('status', 16)->default('pending'); // pending, approved, rejected
            $table->unsignedBigInteger('approved_by')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->text('reject_reason')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('deposit_topups');
        Schema::dropIfExists('deposit_transactions');
        Schema::dropIfExists('deposit_wallets');
        Schema::dropIfExists('commission_rules');
    }
};
