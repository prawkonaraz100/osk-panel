<?php

use App\Console\Commands\GoLiveEvidenceCommand;
use App\Console\Commands\OperationalAlertSmokeCommand;
use App\Console\Commands\ProductionPreflightCommand;
use App\Console\Commands\RetentionRunCommand;
use App\Modules\ResourcesCore\ResourceDomainException;
use App\Support\Operations\ProductionHealthController;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

return Application::configure(basePath: dirname(__DIR__))
    ->withCommands([GoLiveEvidenceCommand::class, OperationalAlertSmokeCommand::class, ProductionPreflightCommand::class, RetentionRunCommand::class])
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        then: function (): void {
            Route::get('/health/live', [ProductionHealthController::class, 'live']);
            Route::get('/health/ready', [ProductionHealthController::class, 'ready']);
        },
    )
    ->withMiddleware(function (Middleware $middleware): void {
        //
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
        $exceptions->render(function (ResourceDomainException $exception, Request $request) {
            if (! $request->is('api/*') && ! $request->expectsJson()) {
                return null;
            }

            return response()->json([
                'error' => [
                    'code' => $exception->machineCode,
                    'message' => $exception->getMessage(),
                    'request_id' => (string) $request->attributes->get('request_id'),
                ],
            ], $exception->httpStatus);
        });
    })->create();
