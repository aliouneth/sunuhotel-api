<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\CountryTaxRate;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use App\Http\Controllers\Controller;
use Illuminate\Validation\Rule;

/**
 * Platform-scoped CRUD for country-level default tax (VAT) percentages. These
 * are a PURE fallback — a hotel's own tax_rate always wins; the country rate
 * is only used when a hotel in that country leaves its tax_rate at 0.
 */
class CountryTaxRateController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json([
            'data' => CountryTaxRate::query()->orderBy('country_code')->get(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'country_code' => ['required', 'string', 'size:2', Rule::unique('country_tax_rates')],
            'tax_rate' => ['required', 'numeric', 'min:0', 'max:100'],
        ]);

        $rate = CountryTaxRate::create([
            'country_code' => strtoupper($validated['country_code']),
            'tax_rate' => (float) $validated['tax_rate'],
        ]);

        return response()->json(['data' => $rate], 201);
    }

    public function show(CountryTaxRate $countryTaxRate): JsonResponse
    {
        return response()->json(['data' => $countryTaxRate]);
    }

    public function update(Request $request, CountryTaxRate $countryTaxRate): JsonResponse
    {
        $validated = $request->validate([
            'country_code' => ['sometimes', 'string', 'size:2', Rule::unique('country_tax_rates')->ignore($countryTaxRate->id)],
            'tax_rate' => ['sometimes', 'numeric', 'min:0', 'max:100'],
        ]);

        if (isset($validated['country_code'])) {
            $validated['country_code'] = strtoupper($validated['country_code']);
        }

        $countryTaxRate->update($validated);

        return response()->json(['data' => $countryTaxRate->fresh()]);
    }

    public function destroy(CountryTaxRate $countryTaxRate): JsonResponse
    {
        $countryTaxRate->delete();

        return response()->json(['data' => null], 204);
    }
}
