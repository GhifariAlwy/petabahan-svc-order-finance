<?php

namespace App\Http\Controllers;

use App\Models\CommissionRule;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminCommissionRuleController extends Controller
{
    public function index(): JsonResponse
    {
        $rules = CommissionRule::orderBy('min_amount')->get();

        return response()->json([
            'status' => 'ok',
            'data' => $rules,
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'min_amount' => 'required|integer|min:0',
            'max_amount' => 'nullable|integer',
            'total_commission' => 'required|integer|min:0',
            'merchant_share' => 'required|integer|min:0',
            'supplier_share' => 'required|integer|min:0',
            'is_active' => 'boolean',
        ]);

        $rule = CommissionRule::create($validated);

        return response()->json([
            'status' => 'ok',
            'message' => 'Aturan komisi berhasil dibuat.',
            'data' => $rule,
        ], 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $rule = CommissionRule::findOrFail($id);

        $validated = $request->validate([
            'min_amount' => 'sometimes|integer|min:0',
            'max_amount' => 'nullable|integer',
            'total_commission' => 'sometimes|integer|min:0',
            'merchant_share' => 'sometimes|integer|min:0',
            'supplier_share' => 'sometimes|integer|min:0',
            'is_active' => 'sometimes|boolean',
        ]);

        $rule->update($validated);

        return response()->json([
            'status' => 'ok',
            'message' => 'Aturan komisi berhasil diperbarui.',
            'data' => $rule,
        ]);
    }
}
