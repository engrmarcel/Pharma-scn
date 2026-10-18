<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Accepts a well-formed X-Correlation-Id from the caller or generates one.
 * Stored in Laravel Context, so it follows the request into logs, audit
 * records and queued jobs.
 */
class AssignCorrelationId
{
    public const HEADER = 'X-Correlation-Id';

    public function handle(Request $request, Closure $next): Response
    {
        $incoming = (string) $request->headers->get(self::HEADER, '');
        $id = preg_match('/^[A-Za-z0-9._\-]{8,64}$/', $incoming) ? $incoming : (string) Str::uuid();

        Context::add('correlation_id', $id);

        $response = $next($request);
        $response->headers->set(self::HEADER, $id);

        return $response;
    }
}
