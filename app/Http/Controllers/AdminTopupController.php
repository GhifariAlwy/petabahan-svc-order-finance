<?php

namespace App\Http\Controllers;

use App\Domain\Finance\Services\TopupService;
use App\Models\DepositTopup;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class AdminTopupController extends Controller
{
    public function __construct(
        protected TopupService $topupService
    ) {}

    public function index(Request $request): JsonResponse
    {
        $query = DepositTopup::query();

        if ($status = $request->query('status')) {
            $query->where('status', $status);
        }

        $topups = $query->orderByDesc('id')->paginate(20);

        return response()->json([
            'status' => 'ok',
            'data' => $topups,
        ]);
    }

    public function approve(Request $request, int $id): JsonResponse
    {
        $adminId = (int) ($request->header('X-User-Id') ?? 1);

        $approved = $this->topupService->approveTopup($id, $adminId);

        return response()->json([
            'status' => 'ok',
            'message' => 'Top-up deposit berhasil disetujui. Saldo agen telah dikredit.',
            'data' => $approved,
        ]);
    }

    public function reject(Request $request, int $id): JsonResponse
    {
        $adminId = (int) ($request->header('X-User-Id') ?? 1);
        $reason = $request->input('reason', 'Bukti transfer tidak valid atau tidak terbaca');

        $rejected = $this->topupService->rejectTopup($id, $reason, $adminId);

        return response()->json([
            'status' => 'ok',
            'message' => 'Top-up deposit berhasil ditolak.',
            'data' => $rejected,
        ]);
    }

    public function proofSignedUrl(int $id): JsonResponse
    {
        $topup = DepositTopup::findOrFail($id);

        $signedUrl = URL::temporarySignedRoute(
            'api.admin.topups.proof.show',
            now()->addMinutes(10),
            ['id' => $topup->id]
        );

        return response()->json([
            'status' => 'ok',
            'data' => [
                'signed_url' => $signedUrl,
                'expires_in_minutes' => 10,
            ],
        ]);
    }

    public function streamProof(Request $request, int $id): BinaryFileResponse|JsonResponse
    {
        if (!$request->hasValidSignature()) {
            return response()->json(['error' => ['code' => 'INVALID_SIGNATURE', 'message' => 'Link kedaluwarsa atau signature tidak valid']], 403);
        }

        $topup = DepositTopup::findOrFail($id);
        $disk = Storage::disk('private');

        if (!$disk->exists($topup->proof_path)) {
            return response()->json(['error' => ['code' => 'FILE_NOT_FOUND', 'message' => 'File bukti top-up tidak ditemukan']], 404);
        }

        return response()->file($disk->path($topup->proof_path));
    }
}
