<?php

use App\Modules\CalendarTraining\TrainingSessionController;
use App\Modules\ResourcesCore\ResourceApiMiddleware;
use App\Modules\ResourcesCore\ResourceController;
use App\Modules\StudentsCourses\StudentCourseController;
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

    Route::get('/students', [StudentCourseController::class, 'studentsList']);
    Route::post('/students', [StudentCourseController::class, 'studentsCreate']);
    Route::get('/students/{studentId}', [StudentCourseController::class, 'studentsGet']);
    Route::patch('/students/{studentId}', [StudentCourseController::class, 'studentsUpdate']);
    Route::get('/students/{studentId}/preview', [StudentCourseController::class, 'studentsPreview']);
    Route::post('/students/{studentId}/archive', [StudentCourseController::class, 'studentsArchive']);
    Route::post('/students/{studentId}/restore', [StudentCourseController::class, 'studentsRestore']);

    Route::get('/students/{studentId}/course-enrollments', [StudentCourseController::class, 'coursesList']);
    Route::post('/students/{studentId}/course-enrollments', [StudentCourseController::class, 'coursesCreate']);
    Route::get('/course-enrollments/{courseEnrollmentId}', [StudentCourseController::class, 'coursesGet']);
    Route::patch('/course-enrollments/{courseEnrollmentId}', [StudentCourseController::class, 'coursesUpdate']);
    Route::post('/course-enrollments/{courseEnrollmentId}/cancel', [StudentCourseController::class, 'coursesCancel']);
    Route::post('/course-enrollments/{courseEnrollmentId}/restore', [StudentCourseController::class, 'coursesRestore']);
    Route::post('/course-enrollments/{courseEnrollmentId}/stage-transitions', [StudentCourseController::class, 'coursesChangeStage']);
    Route::get('/course-enrollments/{courseEnrollmentId}/requirements', [StudentCourseController::class, 'courseRequirementsGet']);
    Route::post('/course-enrollments/{courseEnrollmentId}/requirement-context', [StudentCourseController::class, 'courseRequirementsUpdateContext']);
    Route::post('/course-enrollments/{courseEnrollmentId}/exemption-decisions', [StudentCourseController::class, 'courseRequirementsAddExemptionDecision']);
    Route::get('/course-enrollments/{courseEnrollmentId}/recognized-external-training', [StudentCourseController::class, 'externalTrainingList']);
    Route::post('/course-enrollments/{courseEnrollmentId}/recognized-external-training', [StudentCourseController::class, 'externalTrainingCreate']);
    Route::post('/course-enrollments/{courseEnrollmentId}/recognized-external-training/{recordId}/revoke', [StudentCourseController::class, 'externalTrainingRevoke']);

    Route::get('/course-enrollments/{courseEnrollmentId}/training-sessions', [TrainingSessionController::class, 'list']);
    Route::post('/course-enrollments/{courseEnrollmentId}/training-sessions', [TrainingSessionController::class, 'create']);
    Route::get('/training-sessions/{sessionId}', [TrainingSessionController::class, 'get']);
    Route::patch('/training-sessions/{sessionId}', [TrainingSessionController::class, 'update']);
    Route::put('/training-sessions/{sessionId}/attendance', [TrainingSessionController::class, 'attendance']);
    Route::post('/training-sessions/{sessionId}/complete', [TrainingSessionController::class, 'complete']);
    Route::post('/training-sessions/{sessionId}/cancel', [TrainingSessionController::class, 'cancel']);
    Route::get('/course-enrollments/{courseEnrollmentId}/training-hours', [TrainingSessionController::class, 'hours']);
    Route::post('/course-enrollments/{courseEnrollmentId}/training-hour-corrections', [TrainingSessionController::class, 'correctHours']);
});

Route::view('/', 'app');
Route::view('/kursanci', 'app');
Route::view('/kursanci/{studentId}', 'app');
Route::view('/lokalizacje', 'app');
Route::view('/pracownicy', 'app');
Route::view('/pracownicy/{staffId}', 'app');
Route::view('/pojazdy', 'app');
Route::view('/pojazdy/{vehicleId}', 'app');
Route::view('/kalendarz', 'app');
