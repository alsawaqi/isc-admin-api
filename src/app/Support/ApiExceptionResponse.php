<?php

namespace App\Support;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

final class ApiExceptionResponse
{
    public static function message(Throwable $exception, int $statusCode, bool $debug): string
    {
        if ($debug) {
            return self::messageOrFallback($exception->getMessage(), $statusCode);
        }

        return match (true) {
            $exception instanceof ValidationException => 'The submitted data is invalid.',
            $exception instanceof AuthenticationException => 'Unauthenticated.',
            $exception instanceof AuthorizationException => self::messageOrDefault(
                $exception->getMessage(),
                'This action is unauthorized.'
            ),
            $exception instanceof HttpExceptionInterface && $statusCode === 403 => self::messageOrDefault(
                $exception->getMessage(),
                'Forbidden.'
            ),
            $statusCode === 413 => 'The uploaded file is too large.',
            $statusCode === 419 => 'Your session has expired. Please sign in again.',
            $statusCode === 429 => 'Too many requests. Please try again later.',
            $exception instanceof HttpExceptionInterface && in_array($statusCode, [400, 409, 422], true) =>
                self::messageOrFallback($exception->getMessage(), $statusCode),
            $statusCode >= 500 => 'Server Error',
            default => self::fallback($statusCode),
        };
    }

    private static function messageOrFallback(string $message, int $statusCode): string
    {
        return self::messageOrDefault($message, self::fallback($statusCode));
    }

    private static function messageOrDefault(string $message, string $default): string
    {
        $message = trim($message);

        return $message !== '' ? $message : $default;
    }

    private static function fallback(int $statusCode): string
    {
        return Response::$statusTexts[$statusCode] ?? 'Request failed.';
    }
}
