<?php

namespace App\Exceptions;

use Illuminate\Foundation\Exceptions\Handler as ExceptionHandler;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Validation\ValidationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;
use Throwable;

class Handler extends ExceptionHandler
{
    /**
     * A list of exception types with their corresponding custom log levels.
     *
     * @var array<class-string<\Throwable>, \Psr\Log\LogLevel::*>
     */
    protected $levels = [
        //
    ];

    /**
     * A list of the exception types that are not reported.
     *
     * @var array<int, class-string<\Throwable>>
     */
    protected $dontReport = [
        //
    ];

    /**
     * A list of the inputs that are never flashed to the session on validation exceptions.
     *
     * @var array<int, string>
     */
    protected $dontFlash = [
        'current_password',
        'password',
        'password_confirmation',
    ];

    /**
     * Register the exception handling callbacks for the application.
     */
    public function register(): void
    {
        $this->reportable(function (Throwable $e) {
            // Log to audit system for security-related exceptions
            if ($this->isSecurityException($e)) {
                \App\Services\AuditLogService::logSecurity('exception_thrown', null, [
                    'exception' => get_class($e),
                    'message' => $e->getMessage(),
                    'file' => $e->getFile(),
                    'line' => $e->getLine(),
                ]);
            }
        });

        $this->renderable(function (Throwable $e, $request) {
            if ($request->is('api/*')) {
                return $this->handleApiException($e, $request);
            }
        });
    }

    /**
     * Handle API exceptions with standardized format
     */
    protected function handleApiException(Throwable $e, $request)
    {
        // Authentication exceptions
        if ($e instanceof AuthenticationException) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthenticated',
                'error_code' => 'UNAUTHENTICATED',
            ], 401);
        }

        // Validation exceptions
        if ($e instanceof ValidationException) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'error_code' => 'VALIDATION_ERROR',
                'errors' => $e->errors(),
            ], 422);
        }

        // Model not found
        if ($e instanceof ModelNotFoundException) {
            return response()->json([
                'success' => false,
                'message' => 'Resource not found',
                'error_code' => 'NOT_FOUND',
            ], 404);
        }

        // Not found (route)
        if ($e instanceof NotFoundHttpException) {
            return response()->json([
                'success' => false,
                'message' => 'Endpoint not found',
                'error_code' => 'ENDPOINT_NOT_FOUND',
            ], 404);
        }

        // Access denied
        if ($e instanceof AccessDeniedHttpException) {
            return response()->json([
                'success' => false,
                'message' => 'Access denied',
                'error_code' => 'ACCESS_DENIED',
            ], 403);
        }

        // Rate limiting
        if ($e instanceof TooManyRequestsHttpException) {
            return response()->json([
                'success' => false,
                'message' => 'Too many requests. Please try again later.',
                'error_code' => 'RATE_LIMIT_EXCEEDED',
                'retry_after' => $e->getHeaders()['Retry-After'] ?? null,
            ], 429);
        }

        // Database exceptions
        if ($this->isDatabaseException($e)) {
            \Log::error('Database error', [
                'exception' => get_class($e),
                'message' => $e->getMessage(),
                'sql' => method_exists($e, 'getSql') ? $e->getSql() : null,
            ]);

            return response()->json([
                'success' => false,
                'message' => config('app.debug') ? $e->getMessage() : 'Database error occurred',
                'error_code' => 'DATABASE_ERROR',
            ], 500);
        }

        // Generic server error
        if (config('app.debug')) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
                'error_code' => 'SERVER_ERROR',
                'exception' => get_class($e),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'trace' => $e->getTraceAsString(),
            ], 500);
        }

        return response()->json([
            'success' => false,
            'message' => 'An error occurred. Please try again later.',
            'error_code' => 'SERVER_ERROR',
        ], 500);
    }

    /**
     * Check if exception is security-related
     */
    protected function isSecurityException(Throwable $e): bool
    {
        return $e instanceof AuthenticationException ||
               $e instanceof AccessDeniedHttpException ||
               $e instanceof TooManyRequestsHttpException ||
               str_contains($e->getMessage(), 'IDOR') ||
               str_contains($e->getMessage(), 'Tenant mismatch');
    }

    /**
     * Check if exception is database-related
     */
    protected function isDatabaseException(Throwable $e): bool
    {
        return $e instanceof \PDOException ||
               $e instanceof \Illuminate\Database\QueryException ||
               str_contains(get_class($e), 'Database');
    }
}
