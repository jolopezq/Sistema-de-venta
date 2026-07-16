<?php

namespace App\Http\Controllers;

use App\Models\LoyaltyConfig;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class LoyaltyConfigController extends Controller
{
    /**
     * Obtiene la configuración de lealtad activa.
     */
    public function show(): JsonResponse
    {
        return response()->json(LoyaltyConfig::active());
    }

    /**
     * Actualiza la configuración de lealtad.
     */
    public function update(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'accumulation_rate' => ['required', 'numeric', 'min:0'],
            'redemption_value' => ['required', 'numeric', 'min:0'],
            'points_expiration_days' => ['required', 'integer', 'min:0'],
            'excluded_categories' => ['nullable', 'array'],
            'excluded_categories.*' => ['integer', 'exists:categories,id'],
        ]);

        $config = LoyaltyConfig::active();
        if (!$config) {
            $config = LoyaltyConfig::create($validated);
        } else {
            $config->update($validated);
        }

        return response()->json($config);
    }
}
