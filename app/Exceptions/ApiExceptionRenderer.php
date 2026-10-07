<?php

namespace App\Exceptions;

use App\Support\ApiResponse;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Throwable;

/**
 * Central exception → HTTP mapping.
 *
 * - /api/v2/*          → JSON envelope with a correct status code, never a stack trace in production.
 * - /api/v1/legacy/*   → the legacy scripts' plain-text "error" body (legacy apps parse strings).
 * - anything else      → Laravel default.
 */
final class ApiExceptionRenderer
{
    public static function render(Throwable $e, Request $request): mixed
    {
        if ($request->is('api/v1/legacy/*')) {
            if ($e instanceof ThrottleRequestsException) {
                return response('error', 429)->header('Content-Type', 'text/html; charset=UTF-8');
            }
            if ($e instanceof HttpExceptionInterface && $e->getStatusCode() === 404) {
                return response('error', 404)->header('Content-Type', 'text/html; charset=UTF-8');
            }

            // Legacy clients treat any body without "success"/expected tokens as failure.
            return response('error', $e instanceof HttpExceptionInterface ? $e->getStatusCode() : 500)
                ->header('Content-Type', 'text/html; charset=UTF-8');
        }

        if (! $request->is('api/*')) {
            return null; // default rendering
        }

        return match (true) {
            $e instanceof ValidationException => ApiResponse::error(
                'The given data was invalid.', 422, $e->errors()
            ),
            $e instanceof AuthenticationException => ApiResponse::error('Unauthenticated.', 401),
            $e instanceof AuthorizationException, $e instanceof AccessDeniedHttpException => ApiResponse::error(
                'This action is unauthorized.', 403
            ),
            $e instanceof ModelNotFoundException, $e instanceof NotFoundHttpException => ApiResponse::error('Not found.', 404),
            $e instanceof MethodNotAllowedHttpException => ApiResponse::error('Method not allowed.', 405),
            $e instanceof ThrottleRequestsException => ApiResponse::error(
                'Too many requests. Please try again later.',
                429,
                null,
                ['retry_after' => (int) ($e->getHeaders()['Retry-After'] ?? 60)],
                $e->getHeaders(),
            ),
            $e instanceof DomainException => ApiResponse::error($e->getMessage(), $e->status, $e->errors),
            $e instanceof HttpExceptionInterface => ApiResponse::error(
                $e->getMessage() ?: 'Request could not be completed.', $e->getStatusCode(), null, [], $e->getHeaders()
            ),
            default => ApiResponse::error(
                config('app.debug') ? $e->getMessage() : 'Server error.',
                500,
                null,
                config('app.debug') ? ['exception' => $e::class] : [],
            ),
        };
    }
}
