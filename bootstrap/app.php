<?php

use Illuminate\Auth\AuthenticationException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        api: __DIR__.'/../routes/api.php',
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        $middleware->redirectGuestsTo(function (Request $request) {
            if ($request->is('api/*') || $request->expectsJson()) {
                return null;
            }

            return '/';
        });

        $middleware->alias([
            'role' => \App\Http\Middleware\EnsureUserHasRole::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        $exceptions->render(function (AuthenticationException $exception, Request $request) {
            if ($request->is('api/*') || $request->expectsJson()) {
                return response()->json([
                    'message' => 'Unauthenticated.',
                ], 401);
            }
        });

        $exceptions->render(function (\Symfony\Component\HttpKernel\Exception\HttpException $exception, Request $request) {
            if ($request->is('api/*') || $request->expectsJson()) {
                return response()->json([
                    'message' => $exception->getMessage() ?: 'Terjadi kesalahan.',
                ], $exception->getStatusCode());
            }
        });

        $exceptions->render(function (\Illuminate\Validation\ValidationException $exception, Request $request) {
            if ($request->is('api/*') || $request->expectsJson()) {
                return response()->json([
                    'message' => $exception->getMessage(),
                    'errors' => $exception->errors(),
                ], 422);
            }
        });

        $exceptions->render(function (\Throwable $exception, Request $request) {
            if ($request->is('api/*') || $request->expectsJson()) {
                return response()->json([
                    'message' => $exception->getMessage(),
                    'error_type' => get_class($exception),
                    'file' => basename($exception->getFile()) . ':' . $exception->getLine(),
                ], 500);
            }
        });
    })->create();
