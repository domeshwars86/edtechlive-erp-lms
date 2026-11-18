<?php

use App\Http\Controllers\Api\V1\AcademicMasterController;
use App\Http\Controllers\Api\V1\AttendanceController;
use App\Http\Controllers\Api\V1\AttendanceReportController;
use App\Http\Controllers\Api\V1\Auth\AuthenticatedSessionController;
use App\Http\Controllers\Api\V1\Auth\EmailVerificationNotificationController;
use App\Http\Controllers\Api\V1\Auth\NewPasswordController;
use App\Http\Controllers\Api\V1\Auth\PasswordResetLinkController;
use App\Http\Controllers\Api\V1\Auth\RegisteredOrganizationController;
use App\Http\Controllers\Api\V1\Auth\VerifyEmailController;
use App\Http\Controllers\Api\V1\ContactEnquiryController;
use App\Http\Controllers\Api\V1\CourseAssetController;
use App\Http\Controllers\Api\V1\CourseAssignmentController;
use App\Http\Controllers\Api\V1\CourseController;
use App\Http\Controllers\Api\V1\CourseProgressController;
use App\Http\Controllers\Api\V1\CourseProgressReportController;
use App\Http\Controllers\Api\V1\CourseQuizController;
use App\Http\Controllers\Api\V1\DashboardSummaryController;
use App\Http\Controllers\Api\V1\FamilyController;
use App\Http\Controllers\Api\V1\FeeRecordController;
use App\Http\Controllers\Api\V1\MeController;
use App\Http\Controllers\Api\V1\MeetingController;
use App\Http\Controllers\Api\V1\NotificationController;
use App\Http\Controllers\Api\V1\OrganizationController;
use App\Http\Controllers\Api\V1\OrganizationInvitationController;
use App\Http\Controllers\Api\V1\PeopleController;
use App\Http\Controllers\Api\V1\StudentGuardianController;
use App\Http\Controllers\Api\V1\TeachingAssignmentController;
use App\Http\Controllers\Api\V1\TimetableController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function (): void {
    Route::get('/health', fn () => response()->json([
        'status' => 'ok',
        'service' => 'edtechlive-api',
        'version' => 'v1',
    ]))->name('api.v1.health');

    Route::post('/enquiries', [ContactEnquiryController::class, 'store'])
        ->middleware('throttle:6,1')->name('api.v1.enquiries.store');

    Route::middleware(['auth:sanctum', 'verified'])->group(function (): void {
        Route::get('/enquiries', [ContactEnquiryController::class, 'index'])->name('api.v1.enquiries.index');
        Route::patch('/enquiries/{enquiry}', [ContactEnquiryController::class, 'update'])
            ->whereNumber('enquiry')->name('api.v1.enquiries.update');
    });

    Route::prefix('auth')->group(function (): void {
        Route::post('/register', [RegisteredOrganizationController::class, 'store'])
            ->middleware('throttle:api-auth-register')->name('api.v1.auth.register');
        Route::post('/login', [AuthenticatedSessionController::class, 'store'])
            ->middleware('throttle:api-auth-login')->name('api.v1.auth.login');
        Route::post('/email/verification-notification', [EmailVerificationNotificationController::class, 'store'])
            ->middleware('throttle:api-auth-verification')->name('api.v1.auth.verification.send');
        Route::get('/verify-email/{id}/{hash}', VerifyEmailController::class)
            ->middleware(['signed', 'throttle:6,1'])->name('verification.verify');
        Route::post('/forgot-password', [PasswordResetLinkController::class, 'store'])
            ->middleware('throttle:api-auth-password-reset')->name('api.v1.auth.password.email');
        Route::post('/reset-password', [NewPasswordController::class, 'store'])
            ->middleware('throttle:api-auth-password-reset')->name('api.v1.auth.password.update');
        Route::get('/invitations/{token}', [OrganizationInvitationController::class, 'show'])
            ->where('token', '[A-Za-z0-9]{64}')->middleware('throttle:12,1');
        Route::post('/invitations/register', [OrganizationInvitationController::class, 'registerAndAccept'])
            ->middleware('throttle:api-auth-register');
    });

    Route::middleware('auth:sanctum')->group(function (): void {
        Route::get('/me', MeController::class)->name('api.v1.me');
        Route::post('/auth/logout', [AuthenticatedSessionController::class, 'destroy'])->name('api.v1.auth.logout');

        Route::middleware('verified')->group(function (): void {
            Route::get('/organizations', [OrganizationController::class, 'index'])->name('api.v1.organizations.index');
            Route::get('/organizations/{organization}', [OrganizationController::class, 'show'])
                ->whereNumber('organization')->name('api.v1.organizations.show');

            Route::prefix('/organizations/{organization}')->whereNumber('organization')->group(function (): void {
                Route::get('/my/student', [FamilyController::class, 'student']);
                Route::get('/my/student/attendance', [FamilyController::class, 'studentAttendance']);
                Route::get('/my/children', [FamilyController::class, 'children']);
                Route::get('/my/children/{student}/attendance', [FamilyController::class, 'childAttendance'])->whereNumber('student');
                Route::get('/invitations', [OrganizationInvitationController::class, 'index']);
                Route::post('/invitations', [OrganizationInvitationController::class, 'store'])->middleware('throttle:6,1');
                Route::delete('/invitations/{record}', [OrganizationInvitationController::class, 'destroy'])->whereNumber('record');
                Route::post('/invitations/{token}/accept', [OrganizationInvitationController::class, 'acceptExisting'])->where('token', '[A-Za-z0-9]{64}');
                Route::get('/academic-sessions', [AcademicMasterController::class, 'sessions']);
                Route::post('/academic-sessions', [AcademicMasterController::class, 'storeSession']);
                Route::patch('/academic-sessions/{record}', [AcademicMasterController::class, 'updateSession'])->whereNumber('record');
                Route::get('/classes', [AcademicMasterController::class, 'classes']);
                Route::post('/classes', [AcademicMasterController::class, 'storeClass']);
                Route::patch('/classes/{record}', [AcademicMasterController::class, 'updateClass'])->whereNumber('record');
                Route::get('/classes/{class}/sections', [AcademicMasterController::class, 'sections'])->whereNumber('class');
                Route::post('/classes/{class}/sections', [AcademicMasterController::class, 'storeSection'])->whereNumber('class');
                Route::patch('/sections/{record}', [AcademicMasterController::class, 'updateSection'])->whereNumber('record');
                Route::get('/subjects', [AcademicMasterController::class, 'subjects']);
                Route::post('/subjects', [AcademicMasterController::class, 'storeSubject']);
                Route::patch('/subjects/{record}', [AcademicMasterController::class, 'updateSubject'])->whereNumber('record');
                Route::get('/students', [PeopleController::class, 'students']);
                Route::post('/students', [PeopleController::class, 'storeStudent']);
                Route::patch('/students/{record}', [PeopleController::class, 'updateStudent'])->whereNumber('record');
                Route::get('/parent-accounts', [StudentGuardianController::class, 'directory']);
                Route::get('/students/{student}/guardians', [StudentGuardianController::class, 'index'])->whereNumber('student');
                Route::post('/students/{student}/guardians', [StudentGuardianController::class, 'store'])->whereNumber('student');
                Route::delete('/students/{student}/guardians/{guardian}', [StudentGuardianController::class, 'destroy'])
                    ->whereNumber('student')->whereNumber('guardian');
                Route::get('/teachers', [PeopleController::class, 'teachers']);
                Route::post('/teachers', [PeopleController::class, 'storeTeacher']);
                Route::patch('/teachers/{record}', [PeopleController::class, 'updateTeacher'])->whereNumber('record');
                Route::get('/teaching-assignments', [TeachingAssignmentController::class, 'index']);
                Route::post('/teaching-assignments', [TeachingAssignmentController::class, 'store']);
                Route::delete('/teaching-assignments/{record}', [TeachingAssignmentController::class, 'destroy'])->whereNumber('record');
                Route::get('/attendance', [AttendanceController::class, 'index']);
                Route::put('/attendance', [AttendanceController::class, 'store']);
                Route::get('/reports/attendance', [AttendanceReportController::class, 'index']);
                Route::get('/reports/course-progress', [CourseProgressReportController::class, 'index']);
                Route::get('/fees', [FeeRecordController::class, 'index']);
                Route::post('/fees', [FeeRecordController::class, 'store']);
                Route::post('/fees/{fee}/payments', [FeeRecordController::class, 'payment'])->whereNumber('fee');
                Route::get('/meetings', [MeetingController::class, 'index']);
                Route::post('/meetings', [MeetingController::class, 'store'])->middleware('throttle:30,1');
                Route::patch('/meetings/{meeting}', [MeetingController::class, 'update'])->whereNumber('meeting');
                Route::get('/notifications', [NotificationController::class, 'index']);
                Route::post('/notifications/{notification}/read', [NotificationController::class, 'markRead']);
                Route::get('/dashboard/summary', [DashboardSummaryController::class, 'index']);
                Route::get('/courses', [CourseController::class, 'index']);
                Route::post('/courses', [CourseController::class, 'store']);
                Route::patch('/courses/{record}', [CourseController::class, 'update'])->whereNumber('record');
                Route::get('/courses/{course}/lessons', [CourseController::class, 'lessons'])->whereNumber('course');
                Route::get('/courses/{course}/progress', [CourseProgressController::class, 'index'])->whereNumber('course');
                Route::put('/courses/{course}/lessons/{lesson}/progress', [CourseProgressController::class, 'update'])->whereNumber('course')->whereNumber('lesson');
                Route::post('/courses/{course}/lessons', [CourseController::class, 'storeLesson'])->whereNumber('course');
                Route::post('/courses/{course}/enrollments', [CourseController::class, 'enroll'])->whereNumber('course');
                Route::get('/courses/{course}/assignments', [CourseAssignmentController::class, 'index'])->whereNumber('course');
                Route::post('/courses/{course}/assignments', [CourseAssignmentController::class, 'store'])->whereNumber('course');
                Route::patch('/assignments/{record}', [CourseAssignmentController::class, 'update'])->whereNumber('record');
                Route::post('/assignments/{assignment}/submissions', [CourseAssignmentController::class, 'submit'])->whereNumber('assignment');
                Route::get('/assignments/{assignment}/submissions', [CourseAssignmentController::class, 'submissions'])->whereNumber('assignment');
                Route::patch('/submissions/{submission}/grade', [CourseAssignmentController::class, 'grade'])->whereNumber('submission');
                Route::get('/courses/{course}/quizzes', [CourseQuizController::class, 'index'])->whereNumber('course');
                Route::post('/courses/{course}/quizzes', [CourseQuizController::class, 'store'])->whereNumber('course');
                Route::patch('/quizzes/{quiz}', [CourseQuizController::class, 'update'])->whereNumber('quiz');
                Route::post('/quizzes/{quiz}/questions', [CourseQuizController::class, 'addQuestion'])->whereNumber('quiz');
                Route::post('/quizzes/{quiz}/attempts', [CourseQuizController::class, 'startAttempt'])->whereNumber('quiz');
                Route::patch('/quizzes/{quiz}/attempts/{attempt}', [CourseQuizController::class, 'submitAttempt'])->whereNumber('quiz')->whereNumber('attempt');
                Route::get('/quizzes/{quiz}/attempts', [CourseQuizController::class, 'attempts'])->whereNumber('quiz');
                Route::get('/courses/{course}/assets', [CourseAssetController::class, 'index'])->whereNumber('course');
                Route::post('/courses/{course}/assets', [CourseAssetController::class, 'store'])->whereNumber('course');
                Route::patch('/assets/{record}', [CourseAssetController::class, 'update'])->whereNumber('record');
                Route::get('/assets/{record}/download', [CourseAssetController::class, 'download'])->whereNumber('record');
                Route::get('/timetable', [TimetableController::class, 'index']);
                Route::post('/timetable', [TimetableController::class, 'store']);
                Route::patch('/timetable/{record}', [TimetableController::class, 'update'])->whereNumber('record');
            });
        });
    });
});
