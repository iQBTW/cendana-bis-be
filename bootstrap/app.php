<?php

use App\Http\Middleware\AttachTokenFromCookie;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__ . '/../routes/web.php',
        api: __DIR__ . '/../routes/api.php',
        commands: __DIR__ . '/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->trustProxies(at: [
            '127.0.0.1',
            '::1',
            '172.18.0.0/16'
        ]);

        $middleware->append(AttachTokenFromCookie::class);

        $middleware->redirectGuestsTo(function (Request $request) {
            if ($request->is('api/*')) {
                return null;
            }
        });

        $middleware->alias([
            'role' => \Spatie\Permission\Middleware\RoleMiddleware::class,
            'role_or_permission' => \Spatie\Permission\Middleware\RoleOrPermissionMiddleware::class,
            'recaptcha' => \App\Http\Middleware\VerifyRecaptcha::class,
            'permission' => \App\Http\Middleware\Permission::class,
            'client.binding' => \App\Http\Middleware\EnforceClientBinding::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->render(function (AuthenticationException $e, Request $request) {
            // Paksa API untuk selalu JSON
            if ($request->is('api/*') || $request->expectsJson() || $request->wantsJson()) {
                return response()->json([
                    'meta' => [
                        'message' => 'Unauthenticated',
                        'status' => false,
                        'code' => 401,
                    ],
                    
                    'data' => []
                ], 401);
            }

            return null;
        });

        $exceptions->render(function (ModelNotFoundException $e, Request $request) {
            if ($request->is('api/*')) {
                return response()->json([
                    'meta' => [
                        'message' => 'Data tidak ditemukan',
                        'status' => false,
                        'code' => 404,
                    ],
                    'data' => []
                ], 404);
            }

            return null;
        });
    })->create();