<?php

namespace App\Support\Web;

use App\Support\Exceptions\BusinessRuleViolation;
use App\Support\Tenancy\TenantContext;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Request;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

/**
 * The web screens are clients of the public API, called in-process (ADR-0024).
 *
 * A call is dispatched through the HTTP kernel as an /api/v1 request made by the
 * signed-in user for the organization chosen in the switcher, so it passes the same
 * middleware (tenant, module, security, idempotency), validation, authorization and
 * business rules as a mobile or integration client. No business rule lives in a screen.
 *
 * - 2xx: the decoded JSON body is returned.
 * - 422 validation: a ValidationException with the API's field errors (optionally prefixed).
 * - any other error: an ApiError carrying the API's message, code and status.
 */
class InternalApi
{
    public function __construct(
        private readonly Kernel $kernel,
        private readonly TenantContext $tenant,
        private readonly Router $router,
    ) {}

    public function get(string $uri, array $query = []): array
    {
        return $this->send('GET', $uri, array_filter($query, fn ($v) => $v !== null && $v !== ''));
    }

    public function post(string $uri, array $data = [], ?string $errorPrefix = null, ?string $idempotencyKey = null): array
    {
        return $this->send('POST', $uri, $data, $errorPrefix, $idempotencyKey);
    }

    public function put(string $uri, array $data = [], ?string $errorPrefix = null): array
    {
        return $this->send('PUT', $uri, $data, $errorPrefix);
    }

    public function patch(string $uri, array $data = [], ?string $errorPrefix = null): array
    {
        return $this->send('PATCH', $uri, $data, $errorPrefix);
    }

    public function delete(string $uri, array $data = []): array
    {
        return $this->send('DELETE', $uri, $data);
    }

    /** Multipart POST: $files maps field name => UploadedFile (Livewire uploads are UploadedFiles). */
    public function upload(string $uri, array $data, array $files, ?string $errorPrefix = null): array
    {
        return $this->send('POST', $uri, $data, $errorPrefix, null, $files);
    }

    /** Raw response for downloads (PDF, CSV, images); refusals are raised like any call. */
    public function raw(string $method, string $uri, array $query = []): Response
    {
        $response = $this->dispatch($method, $uri, $query, null);
        if ($response->getStatusCode() >= 400) {
            $body = json_decode((string) $response->getContent(), true) ?: [];
            throw new ApiError((string) ($body['message'] ?? 'The file could not be downloaded.'), $response->getStatusCode(), $body['error']['code'] ?? null);
        }

        return $response;
    }

    /** The body of a download, for handing to the browser from a Livewire action. */
    public function download(string $uri, array $query = []): string
    {
        $response = $this->raw('GET', $uri, $query);
        if ($response instanceof StreamedResponse || $response instanceof BinaryFileResponse) {
            ob_start();
            $response->sendContent();

            return (string) ob_get_clean();
        }

        return (string) $response->getContent();
    }

    public function send(string $method, string $uri, array $data = [], ?string $errorPrefix = null, ?string $idempotencyKey = null, array $files = []): array
    {
        $response = $this->dispatch($method, $uri, $data, $idempotencyKey, $files);
        $status = $response->getStatusCode();
        $body = json_decode((string) $response->getContent(), true);
        $body = is_array($body) ? $body : [];

        if ($status === 422 && isset($body['errors']) && is_array($body['errors'])) {
            $errors = [];
            foreach ($body['errors'] as $field => $messages) {
                $errors[$errorPrefix ? "{$errorPrefix}.{$field}" : $field] = (array) $messages;
            }
            throw ValidationException::withMessages($errors);
        }

        if ($status >= 400) {
            throw new ApiError((string) ($body['message'] ?? 'The request could not be completed.'), $status,
                $body['error']['code'] ?? null, $body['error']['details'] ?? [], $body['correlation_id'] ?? null);
        }

        return $body;
    }

    private function render(\Throwable $e): Response
    {
        return match (true) {
            $e instanceof ValidationException => response()->json(['message' => $e->getMessage(), 'errors' => $e->errors()], 422),
            $e instanceof BusinessRuleViolation => response()->json(['message' => $e->getMessage(),
                'error' => array_filter(['code' => $e->errorCode, 'details' => $e->details ?: null])], $e->status),
            $e instanceof AuthorizationException => response()->json(['message' => $e->getMessage() ?: 'This action is unauthorized.'], 403),
            $e instanceof AuthenticationException => response()->json(['message' => 'Unauthenticated.'], 401),
            $e instanceof ModelNotFoundException => response()->json(['message' => 'Not found.'], 404),
            $e instanceof HttpExceptionInterface => response()->json(['message' => $e->getMessage() ?: 'The request could not be completed.'], $e->getStatusCode()),
            default => throw $e,
        };
    }

    private function dispatch(string $method, string $uri, array $data, ?string $idempotencyKey, array $files = []): Response
    {
        $outer = app('request');
        $path = '/api/v1/'.ltrim($uri, '/');

        $server = array_intersect_key($outer->server->all(), array_flip(['HTTP_HOST', 'SERVER_NAME', 'SERVER_PORT', 'HTTPS', 'REMOTE_ADDR', 'HTTP_USER_AGENT']));
        $isRead = in_array($method, ['GET', 'HEAD'], true);
        $multipart = $files !== [];
        $sub = Request::create($path, $method, $isRead || $multipart ? $data : [], [], $files, $server, $isRead || $multipart ? null : json_encode($data));
        $sub->headers->set('Accept', 'application/json');
        if (! $isRead) {
            if (! $multipart) {
                $sub->headers->set('Content-Type', 'application/json');
            }
            $sub->headers->set(config('platform.idempotency.header'), $idempotencyKey ?? 'web-'.Str::ulid());
        }
        if ($this->tenant->id() !== null) {
            $sub->headers->set(config('platform.tenancy.header'), $this->tenant->id());
        }
        $sub->attributes->set('web_client', true);

        // Everything the sub-request touches that the page still needs afterwards.
        $organization = $this->tenant->organization();
        $membership = $this->tenant->membership();
        $guard = Auth::getDefaultDriver();
        $correlation = Context::get('correlation_id');
        [$route, $routeRequest] = (fn () => [$this->current, $this->currentRequest])->call($this->router);

        // Livewire's test harness disables middleware for the page request; the API call
        // still runs its own pipeline (bindings, tenant, module, idempotency) as in production.
        $middlewareDisabled = app()->bound('middleware.disable') ? app('middleware.disable') : null;
        unset(app()['middleware.disable']);
        // The API acts as the page's user; replace any user the API guard cached from an earlier call in this process.
        $pageUser = Auth::user();
        $pageUser ? Auth::guard('sanctum')->setUser($pageUser) : Auth::guard('sanctum')->forgetUser();

        try {
            try {
                return $this->kernel->handle($sub);
            } catch (\Throwable $e) {
                // The kernel normally renders errors as JSON. When the exception handler rethrows
                // instead (test harnesses do), turn the exception into the same response.
                return $this->render($e);
            }
        } finally {
            if ($middlewareDisabled !== null) {
                app()->instance('middleware.disable', $middlewareDisabled);
            }
            app()->instance('request', $outer);
            Facade::clearResolvedInstance('request');
            Auth::shouldUse($guard);
            $organization ? $this->tenant->set($organization, $membership) : $this->tenant->clear();
            if ($correlation !== null) {
                Context::add('correlation_id', $correlation);
            }
            (function () use ($route, $routeRequest) {
                $this->current = $route;
                $this->currentRequest = $routeRequest;
            })->call($this->router);
        }
    }
}
