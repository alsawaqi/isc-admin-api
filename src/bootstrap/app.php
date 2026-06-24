<?php

 
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
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
        channels: __DIR__.'/../routes/channels.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        //
    })
    ->withExceptions(function (Exceptions $exceptions) {
        //
      $exceptions->render(function (Throwable $e, Request $request) {
    $statusCode = match (true) {
        $e instanceof ValidationException => $e->status,
        $e instanceof AuthenticationException => 401,
        $e instanceof AuthorizationException => 403,
        $e instanceof HttpExceptionInterface => $e->getStatusCode(),
        default => 500,
    };

    $isDebug = config('app.debug');

    if ($request->expectsJson()) {
        $payload = [
            'success' => false,
            'message' => $isDebug ? $e->getMessage() : 'Server Error',
            'trace' => $isDebug ? $e->getTrace() : null,
        ];

        if ($e instanceof ValidationException) {
            $payload['errors'] = $e->errors();
        }

        return response()->json($payload, $statusCode);
    }

    if (! view()->exists('errors.general')) {
        return response($isDebug ? $e->getMessage() : 'Something went wrong.', $statusCode);
    }

    return response()->view('errors.general', [
        'message' => $isDebug ? $e->getMessage() : 'Something went wrong.',
        'trace' => $isDebug ? $e->getTrace() : [],
    ], $statusCode);
});
    })->create();
