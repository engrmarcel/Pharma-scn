<?php

use App\Http\Middleware\AssignCorrelationId;
use App\Http\Middleware\AuthenticateConnector;
use App\Http\Middleware\EnforceIdempotency;
use App\Http\Middleware\EnsureAccountSecurity;
use App\Http\Middleware\EnsureDeviceActive;
use App\Http\Middleware\EnsureModuleEnabled;
use App\Http\Middleware\EnsureUserIsActive;
use App\Http\Middleware\ResolveTenant;
use App\Http\Middleware\SecurityHeaders;
use App\Support\Exceptions\BusinessRuleViolation;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Support\Facades\Context;
use Symfony\Component\HttpFoundation\Response;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->prepend(AssignCorrelationId::class);
        $middleware->append(SecurityHeaders::class);

        $middleware->alias([
            'tenant' => ResolveTenant::class,
            'module' => EnsureModuleEnabled::class,
            'idempotent' => EnforceIdempotency::class,
            'active' => EnsureUserIsActive::class,
            'secure' => EnsureAccountSecurity::class,
            'device' => EnsureDeviceActive::class,
            'connector' => AuthenticateConnector::class,
        ]);

        // Tenant resolution must follow authentication and precede route-model
        // binding, so bound tenant-owned models are resolved inside the tenant scope.
        // (Authenticate already sorts ahead of SubstituteBindings in the default list.)
        $middleware->prependToPriorityList(SubstituteBindings::class, EnsureUserIsActive::class);
        $middleware->prependToPriorityList(SubstituteBindings::class, ResolveTenant::class);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );

        $exceptions->render(function (BusinessRuleViolation $e, Request $request) {
            if ($request->is('api/*') || $request->expectsJson()) {
                return response()->json([
                    'message' => $e->getMessage(),
                    'error' => array_filter(['code' => $e->errorCode, 'details' => $e->details ?: null]),
                ], $e->status);
            }

            abort($e->status, $e->getMessage());
        });

        $exceptions->dontReport(BusinessRuleViolation::class);

        // Every JSON error carries the correlation id for support/troubleshooting.
        $exceptions->respond(function (Response $response) {
            if ($response instanceof JsonResponse && $response->getStatusCode() >= 400) {
                $data = $response->getData(true);
                if (is_array($data)) {
                    $data['correlation_id'] = Context::get('correlation_id');
                    $response->setData($data);
                }
            }

            return $response;
        });
    })->create();
