<?php

namespace App\Console\Commands;

use App\Models\DepositTransaction;
use App\Models\DepositWallet;
use App\Models\Order;
use App\Models\StockReservation;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class ReconcileCommand extends Command
{
    protected $signature = 'petabahan:reconcile {--detailed : Tampilkan rincian setiap anomali}';

    protected $description = 'Verifikasi 4 Invarian Kritis Finansial & Stok PETABAHAN (Target Selisih = 0)';

    public function handle(): int
    {
        $this->info('===============================================================');
        $this->info('   PETABAHAN RECONCILIATION SUITE (Wave 2 Verification)        ');
        $this->info('===============================================================');

        $hasError = false;

        // 1. Invarian 1 — Konsistensi Saldo Dompet vs Sum Ledger
        $this->comment('Memeriksa Invarian 1: Saldo Dompet vs Sum Ledger (credit - debit)...');
        $wallets = DepositWallet::all();
        $inv1Discrepancies = 0;

        foreach ($wallets as $wallet) {
            $sumCredit = (int) DepositTransaction::where('wallet_id', $wallet->id)
                ->where('direction', 'credit')
                ->sum('amount');
            $sumDebit = (int) DepositTransaction::where('wallet_id', $wallet->id)
                ->where('direction', 'debit')
                ->sum('amount');
            $expectedBalance = $sumCredit - $sumDebit;
            $diff = $wallet->balance - $expectedBalance;

            if ($diff !== 0) {
                $hasError = true;
                $inv1Discrepancies++;
                $this->error(" -> ANOMALI Wallet ID {$wallet->id} (Supplier {$wallet->supplier_id}): Saldo DB = {$wallet->balance}, Hitungan Ledger = {$expectedBalance}, Selisih = {$diff}");
            }
        }
        if ($inv1Discrepancies === 0) {
            $this->info(" [OK] Invarian 1 Bersih: Selisih mutlak = 0 untuk {$wallets->count()} dompet.");
        }

        // 2. Invarian 2 — Rantai Ledger Berurutan (balance_after consistency)
        $this->comment('Memeriksa Invarian 2: Integritas Rantai balance_after pada Ledger...');
        $inv2Discrepancies = 0;
        foreach ($wallets as $wallet) {
            $transactions = DepositTransaction::where('wallet_id', $wallet->id)
                ->orderBy('created_at', 'asc')
                ->orderBy('id', 'asc')
                ->get();

            $runningBalance = 0;
            foreach ($transactions as $tx) {
                if ($tx->direction === 'credit') {
                    $runningBalance += $tx->amount;
                } else {
                    $runningBalance -= $tx->amount;
                }

                if ($tx->balance_after !== $runningBalance) {
                    $hasError = true;
                    $inv2Discrepancies++;
                    $this->error(" -> ANOMALI Tx ID {$tx->id}: balance_after tercatat = {$tx->balance_after}, seharusnya = {$runningBalance}");
                }
            }

            if ($transactions->isNotEmpty()) {
                $lastTx = $transactions->last();
                if ($lastTx->balance_after !== $wallet->balance) {
                    $hasError = true;
                    $inv2Discrepancies++;
                    $this->error(" -> ANOMALI Wallet ID {$wallet->id}: balance_after terakhir ({$lastTx->balance_after}) != wallet.balance ({$wallet->balance})");
                }
            }
        }
        if ($inv2Discrepancies === 0) {
            $this->info(' [OK] Invarian 2 Bersih: Seluruh rantai balance_after sinkron dan valid.');
        }

        // 3. Invarian 3 — Konsistensi Komisi Order vs Debit Ledger
        $this->comment('Memeriksa Invarian 3: Konsistensi Pemotongan Komisi Order...');
        $paidOrders = Order::whereIn('status', ['PAID_DP', 'PAID_FULL', 'READY_FOR_PICKUP', 'COURIER_REQUESTED', 'IN_DELIVERY', 'COMPLETED'])
            ->get();
        $inv3Discrepancies = 0;

        foreach ($paidOrders as $order) {
            $key = "commission:{$order->id}";
            $txCount = DepositTransaction::where('idempotency_key', $key)->count();
            $tx = DepositTransaction::where('idempotency_key', $key)->first();

            if ($txCount !== 1) {
                $hasError = true;
                $inv3Discrepancies++;
                $this->error(" -> ANOMALI Order ID {$order->id}: Ditemukan {$txCount} mutasi komisi (seharusnya tepat 1).");
            } elseif ($tx->amount !== $order->total_commission) {
                $hasError = true;
                $inv3Discrepancies++;
                $this->error(" -> ANOMALI Order ID {$order->id}: Nominal debit komisi ({$tx->amount}) != total_commission ({$order->total_commission}).");
            }
        }

        // Cek order dengan kompensasi Saga StockLockFailed: net mutasi harus 0
        $sagaCompensatedOrders = Order::where('status', 'CANCELLED')
            ->where('cancel_reason', 'LIKE', '%Saga Compensation%')
            ->get();

        foreach ($sagaCompensatedOrders as $order) {
            $debit = (int) DepositTransaction::where('idempotency_key', "commission:{$order->id}")->sum('amount');
            $credit = (int) DepositTransaction::where('idempotency_key', "refund:commission:{$order->id}")->sum('amount');
            if ($debit !== $credit) {
                $hasError = true;
                $inv3Discrepancies++;
                $this->error(" -> ANOMALI Order ID {$order->id}: Net kompensasi Saga debit ({$debit}) != credit ({$credit}).");
            }
        }
        if ($inv3Discrepancies === 0) {
            $this->info(" [OK] Invarian 3 Bersih: {$paidOrders->count()} pesanan berbayar memiliki debit komisi presisi 1:1.");
        }

        // 4. Invarian 4 — Konsistensi Reservasi Stok
        $this->comment('Memeriksa Invarian 4: Konsistensi Status Reservasi Stok...');
        $inv4Discrepancies = 0;
        $unlinkedReservations = StockReservation::whereDoesntHave('order')->count();
        if ($unlinkedReservations > 0) {
            $hasError = true;
            $inv4Discrepancies += $unlinkedReservations;
            $this->error(" -> ANOMALI: Ditemukan {$unlinkedReservations} reservasi tanpa referensi order!");
        }
        if ($inv4Discrepancies === 0) {
            $this->info(' [OK] Invarian 4 Bersih: Tidak ada orphan stock reservations.');
        }

        $this->newLine();
        if ($hasError) {
            $this->error('REKONSILIASI GAGAL! Ditemukan anomali finansial/stok. Exit code = 1.');
            return 1;
        }

        $this->info('REKONSILIASI BERHASIL! Seluruh 4 invarian bernilai selisih mutlak = 0. Exit code = 0.');
        return 0;
    }
}
