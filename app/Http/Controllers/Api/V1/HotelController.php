<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Hotel;
use App\Services\AuditLogger;
use Illuminate\Http\Request;

/**
 * Tenant settings management. The authenticated user can only ever act on
 * their own hotel (the tenant middleware pinned it for the request).
 */
class HotelController extends Controller
{
    public function show(Request $request)
    {
        $this->authorize('hotels.view');

        return response()->json(['data' => $request->user()->hotel]);
    }

    public function update(Request $request)
    {
        $this->authorize('hotels.update');

        $validated = $request->validate([
            'name' => ['sometimes', 'string', 'max:120'],
            'legal_name' => ['nullable', 'string', 'max:191'],
            'address' => ['nullable', 'string', 'max:191'],
            'city' => ['nullable', 'string', 'max:191'],
            'country' => ['nullable', 'string', 'max:2'],
            'phone' => ['nullable', 'string', 'max:40'],
            'email' => ['nullable', 'email'],
            'website' => ['nullable', 'url'],
            'timezone' => ['sometimes', 'string', 'max:64'],
            'currency' => ['sometimes', 'string', 'size:3'],
            'tax_rate' => ['sometimes', 'numeric', 'between:0,100'],
            'check_in_time' => ['sometimes', 'date_format:H:i'],
            'check_out_time' => ['sometimes', 'date_format:H:i'],
            'settings' => ['sometimes', 'array'],
            'logo' => ['nullable', 'image', 'mimes:png,jpg,jpeg,webp', 'max:2048'],
        ]);

        $hotel = $request->user()->hotel;

        if ($request->hasFile('logo')) {
            $hotel->setLogo($request->file('logo'));
        }

        unset($validated['logo']);
        $hotel->update($validated);

        AuditLogger::critical($hotel, 'hotel.updated', ['fields' => array_keys($validated)]);
        AuditLogger::log('hotel.logo_uploaded', $hotel, null, ['uploaded' => $request->hasFile('logo')]);

        return response()->json(['data' => $hotel->fresh()]);
    }
}