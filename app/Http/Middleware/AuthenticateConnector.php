<?php

namespace App\Http\Middleware;

use App\Domain\Billing\Services\EntitlementService;
use App\Domain\Integration\Models\IntegrationConnector;
use App\Support\Exceptions\BusinessRuleViolation;
use App\Support\Tenancy\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Symfony\Component\HttpFoundation\Response;

/**
 * Authenticates an external system by its connector key (Authorization: Bearer ck_…)
 * and sets the connector's organization as the tenant. Not a user session: the
 * key can only reach the integration intake endpoints.
 *
 * When the connector has a signing secret, the body must also be signed:
 *   X-Timestamp: <unix seconds>
 *   X-Signature: sha256=<hex HMAC-SHA256 of "<timestamp>.<raw body>">
 *
 * Keys are compared by hash in constant time and never logged.
 */
class AuthenticateConnector
{
    public function __construct(
        private readonly TenantContext $tenant,
        private readonly EntitlementService $entitlements,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $key = (string) ($request->bearerToken() ?? $request->header('X-Connector-Key', ''));
        $connector = preg_match('/^ck_([a-z0-9]{12})_[A-Za-z0-9]{40}$/', $key, $m)
            ? IntegrationConnector::acrossTenants()->with('organization')->where('inbound_key_prefix', $m[1])->first()
            : null;

        if ($connector === null || ! hash_equals((string) $connector->inbound_key_hash, hash('sha256', $key))) {
            throw new BusinessRuleViolation('connector_unauthenticated', 'Invalid or missing connector key.', 401);
        }
        if (! $connector->acceptsMessages()) {
            throw new BusinessRuleViolation('connector_inactive', 'This connector is not active.', 403);
        }

        $organization = $connector->organization;
        if (! $organization->isActive()) {
            throw new BusinessRuleViolation('organization_suspended', 'The organization is suspended.', 403);
        }
        if (! $organization->hasModule('integrations') || ! $this->entitlements->allows($organization, 'module.integrations')) {
            throw new BusinessRuleViolation('module_not_enabled', 'The integrations module is not enabled for this organization.', 403);
        }

        if ($connector->signing_secret !== null) {
            $this->verifySignature($request, $connector->signing_secret);
        }

        $limiter = "integration-connector:{$connector->id}";
        if (! RateLimiter::attempt($limiter, $connector->rate_limit_per_minute, fn () => true, 60)) {
            throw new BusinessRuleViolation('rate_limited', 'Too many requests for this connector. Retry after the indicated delay.', 429,
                ['retry_after_seconds' => RateLimiter::availableIn($limiter)]);
        }

        $this->tenant->set($organization);
        $request->attributes->set('connector', $connector);

        try {
            return $next($request);
        } finally {
            $this->tenant->clear();
        }
    }

    private function verifySignature(Request $request, string $secret): void
    {
        $timestamp = (string) $request->header('X-Timestamp', '');
        $signature = (string) $request->header('X-Signature', '');

        if (! ctype_digit($timestamp) || abs(time() - (int) $timestamp) > config('integrations.signature_tolerance_seconds')) {
            throw new BusinessRuleViolation('signature_invalid', 'Missing or stale X-Timestamp. Signed requests must be sent within the tolerance window.', 401);
        }
        $expected = 'sha256='.hash_hmac('sha256', $timestamp.'.'.$request->getContent(), $secret);
        if (! hash_equals($expected, $signature)) {
            throw new BusinessRuleViolation('signature_invalid', 'The request signature does not match.', 401);
        }
    }
}
