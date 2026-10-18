<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Pos\Payments\InvalidWebhook;
use App\Domain\Pos\Payments\PaymentProviderRegistry;
use App\Domain\Pos\Services\PosSaleService;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Provider callbacks. Unauthenticated by design; authenticity comes only
 * from the provider's signature. A 2xx is returned once the event is
 * durably recorded (including duplicates) so providers stop retrying.
 */
class PaymentWebhookController extends Controller
{
    public function __invoke(Request $request, string $provider, PaymentProviderRegistry $providers, PosSaleService $sales): JsonResponse
    {
        $adapter = $providers->get($provider);

        try {
            $event = $adapter->parseWebhook($request->getContent(), $request->headers->all());
        } catch (InvalidWebhook $e) {
            Log::warning('Rejected payment webhook', ['provider' => $provider, 'reason' => $e->getMessage()]);

            return response()->json(['message' => 'Invalid webhook.'], 401);
        }

        return response()->json(['received' => true, 'outcome' => $sales->handleProviderEvent($adapter->code(), $event)]);
    }
}
