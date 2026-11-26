<?php

namespace App\Domain\Finance\Services;

use App\Models\DepositTopup;
use App\Models\DepositWallet;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class TopupService
{
    public function __construct(
        protected DepositLedgerService $depositLedgerService
    ) {}

    /**
     * Supplier requests manual deposit top-up
     */
    public function requestTopup(int $supplierId, int $amount, UploadedFile $proofFile): DepositTopup
    {
        if ($amount <= 0) {
            throw ValidationException::withMessages(['amount' => 'Nominal top-up harus lebih dari 0']);
        }

        $filename = 'topup_' . $supplierId . '_' . time() . '_' . Str::random(8) . '.' . $proofFile->getClientOriginalExtension();
        $path = $proofFile->storeAs('proofs/topups', $filename, 'private');

        return DepositTopup::create([
            'supplier_id' => $supplierId,
            'amount' => $amount,
            'proof_path' => $path,
            'status' => 'pending',
        ]);
    }

    /**
     * Admin approves manual deposit top-up atomically and idempotently
     */
    public function approveTopup(int $topupId, int $adminId): DepositTopup
    {
        return DB::transaction(function () use ($topupId, $adminId) {
            // Anti-deadlock: deposit_topups -> deposit_wallets
            $topup = DepositTopup::where('id', $topupId)->lockForUpdate()->firstOrFail();
            $wallet = DepositWallet::where('supplier_id', $topup->supplier_id)->lockForUpdate()->firstOrFail();

            if ($topup->status !== 'pending') {
                if ($topup->status === 'approved') {
                    return $topup; // Idempotent
                }
                throw ValidationException::withMessages([
                    'topup' => 'Permintaan top-up ini sudah diproses sebelumnya (' . $topup->status . ').'
                ]);
            }

            $idempotencyKey = "topup:{$topup->id}";

            $this->depositLedgerService->credit(
                $wallet,
                $topup->amount,
                'topup',
                $idempotencyKey,
                null,
                "Top-up deposit disetujui oleh Admin #{$adminId}"
            );

            $topup->status = 'approved';
            $topup->approved_by = $adminId;
            $topup->approved_at = now();
            $topup->save();

            return $topup;
        });
    }

    /**
     * Admin rejects manual deposit top-up
     */
    public function rejectTopup(int $topupId, string $reason, int $adminId): DepositTopup
    {
        return DB::transaction(function () use ($topupId, $reason, $adminId) {
            $topup = DepositTopup::where('id', $topupId)->lockForUpdate()->firstOrFail();

            if ($topup->status !== 'pending') {
                throw ValidationException::withMessages([
                    'topup' => 'Permintaan top-up ini sudah diproses sebelumnya (' . $topup->status . ').'
                ]);
            }

            $topup->status = 'rejected';
            $topup->reject_reason = $reason;
            $topup->save();

            return $topup;
        });
    }
}
