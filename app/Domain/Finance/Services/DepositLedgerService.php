<?php

namespace App\Domain\Finance\Services;

use App\Exceptions\InsufficientDepositException;
use App\Models\DepositTransaction;
use App\Models\DepositWallet;
use Illuminate\Support\Facades\DB;

class DepositLedgerService
{
    /**
     * Debit wallet balance with strictly append-only ledger transaction
     *
     * @throws InsufficientDepositException
     */
    public function debit(
        DepositWallet $wallet,
        int $amount,
        string $type,
        string $idempotencyKey,
        ?int $orderId = null,
        ?string $note = null
    ): DepositTransaction {
        // Idempotency check: if transaction with idempotency_key already exists, return it
        $existing = DepositTransaction::where('idempotency_key', $idempotencyKey)->first();
        if ($existing) {
            return $existing;
        }

        if ($wallet->balance < $amount) {
            throw new InsufficientDepositException(
                "Saldo deposit tidak mencukupi. Dibutuhkan Rp " . number_format($amount, 0, ',', '.') .
                ", saldo saat ini Rp " . number_format($wallet->balance, 0, ',', '.')
            );
        }

        $newBalance = $wallet->balance - $amount;
        $wallet->balance = $newBalance;
        $wallet->save();

        return DepositTransaction::create([
            'wallet_id' => $wallet->id,
            'type' => $type,
            'direction' => 'debit',
            'amount' => $amount,
            'balance_after' => $newBalance,
            'order_id' => $orderId,
            'idempotency_key' => $idempotencyKey,
            'note' => $note,
            'created_at' => now(),
        ]);
    }

    /**
     * Credit wallet balance with strictly append-only ledger transaction
     */
    public function credit(
        DepositWallet $wallet,
        int $amount,
        string $type,
        string $idempotencyKey,
        ?int $orderId = null,
        ?string $note = null
    ): DepositTransaction {
        // Idempotency check
        $existing = DepositTransaction::where('idempotency_key', $idempotencyKey)->first();
        if ($existing) {
            return $existing;
        }

        $newBalance = $wallet->balance + $amount;
        $wallet->balance = $newBalance;
        $wallet->save();

        return DepositTransaction::create([
            'wallet_id' => $wallet->id,
            'type' => $type,
            'direction' => 'credit',
            'amount' => $amount,
            'balance_after' => $newBalance,
            'order_id' => $orderId,
            'idempotency_key' => $idempotencyKey,
            'note' => $note,
            'created_at' => now(),
        ]);
    }

    /**
     * Get or create wallet for supplier
     */
    public function getOrCreateWallet(int $supplierId): DepositWallet
    {
        return DepositWallet::firstOrCreate(
            ['supplier_id' => $supplierId],
            ['balance' => 0, 'min_required_balance' => 50000]
        );
    }
}
