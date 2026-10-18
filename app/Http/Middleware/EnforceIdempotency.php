<?php

namespace App\Http\Middleware;

use App\Domain\Platform\Models\IdempotencyKey;
use App\Support\Exceptions\BusinessRuleViolation;
use App\Support\Tenancy\TenantContext;
use Closure;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

/**
 * Makes unsafe requests safe to retry. Usage: ->middleware('idempotent') to
 * honour an Idempotency-Key when sent, 'idempotent:required' to demand one.
 *
 * - First request with a key: processed; a 2xx/3xx response is stored.
 * - Same key + same body again: the stored response is replayed.
 * - Same key + different body: 422 idempotency_key_reused.
 * - Same key while the first is still processing: 409.
 * - 4xx/5xx responses are not stored, so the client may retry.
 *
 * Keys are scoped to the user and tenant, so they cannot collide across tenants.
 */
class EnforceIdempotency
{
    public function __construct(private readonly TenantContext $tenant) {}

    public function handle(Request $request, Closure $next, ?string $mode = null): Response
    {
        // Re-entrant: a route may carry both the group-level 'idempotent' and its own
        // 'idempotent:required'. The outermost instance that claims the key handles it.
        if (in_array($request->method(), ['GET', 'HEAD', 'OPTIONS'], true) || $request->attributes->get('idempotency.claimed')) {
            return $next($request);
        }

        $key = $request->headers->get(config('platform.idempotency.header'));

        if ($key === null || $key === '') {
            if ($mode === 'required') {
                throw new BusinessRuleViolation('idempotency_key_required', 'This operation requires an '.config('platform.idempotency.header').' header.', 400);
            }

            return $next($request);
        }

        if (! preg_match('/^[A-Za-z0-9._:\-]{8,128}$/', $key)) {
            throw new BusinessRuleViolation('idempotency_key_invalid', 'Idempotency keys must be 8-128 characters of [A-Za-z0-9._:-].', 400);
        }

        $scope = 'user:'.($request->user()?->getAuthIdentifier() ?? 'guest').'|org:'.($this->tenant->id() ?? '-');
        $hash = hash('sha256', $request->method().'|'.$request->path().'|'.json_encode($request->all()));

        $record = $this->claim($scope, $key, $request, $hash);
        $request->attributes->set('idempotency.claimed', true);

        if ($record->completed_at !== null) {
            return response($record->response_body, $record->response_status, [
                'Content-Type' => 'application/json',
                'Idempotent-Replayed' => 'true',
            ]);
        }

        try {
            $response = $next($request);
        } catch (\Throwable $e) {
            $record->delete();
            throw $e;
        }

        // Only successful outcomes are remembered. Client and server errors change
        // no state (validation and business-rule failures roll back), so the key
        // is released and the client may retry — with a corrected body if needed.
        if ($response->getStatusCode() >= 400) {
            $record->delete();
        } else {
            $record->forceFill([
                'response_status' => $response->getStatusCode(),
                'response_body' => $response->getContent(),
                'completed_at' => now(),
            ])->save();
        }

        return $response;
    }

    private function claim(string $scope, string $key, Request $request, string $hash): IdempotencyKey
    {
        return DB::transaction(function () use ($scope, $key, $request, $hash) {
            $existing = IdempotencyKey::query()->where('scope', $scope)->where('key', $key)->lockForUpdate()->first();

            if ($existing !== null && $existing->expires_at->isPast()) {
                $existing->delete();
                $existing = null;
            }

            if ($existing === null) {
                try {
                    return IdempotencyKey::query()->create([
                        'scope' => $scope,
                        'key' => $key,
                        'method' => $request->method(),
                        'path' => substr($request->path(), 0, 255),
                        'request_hash' => $hash,
                        'expires_at' => now()->addHours(config('platform.idempotency.ttl_hours')),
                    ]);
                } catch (UniqueConstraintViolationException) {
                    throw new BusinessRuleViolation('idempotency_request_in_progress', 'A request with this idempotency key is already being processed.', 409);
                }
            }

            if (! hash_equals($existing->request_hash, $hash)) {
                throw new BusinessRuleViolation('idempotency_key_reused', 'This idempotency key was already used for a different request.', 422);
            }

            if ($existing->completed_at === null) {
                throw new BusinessRuleViolation('idempotency_request_in_progress', 'A request with this idempotency key is already being processed.', 409);
            }

            return $existing;
        });
    }
}
