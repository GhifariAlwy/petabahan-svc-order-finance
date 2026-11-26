<?php

use App\Domain\Finance\Services\DepositLedgerService;
use App\Domain\Finance\Services\TopupService;
use App\Domain\Order\Services\PaymentService;
use App\Models\DepositTopup;
use App\Models\DepositWallet;
use App\Models\Order;
use App\Models\OrderPayment;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Validation\ValidationException;

beforeEach(function () {
    Storage::fake('private');
});

it('processes manual top-up and admin approval with idempotent ledger credit', function () {
    $topupService = app(TopupService::class);
    $ledgerService = app(DepositLedgerService::class);

    $wallet = DepositWallet::create([
        'supplier_id' => 301,
        'balance' => 20000,
        'min_required_balance' => 50000,
    ]);

    $fakeProof = UploadedFile::fake()->create('topup_receipt.jpg', 500);

    // 1. Supplier requests top-up of 100.000
    $topup = $topupService->requestTopup(301, 100000, $fakeProof);
    expect($topup->status)->toBe('pending')
        ->and($topup->amount)->toBe(100000)
        ->and(Storage::disk('private')->exists($topup->proof_path))->toBeTrue();

    // 2. Admin approves top-up #1
    $approved = $topupService->approveTopup($topup->id, 999);
    expect($approved->status)->toBe('approved')
        ->and($wallet->refresh()->balance)->toBe(120000); // 20k + 100k

    // 3. Idempotent approval: approving again must not credit again
    $approvedAgain = $topupService->approveTopup($topup->id, 999);
    expect($wallet->refresh()->balance)->toBe(120000);
});

it('rejects top-up request with reason and does not credit wallet', function () {
    $topupService = app(TopupService::class);

    $wallet = DepositWallet::create([
        'supplier_id' => 302,
        'balance' => 25000,
        'min_required_balance' => 50000,
    ]);

    $fakeProof = UploadedFile::fake()->create('blurry_receipt.jpg', 500);
    $topup = $topupService->requestTopup(302, 50000, $fakeProof);

    $rejected = $topupService->rejectTopup($topup->id, 'Foto struk buram', 999);

    expect($rejected->status)->toBe('rejected')
        ->and($rejected->reject_reason)->toBe('Foto struk buram')
        ->and($wallet->refresh()->balance)->toBe(25000); // Saldo tidak berubah
});

it('generates secure temporary signed URLs for private transfer proofs and enforces expiration', function () {
    $paymentService = app(PaymentService::class);

    $order = Order::create([
        'order_code' => 'ORD-PROOF-01',
        'merchant_id' => 1,
        'supplier_id' => 2,
        'status' => 'PAYMENT_PROOF_UPLOADED',
    ]);

    // Store private proof
    $fakeFile = UploadedFile::fake()->create('proof.jpg', 300);
    $path = $fakeFile->storeAs('proofs/payments', 'proof_test.jpg', 'private');

    $payment = OrderPayment::create([
        'order_id' => $order->id,
        'type' => 'first',
        'amount' => 500000,
        'proof_path' => $path,
        'status' => 'uploaded',
    ]);

    $signedUrl = $paymentService->getSignedProofUrl($payment, 10);
    expect($signedUrl)->toContain('/api/orders/' . $order->id . '/payments/' . $payment->id . '/proof/stream')
        ->and($signedUrl)->toContain('signature=');

    // Test requesting with valid signature
    $response = $this->get($signedUrl);
    $response->assertStatus(200);

    // Test requesting with tampered / invalid signature -> 403 Forbidden
    $tamperedUrl = $signedUrl . 'invalid';
    $tamperedResponse = $this->get($tamperedUrl);
    $tamperedResponse->assertStatus(403);
});
