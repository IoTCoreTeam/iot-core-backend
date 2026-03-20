<?php

use App\Helpers\ApiResponse;
use App\Helpers\SystemLogHelper;
use App\Http\Middleware\EnsureUserIsAdmin;
use App\Http\Middleware\EnsureUserIsEngineer;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->redirectGuestsTo(function (Request $request) {
            if ($request->is('api/*')) {
                return null;
            }

            return '/login';
        });

        $middleware->alias([
            'admin' => EnsureUserIsAdmin::class,
            'engineer' => EnsureUserIsEngineer::class,
            'admin_or_engineer' => \App\Http\Middleware\EnsureUserIsAdminOrEngineer::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->render(function (\Throwable $e, Request $request) {
            if (! ($request->expectsJson() || $request->is('api/*'))) {
                return null;
            }

            [$status, $message, $error] = match (true) {
                $e instanceof ValidationException => [422, 'Validation failed', $e->errors()],
                $e instanceof ModelNotFoundException => [404, 'Resource not found', null],
                $e instanceof AuthenticationException => [401, 'Unauthenticated', null],
                $e instanceof AuthorizationException => [403, 'Forbidden', null],
                $e instanceof HttpExceptionInterface => [$e->getStatusCode(), $e->getMessage() ?: 'Request failed', null],
                default => [
                    500,
                    config('app.debug') ? $e->getMessage() : 'Internal server error',
                    config('app.debug') ? $e->getMessage() : null,
                ],
            };

            SystemLogHelper::log(
                'api.exception',
                $message,
                [
                    'exception' => get_class($e),
                    'status' => $status,
                    'error' => $error,
                    'path' => $request->path(),
                    'method' => $request->method(),
                ],
                ['level' => $status >= 500 ? 'error' : 'warning']
            );

            return ApiResponse::error($message, $status, $error);
        });
    })->create();
