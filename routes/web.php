<?php

use App\Modules\ResourcesCore\ResourceApiMiddleware;
use App\Modules\ResourcesCore\ResourceController;
use Illuminate\Support\Facades\Route;

Route::middleware(ResourceApiMiddleware::class)->prefix('api/v1')->group(function (): void {
    Route::get('/locations', [ResourceController::class, 'locationsList']);
    Route::post('/locations', [ResourceController::class, 'locationsCreate']);
    Route::get('/locations/{locationId}', [ResourceController::class, 'locationsGet']);
    Route::patch('/locations/{locationId}', [ResourceController::class, 'locationsUpdate']);
    Route::post('/locations/{locationId}/archive', [ResourceController::class, 'locationsArchive']);
    Route::post('/locations/{locationId}/restore', [ResourceController::class, 'locationsRestore']);
    Route::get('/location-types', [ResourceController::class, 'locationTypes']);
    Route::get('/geography/cities', [ResourceController::class, 'cities']);
    Route::get('/driving-categories', [ResourceController::class, 'drivingCategories']);

    Route::get('/staff', [ResourceController::class, 'staffList']);
    Route::post('/staff', [ResourceController::class, 'staffCreate']);
    Route::get('/staff/{staffId}', [ResourceController::class, 'staffGet']);
    Route::patch('/staff/{staffId}', [ResourceController::class, 'staffUpdate']);
    Route::post('/staff/{staffId}/archive', [ResourceController::class, 'staffArchive']);
    Route::post('/staff/{staffId}/restore', [ResourceController::class, 'staffRestore']);
    Route::post('/staff/{staffId}/user-account', [ResourceController::class, 'staffAccountCreate']);
    Route::delete('/staff/{staffId}/user-account', [ResourceController::class, 'staffAccountRevoke']);
    Route::get('/staff/{staffId}/permissions', [ResourceController::class, 'staffPermissionsGet']);
    Route::put('/staff/{staffId}/permissions', [ResourceController::class, 'staffPermissionsReplace']);
    Route::get('/staff-types', [ResourceController::class, 'staffTypes']);

    Route::get('/vehicles', [ResourceController::class, 'vehiclesList']);
    Route::post('/vehicles', [ResourceController::class, 'vehiclesCreate']);
    Route::get('/vehicles/{vehicleId}', [ResourceController::class, 'vehiclesGet']);
    Route::patch('/vehicles/{vehicleId}', [ResourceController::class, 'vehiclesUpdate']);
    Route::post('/vehicles/{vehicleId}/archive', [ResourceController::class, 'vehiclesArchive']);
    Route::post('/vehicles/{vehicleId}/restore', [ResourceController::class, 'vehiclesRestore']);
    Route::get('/vehicles/{vehicleId}/documents', [ResourceController::class, 'vehicleDocumentsList']);
    Route::post('/vehicles/{vehicleId}/documents', [ResourceController::class, 'vehicleDocumentsCreate']);
    Route::patch('/vehicles/{vehicleId}/documents/{documentId}', [ResourceController::class, 'vehicleDocumentsUpdate']);
});

Route::view('/', 'app');
Route::view('/lokalizacje', 'app');
Route::view('/pracownicy', 'app');
Route::view('/pracownicy/{staffId}', 'app');
Route::view('/pojazdy', 'app');
Route::view('/pojazdy/{vehicleId}', 'app');
