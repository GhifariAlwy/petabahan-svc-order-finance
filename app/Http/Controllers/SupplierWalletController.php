<?php

namespace App\Http\Controllers;

use App\Domain\Finance\Services\DepositLedgerService;
use App\Domain\Finance\Services\TopupService;
use App\Models\DepositTransaction;
use App\Models\DepositWallet;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SupplierWalletController extends Controller
{
    public function __construct(
        protected DepositLedgerService $ledgerService,
        protected TopupService $topupService
    ) {}

    public function show(Request $request): JsonResponse
    {
        $supplierId = (int) ($request->header('X-Supplier-Id') ?? $request->query('supplier_id', 1));
        $wallet = $this->ledgerService->getOrCreateWallet($supplierId);

        return response()->json([
            'status' => 'ok',
            'data' => [
                'wallet_id' => $wallet->id,
                'supplier_id' => $wallet->supplier_id,
                'balance' => $wallet->balance,
                'min_required_balance' => $wallet->min_required_balance,
                'is_above_minimum' => $wallet->balance > $wallet->min_required_balance,
            ],
        ]);
    }

    public function transactions(Request $request): JsonResponse
    {
        $supplierId = (int) ($request->header('X-Supplier-Id') ?? $request->query('supplier_id', 1));
        $wallet = $this->ledgerService->getOrCreateWallet($supplierId);

        $transactions = DepositTransaction::where('wallet_id', $wallet->id)
            ->orderByDesc('id')
            ->paginate(20);

        return response()->json([
            'status' => 'ok',
            'data' => $transactions,
        ]);
    }

    public function requestTopup(Request $request): JsonResponse
    {
        $supplierId = (int) ($request->header('X-Supplier-Id') ?? $request->input('supplier_id', 1));

        $validated = $request->validate([
            'amount' => 'required|integer|min:10000',
            'proof' => 'required|file|mimes:jpg,jpeg,png,pdf|max:5120',
        ]);

        $topup = $this->topupService->requestTopup(
            $supplierId,
            (int) $validated['amount'],
            $request->file('proof')
        );

        return response()->json([
            'status' => 'ok',
            'message' => 'Permintaan top-up deposit berhasil diajukan. Menunggu verifikasi admin.',
            'data' => $topup,
        ], 201);
    }
}
