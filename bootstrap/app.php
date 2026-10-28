<?php

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Railway menaruh aplikasi di belakang proxy yang mengakhiri HTTPS.
        $middleware->trustProxies(at: '*');
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );

        // Semua error API berbentuk { "error": { "code", "message" } } supaya
        // aplikasi cukup membaca error.code.
        $error = fn (string $code, string $message, int $status, array $extra = []) => response()->json(
            ['error' => ['code' => $code, 'message' => $message] + $extra], $status,
        );
        $exceptions->render(function (ValidationException $e, Request $r) use ($error) {
            if ($r->is('api/*')) {
                return $error('validation_failed', collect($e->errors())->flatten()->first(), 422, ['fields' => $e->errors()]);
            }
        });
        $exceptions->render(function (AuthenticationException $e, Request $r) use ($error) {
            if ($r->is('api/*')) {
                return $error('unauthenticated', 'Sesi berakhir. Silakan masuk lagi.', 401);
            }
        });
        $exceptions->render(function (ThrottleRequestsException $e, Request $r) use ($error) {
            if ($r->is('api/*')) {
                return $error('too_many_requests', 'Terlalu banyak percobaan. Tunggu sebentar lalu coba lagi.', 429);
            }
        });
        $exceptions->render(function (AuthorizationException|AccessDeniedHttpException $e, Request $r) use ($error) {
            if ($r->is('api/*')) {
                return $error('forbidden', 'Anda tidak berhak mengakses data ini.', 403);
            }
        });
        $exceptions->render(function (NotFoundHttpException $e, Request $r) use ($error) {
            if ($r->is('api/*')) {
                return $error('not_found', 'Data tidak ditemukan.', 404);
            }
        });
        $exceptions->render(function (HttpExceptionInterface $e, Request $r) use ($error) {
            if ($r->is('api/*')) {
                return $error('http_error', $e->getMessage() ?: 'Permintaan tidak dapat diproses.', $e->getStatusCode());
            }
        });
    })->create();
