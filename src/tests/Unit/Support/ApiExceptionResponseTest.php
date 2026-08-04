<?php

namespace Tests\Unit\Support;

use App\Support\ApiExceptionResponse;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\HttpException;

final class ApiExceptionResponseTest extends TestCase
{
    public function test_production_permission_denial_keeps_its_safe_actionable_message(): void
    {
        $exception = new HttpException(403, 'You do not have permission to import product categories.');

        $this->assertSame(
            'You do not have permission to import product categories.',
            ApiExceptionResponse::message($exception, 403, false)
        );
    }

    public function test_production_internal_error_hides_exception_details(): void
    {
        $exception = new RuntimeException('Database password leaked here');

        $this->assertSame('Server Error', ApiExceptionResponse::message($exception, 500, false));
    }

    public function test_production_not_found_does_not_expose_raw_model_details(): void
    {
        $exception = new HttpException(404, 'No query results for model App\\Models\\User 42');

        $this->assertSame('Not Found', ApiExceptionResponse::message($exception, 404, false));
    }

    public function test_rate_limit_has_a_clear_retry_message(): void
    {
        $exception = new HttpException(429, 'Too Many Attempts.');

        $this->assertSame(
            'Too many requests. Please try again later.',
            ApiExceptionResponse::message($exception, 429, false)
        );
    }

    public function test_debug_mode_keeps_the_original_exception_message(): void
    {
        $exception = new RuntimeException('Detailed developer message');

        $this->assertSame(
            'Detailed developer message',
            ApiExceptionResponse::message($exception, 500, true)
        );
    }
}
