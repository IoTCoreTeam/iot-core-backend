<?php

namespace App\Exceptions;

use App\Helpers\ApiResponse;
use App\Helpers\SystemLogHelper;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Exceptions\Handler as ExceptionHandler;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

class Handler extends ExceptionHandler
{
    /**
     * Các exception không cần báo cáo
     */
    protected $dontReport = [];

    /**
     * Các field không flash khi validation lỗi
     */
    protected $dontFlash = [
        'current_password',
        'password',
        'password_confirmation',
    ];

    /**
     * Report exception (log lỗi)
     */
    public function report(Throwable $e): void
    {
        parent::report($e);
    }

    /**
     * Render exception thành HTTP response
     */
    public function render($request, Throwable $e)
    {
        if ($request->expectsJson()) {
            [$status, $message, $error] = $this->resolveJsonException($e);

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
        }

        return parent::render($request, $e);
    }

    private function resolveJsonException(Throwable $e): array
    {
        if ($e instanceof ValidationException) {
            return [422, 'Validation failed', $e->errors()];
        }

        if ($e instanceof ModelNotFoundException) {
            return [404, 'Resource not found', null];
        }

        if ($e instanceof AuthenticationException) {
            return [401, 'Unauthenticated', null];
        }

        if ($e instanceof AuthorizationException) {
            return [403, 'Forbidden', null];
        }

        if ($e instanceof HttpExceptionInterface) {
            $status = $e->getStatusCode();
            $message = $e->getMessage() ?: 'Request failed';

            return [$status, $message, null];
        }

        $message = config('app.debug') ? $e->getMessage() : 'Internal server error';
        $error = config('app.debug') ? $e->getMessage() : null;

        return [500, $message, $error];
    }
}
