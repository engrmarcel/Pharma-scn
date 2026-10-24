<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Support\Web\ApiError;
use App\Support\Web\InternalApi;
use Illuminate\Http\JsonResponse;

/**
 * The desktop till's product list: the terminal's catalogue (prices per pack size, prescription
 * classification, in-date stock by batch), loaded once per shift and searched in the browser so
 * scanning and typing stay instant. Served through the API (ADR-0024); prices and stock are
 * re-checked by the server whenever the cart is saved.
 */
class PosCatalogueController extends Controller
{
    public function __invoke(string $terminal, InternalApi $api): JsonResponse
    {
        try {
            return response()->json($api->get("pos/terminals/{$terminal}/offline-catalogue"), 200, ['Cache-Control' => 'no-store']);
        } catch (ApiError $e) {
            return response()->json(['message' => $e->getMessage()], $e->status);
        }
    }
}
