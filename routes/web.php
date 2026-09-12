<?php

use App\Modules\AuditNotification\ActivityNotificationController;
use App\Modules\CalendarTraining\AvailabilitySlotController;
use App\Modules\CalendarTraining\CalendarDrivingLessonController;
use App\Modules\CalendarTraining\CalendarEventController;
use App\Modules\CalendarTraining\TrainingSessionController;
use App\Modules\CommerceDashboard\CommerceDashboardController;
use App\Modules\CommerceDashboard\DashboardController;
use App\Modules\InternalExams\InternalExamController;
use App\Modules\LearningAccess\LearningAccessController;
use App\Modules\ResourcesCore\ResourceApiMiddleware;
use App\Modules\ResourcesCore\ResourceController;
use App\Modules\StudentFinance\StudentFinanceController;
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

    Route::get('/students/{studentId}/learning-accounts', [LearningAccessController::class, 'accountsList']);
    Route::post('/students/{studentId}/learning-accounts', [LearningAccessController::class, 'accountsCreate']);
    Route::patch('/students/{studentId}/learning-accounts/{accountId}', [LearningAccessController::class, 'accountsUpdate']);
    Route::post('/students/{studentId}/learning-accounts/{accountId}/password-reset', [LearningAccessController::class, 'resetPassword']);
    Route::post('/students/{studentId}/learning-accounts/{accountId}/access-handoffs', [LearningAccessController::class, 'createHandoff']);

    Route::get('/license-products', [LearningAccessController::class, 'products']);
    Route::get('/license-products/{productId}/languages', [LearningAccessController::class, 'productLanguages']);
    Route::get('/license-inventory', [LearningAccessController::class, 'inventory']);
    Route::get('/license-assignments', [LearningAccessController::class, 'assignments']);
    Route::post('/license-assignments', [LearningAccessController::class, 'assignmentCreate']);
    Route::get('/license-assignments/{assignmentId}', [LearningAccessController::class, 'assignmentGet']);
    Route::post('/license-assignments/{assignmentId}/activate', [LearningAccessController::class, 'assignmentActivate']);
    Route::post('/license-assignments/{assignmentId}/revoke-unactivated', [LearningAccessController::class, 'assignmentRevoke']);
    Route::get('/students/{studentId}/learning-accounts/{accountId}/license-assignments', [LearningAccessController::class, 'history']);

    Route::get('/students/{studentId}/charges', [StudentFinanceController::class, 'chargesList']);
    Route::post('/students/{studentId}/charges', [StudentFinanceController::class, 'chargesCreate']);
    Route::post('/students/{studentId}/charges/{chargeId}/cancel', [StudentFinanceController::class, 'chargesCancel']);
    Route::get('/students/{studentId}/payments', [StudentFinanceController::class, 'paymentsList']);
    Route::post('/students/{studentId}/payments', [StudentFinanceController::class, 'paymentsRecord']);
    Route::post('/students/{studentId}/payments/{paymentId}/reverse', [StudentFinanceController::class, 'paymentsReverse']);
    Route::get('/students/{studentId}/finance-summary', [StudentFinanceController::class, 'summary']);

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

    Route::get('/availability-slots', [AvailabilitySlotController::class, 'list']);
    Route::post('/availability-slots', [AvailabilitySlotController::class, 'create']);
    Route::patch('/availability-slots/{slotId}', [AvailabilitySlotController::class, 'update']);
    Route::post('/availability-slots/{slotId}/book', [AvailabilitySlotController::class, 'book']);
    Route::post('/availability-slots/{slotId}/formalize', [AvailabilitySlotController::class, 'formalize']);
    Route::post('/availability-slots/{slotId}/cancel', [AvailabilitySlotController::class, 'cancel']);

    Route::post('/calendar/driving-lessons', [CalendarDrivingLessonController::class, 'create']);
    Route::get('/calendar/driving-lessons/{sessionId}', [CalendarDrivingLessonController::class, 'get']);

    Route::get('/calendar/events', [CalendarEventController::class, 'list']);
    Route::post('/calendar/events', [CalendarEventController::class, 'create']);
    Route::get('/calendar/events/{eventId}', [CalendarEventController::class, 'get']);
    Route::patch('/calendar/events/{eventId}', [CalendarEventController::class, 'update']);
    Route::post('/calendar/events/{eventId}/cancel', [CalendarEventController::class, 'cancel']);
    Route::post('/calendar/events/{eventId}/complete', [CalendarEventController::class, 'complete']);

    Route::get('/orders', [CommerceDashboardController::class, 'ordersList']);
    Route::get('/orders/{orderId}', [CommerceDashboardController::class, 'ordersGet']);
    Route::post('/orders/{orderId}/payments', [CommerceDashboardController::class, 'orderPaymentsCreate']);
    Route::get('/payments', [CommerceDashboardController::class, 'paymentsList']);
    Route::get('/purchase-history', [CommerceDashboardController::class, 'purchaseHistoryList']);
    Route::get('/dashboard', [DashboardController::class, 'get']);

    Route::get('/activity', [ActivityNotificationController::class, 'activityList']);
    Route::get('/notifications', [ActivityNotificationController::class, 'notificationsList']);
    Route::post('/notifications/{notificationId}/read', [ActivityNotificationController::class, 'notificationsMarkRead']);

    Route::get('/internal-exam/inventory', [InternalExamController::class, 'inventory']);
    Route::post('/internal-exam/inventory-adjustments', [InternalExamController::class, 'inventoryAdjust']);
    Route::get('/internal-exam/capabilities', [InternalExamController::class, 'capabilities']);
    Route::get('/internal-exam/subjects', [InternalExamController::class, 'subjects']);
    Route::get('/course-enrollments/{courseEnrollmentId}/internal-exam-attempts', [InternalExamController::class, 'attemptsForCourse']);
    Route::post('/course-enrollments/{courseEnrollmentId}/internal-exam-attempts', [InternalExamController::class, 'attemptCreate']);
    Route::get('/internal-exam-attempts/{attemptId}', [InternalExamController::class, 'attemptGet']);
    Route::patch('/internal-exam-attempts/{attemptId}', [InternalExamController::class, 'attemptPatch']);
    Route::post('/internal-exam-attempts/{attemptId}/accesses', [InternalExamController::class, 'accessCreate']);
    Route::post('/internal-exam-accesses/{accessId}/send', [InternalExamController::class, 'accessSend']);
    Route::post('/internal-exam-accesses/{accessId}/revoke', [InternalExamController::class, 'accessRevoke']);
    Route::post('/internal-exam-accesses/{accessId}/start', [InternalExamController::class, 'accessStart']);
    Route::get('/exam-stations', [InternalExamController::class, 'stationsList']);
    Route::post('/exam-stations', [InternalExamController::class, 'stationRegister']);
    Route::post('/exam-stations/{stationId}/credential', [InternalExamController::class, 'stationCredentialProvision']);
    Route::post('/exam-stations/{stationId}/credential/rotate', [InternalExamController::class, 'stationCredentialRotate']);
    Route::post('/exam-stations/heartbeat', [InternalExamController::class, 'stationHeartbeat']);
    Route::post('/internal-exam-stations/heartbeat', [InternalExamController::class, 'stationHeartbeat']);
    Route::post('/internal-exam-attempts/{attemptId}/station-transfer', [InternalExamController::class, 'stationTransfer']);
    Route::post('/internal-exam-attempts/{attemptId}/submit', [InternalExamController::class, 'attemptSubmit']);
    Route::post('/internal-exam-attempts/{attemptId}/technical-abort', [InternalExamController::class, 'technicalAbort']);
    Route::get('/internal-exam-attempts/{attemptId}/result', [InternalExamController::class, 'result']);
    Route::get('/internal-exam-attempts/{attemptId}/questions', [InternalExamController::class, 'questions']);
    Route::get('/internal-exam-attempts/{attemptId}/documents/answer-sheet.pdf', [InternalExamController::class, 'answerSheetPdf']);
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
Route::view('/licencje/panel', 'app');
Route::view('/egzamin-wewnetrzny/panel', 'app');
