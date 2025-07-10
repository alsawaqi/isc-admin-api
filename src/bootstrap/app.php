<?php

 
use Illuminate\Http\Request;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;


return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        //
    })
    ->withExceptions(function (Exceptions $exceptions) {
        //
      $exceptions->render(function (Throwable $e, Request $request) {
    $statusCode = $e instanceof HttpExceptionInterface ? $e->getStatusCode() : 500;

    $isDebug = config('app.debug');

    if ($request->expectsJson()) {
        return response()->json([
            'success' => false,
            'message' => $isDebug ? $e->getMessage() : 'Server Error',
            'trace' => $isDebug ? $e->getTrace() : null,
        ], $statusCode);
    }

    return response()->view('errors.general', [
        'message' => $isDebug ? $e->getMessage() : 'Something went wrong.',
        'trace' => $isDebug ? $e->getTrace() : [],
    ], $statusCode);
});
    })->create();
