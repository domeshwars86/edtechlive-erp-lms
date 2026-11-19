<?php

use App\Http\Controllers\Api\V1\AcademicBatchController;
use App\Http\Controllers\Api\V1\AcademicExamController;
use App\Http\Controllers\Api\V1\AcademicExamReportController;
use App\Http\Controllers\Api\V1\AcademicMasterController;
use App\Http\Controllers\Api\V1\AnnouncementController;
use App\Http\Controllers\Api\V1\AssessmentReportController;
use App\Http\Controllers\Api\V1\AttendanceController;
use App\Http\Controllers\Api\V1\AttendanceReportController;
use App\Http\Controllers\Api\V1\Auth\AuthenticatedSessionController;
use App\Http\Controllers\Api\V1\Auth\EmailVerificationNotificationController;
use App\Http\Controllers\Api\V1\Auth\NewPasswordController;
use App\Http\Controllers\Api\V1\Auth\PasswordResetLinkController;
use App\Http\Controllers\Api\V1\Auth\RegisteredOrganizationController;
use App\Http\Controllers\Api\V1\Auth\VerifyEmailController;
use App\Http\Controllers\Api\V1\BankReconciliationController;
use App\Http\Controllers\Api\V1\ContactEnquiryController;
use App\Http\Controllers\Api\V1\CourseAssetController;
use App\Http\Controllers\Api\V1\CourseAssignmentController;
use App\Http\Controllers\Api\V1\CourseController;
use App\Http\Controllers\Api\V1\CourseModuleController;
use App\Http\Controllers\Api\V1\CourseProgressController;
use App\Http\Controllers\Api\V1\CourseProgressReportController;
use App\Http\Controllers\Api\V1\CourseQuizController;
use App\Http\Controllers\Api\V1\DashboardActivityController;
use App\Http\Controllers\Api\V1\DashboardSummaryController;
use App\Http\Controllers\Api\V1\EnrollmentReportController;
use App\Http\Controllers\Api\V1\FamilyController;
use App\Http\Controllers\Api\V1\FeeCategoryController;
use App\Http\Controllers\Api\V1\FeeRecordController;
use App\Http\Controllers\Api\V1\FinanceExpenseController;
use App\Http\Controllers\Api\V1\FinanceReportController;
use App\Http\Controllers\Api\V1\HealthController;
use App\Http\Controllers\Api\V1\MeController;
use App\Http\Controllers\Api\V1\MeetingController;
use App\Http\Controllers\Api\V1\NotificationController;
use App\Http\Controllers\Api\V1\NotificationDeliveryController;
use App\Http\Controllers\Api\V1\NotificationPreferencesController;
use App\Http\Controllers\Api\V1\OrganizationBranchController;
use App\Http\Controllers\Api\V1\OrganizationController;
use App\Http\Controllers\Api\V1\OrganizationInvitationController;
use App\Http\Controllers\Api\V1\OrganizationMembershipController;
use App\Http\Controllers\Api\V1\OrganizationSettingsController;
use App\Http\Controllers\Api\V1\PeopleController;
use App\Http\Controllers\Api\V1\PlatformAuditEventController;
use App\Http\Controllers\Api\V1\PlatformOrganizationController;
use App\Http\Controllers\Api\V1\PlatformOverviewController;
use App\Http\Controllers\Api\V1\PlatformUserController;
use App\Http\Controllers\Api\V1\PublicCourseController;
use App\Http\Controllers\Api\V1\StudentGuardianController;
use App\Http\Controllers\Api\V1\TeacherWorkloadReportController;
use App\Http\Controllers\Api\V1\TeachingAssignmentController;
use App\Http\Controllers\Api\V1\TimetableController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function (): void {
    Route::get('/health', fn () => response()->json([
        'status' => 'ok',
        'service' => 'edtechlive-api',
        'version' => 'v1',
    ]))->name('api.v1.health');
    Route::get('/health/ready', [HealthController::class, 'ready'])->name('api.v1.health.ready');
    Route::get('/public/courses', [PublicCourseController::class, 'index'])->middleware('throttle:120,1')->name('api.v1.public.courses.index');
    Route::get('/public/organizations/{organizationSlug}/courses/{courseSlug}', [PublicCourseController::class, 'show'])
        ->where(['organizationSlug' => '[A-Za-z0-9-]{1,120}', 'courseSlug' => '[A-Za-z0-9-]{1,180}'])
        ->middleware('throttle:120,1')->name('api.v1.public.courses.show');

    Route::post('/enquiries', [ContactEnquiryController::class, 'store'])
        ->middleware('throttle:6,1')->name('api.v1.enquiries.store');
    Route::get('/assets/{organization}/{record}/download', [CourseAssetController::class, 'signedDownload'])
        ->whereNumber('organization')->whereNumber('record')->middleware(['signed:relative', 'throttle:60,1'])
        ->name('api.v1.assets.download-signed');
    Route::get('/meeting-recordings/{organization}/{meeting}/{recording}/download', [MeetingController::class, 'signedDownloadRecording'])
        ->whereNumber('organization')->whereNumber('meeting')->whereNumber('recording')->middleware(['signed:relative', 'throttle:60,1'])
        ->name('api.v1.meeting-recordings.download-signed');

    Route::middleware(['auth:sanctum', 'verified'])->group(function (): void {
        Route::get('/enquiries', [ContactEnquiryController::class, 'index'])->name('api.v1.enquiries.index');
        Route::patch('/enquiries/{enquiry}', [ContactEnquiryController::class, 'update'])
            ->whereNumber('enquiry')->name('api.v1.enquiries.update');
        Route::post('/enquiries/{enquiry}/reply', [ContactEnquiryController::class, 'reply'])
            ->whereNumber('enquiry')->middleware('throttle:20,1')->name('api.v1.enquiries.reply');
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
        Route::get('/me/notification-preferences', [NotificationPreferencesController::class, 'show'])->name('api.v1.me.notification-preferences.show');
        Route::patch('/me/notification-preferences', [NotificationPreferencesController::class, 'update'])->name('api.v1.me.notification-preferences.update');
        Route::get('/platform/overview', PlatformOverviewController::class)->middleware('verified');
        Route::get('/platform/organizations', [PlatformOrganizationController::class, 'index'])->middleware('verified');
        Route::patch('/platform/organizations/{organization}', [PlatformOrganizationController::class, 'update'])->whereNumber('organization')->middleware('verified');
        Route::get('/platform/audit-events', [PlatformAuditEventController::class, 'index'])->middleware('verified');
        Route::get('/platform/users', [PlatformUserController::class, 'index'])->middleware('verified');
        Route::post('/auth/logout', [AuthenticatedSessionController::class, 'destroy'])->name('api.v1.auth.logout');

        Route::middleware('verified')->group(function (): void {
            Route::get('/organizations', [OrganizationController::class, 'index'])->name('api.v1.organizations.index');
            Route::patch('/me/organization', [OrganizationController::class, 'select'])->name('api.v1.organizations.select');
            Route::get('/organizations/{organization}', [OrganizationController::class, 'show'])
                ->whereNumber('organization')->name('api.v1.organizations.show');
            Route::patch('/organizations/{organization}/settings', [OrganizationSettingsController::class, 'update'])
                ->whereNumber('organization')->name('api.v1.organizations.settings.update');

            Route::prefix('/organizations/{organization}')->whereNumber('organization')->group(function (): void {
                Route::get('/documents', [CourseAssetController::class, 'organizationIndex']);
                Route::get('/my/student', [FamilyController::class, 'student']);
                Route::get('/my/student/attendance', [FamilyController::class, 'studentAttendance']);
                Route::get('/my/student/results', [FamilyController::class, 'studentResults']);
                Route::get('/my/student/progress', [FamilyController::class, 'studentProgress']);
                Route::get('/my/student/homework', [FamilyController::class, 'studentHomework']);
                Route::get('/my/children', [FamilyController::class, 'children']);
                Route::get('/my/children/{student}/attendance', [FamilyController::class, 'childAttendance'])->whereNumber('student');
                Route::get('/my/children/{student}/results', [FamilyController::class, 'childResults'])->whereNumber('student');
                Route::get('/my/children/{student}/progress', [FamilyController::class, 'childProgress'])->whereNumber('student');
                Route::get('/my/children/{student}/homework', [FamilyController::class, 'childHomework'])->whereNumber('student');
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
                Route::get('/batches', [AcademicBatchController::class, 'index']);
                Route::post('/batches', [AcademicBatchController::class, 'store']);
                Route::patch('/batches/{record}', [AcademicBatchController::class, 'update'])->whereNumber('record');
                Route::get('/branches', [OrganizationBranchController::class, 'index']);
                Route::post('/branches', [OrganizationBranchController::class, 'store']);
                Route::patch('/branches/{record}', [OrganizationBranchController::class, 'update'])->whereNumber('record');
                Route::get('/fee-categories', [FeeCategoryController::class, 'index']);
                Route::post('/fee-categories', [FeeCategoryController::class, 'store']);
                Route::patch('/fee-categories/{record}', [FeeCategoryController::class, 'update'])->whereNumber('record');
                Route::get('/memberships', [OrganizationMembershipController::class, 'index']);
                Route::patch('/memberships/{membership}', [OrganizationMembershipController::class, 'update'])->whereNumber('membership');
                Route::get('/membership-access-audit', [OrganizationMembershipController::class, 'audit']);
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
                Route::get('/reports/assessments', [AssessmentReportController::class, 'index']);
                Route::get('/reports/exams/sections', [AcademicExamReportController::class, 'sections']);
                Route::get('/reports/exams/comparisons', [AcademicExamReportController::class, 'comparisons']);
                Route::get('/reports/exams', [AcademicExamReportController::class, 'index']);
                Route::get('/exams', [AcademicExamController::class, 'index']);
                Route::post('/exams', [AcademicExamController::class, 'store']);
                Route::post('/exams/{exam}/papers', [AcademicExamController::class, 'storePaper'])->whereNumber('exam');
                Route::post('/exams/{exam}/publish', [AcademicExamController::class, 'publish'])->whereNumber('exam');
                Route::get('/exam-papers/{paper}/results', [AcademicExamController::class, 'results'])->whereNumber('paper');
                Route::put('/exam-papers/{paper}/results', [AcademicExamController::class, 'saveResults'])->whereNumber('paper');
                Route::get('/reports/attendance', [AttendanceReportController::class, 'index']);
                Route::get('/reports/course-progress', [CourseProgressReportController::class, 'index']);
                Route::get('/reports/enrollment', [EnrollmentReportController::class, 'index']);
                Route::get('/reports/finance', [FinanceReportController::class, 'index']);
                Route::get('/bank-statements', [BankReconciliationController::class, 'index']);
                Route::post('/bank-statements', [BankReconciliationController::class, 'store'])->middleware('throttle:12,1');
                Route::post('/bank-statement-lines/{line}/match', [BankReconciliationController::class, 'match'])->whereNumber('line');
                Route::get('/bank-statement-lines/{line}/candidates', [BankReconciliationController::class, 'candidates'])->whereNumber('line');
                Route::delete('/bank-statement-lines/{line}/match', [BankReconciliationController::class, 'unmatch'])->whereNumber('line');
                Route::get('/reports/workload', [TeacherWorkloadReportController::class, 'index']);
                Route::get('/fees', [FeeRecordController::class, 'index']);
                Route::post('/fees', [FeeRecordController::class, 'store']);
                Route::post('/fees/{fee}/payments', [FeeRecordController::class, 'payment'])->whereNumber('fee');
                Route::get('/expenses', [FinanceExpenseController::class, 'index']);
                Route::post('/expenses', [FinanceExpenseController::class, 'store'])->middleware('throttle:30,1');
                Route::get('/meetings', [MeetingController::class, 'index']);
                Route::post('/meetings', [MeetingController::class, 'store'])->middleware('throttle:30,1');
                Route::patch('/meetings/{meeting}', [MeetingController::class, 'update'])->whereNumber('meeting');
                Route::get('/meetings/{meeting}/recordings', [MeetingController::class, 'recordings'])->whereNumber('meeting');
                Route::post('/meetings/{meeting}/recordings', [MeetingController::class, 'storeRecording'])->whereNumber('meeting')->middleware('throttle:12,1');
                Route::patch('/meetings/{meeting}/recordings/{recording}', [MeetingController::class, 'updateRecording'])->whereNumber('meeting')->whereNumber('recording');
                Route::get('/meetings/{meeting}/recordings/{recording}/download', [MeetingController::class, 'downloadRecording'])->whereNumber('meeting')->whereNumber('recording');
                Route::post('/meetings/{meeting}/recordings/{recording}/download-link', [MeetingController::class, 'downloadRecordingLink'])->whereNumber('meeting')->whereNumber('recording')->middleware('throttle:30,1');
                Route::get('/notifications', [NotificationController::class, 'index']);
                Route::get('/email-deliveries', [NotificationDeliveryController::class, 'index']);
                Route::post('/notifications/{notification}/read', [NotificationController::class, 'markRead']);
                Route::get('/announcements', [AnnouncementController::class, 'index']);
                Route::post('/announcements', [AnnouncementController::class, 'store'])->middleware('throttle:30,1');
                Route::get('/dashboard/summary', [DashboardSummaryController::class, 'index']);
                Route::get('/dashboard/activity', [DashboardActivityController::class, 'index']);
                Route::get('/courses', [CourseController::class, 'index']);
                Route::post('/courses', [CourseController::class, 'store']);
                Route::patch('/courses/{record}', [CourseController::class, 'update'])->whereNumber('record');
                Route::get('/courses/{course}/lessons', [CourseController::class, 'lessons'])->whereNumber('course');
                Route::get('/courses/{course}/modules', [CourseModuleController::class, 'index'])->whereNumber('course');
                Route::post('/courses/{course}/modules', [CourseModuleController::class, 'store'])->whereNumber('course');
                Route::patch('/courses/{course}/modules/{module}', [CourseModuleController::class, 'update'])->whereNumber('course')->whereNumber('module');
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
                Route::get('/courses/{course}/question-banks', [CourseQuizController::class, 'questionBanks'])->whereNumber('course');
                Route::post('/courses/{course}/question-banks', [CourseQuizController::class, 'storeQuestionBank'])->whereNumber('course');
                Route::get('/question-banks/{bank}/questions', [CourseQuizController::class, 'questionBankQuestions'])->whereNumber('bank');
                Route::post('/question-banks/{bank}/questions', [CourseQuizController::class, 'storeQuestionBankQuestion'])->whereNumber('bank');
                Route::patch('/question-banks/{bank}', [CourseQuizController::class, 'updateQuestionBank'])->whereNumber('bank');
                Route::delete('/question-banks/{bank}', [CourseQuizController::class, 'deleteQuestionBank'])->whereNumber('bank');
                Route::patch('/question-banks/{bank}/questions/{question}', [CourseQuizController::class, 'updateQuestionBankQuestion'])->whereNumber('bank')->whereNumber('question');
                Route::delete('/question-banks/{bank}/questions/{question}', [CourseQuizController::class, 'deleteQuestionBankQuestion'])->whereNumber('bank')->whereNumber('question');
                Route::patch('/quizzes/{quiz}', [CourseQuizController::class, 'update'])->whereNumber('quiz');
                Route::post('/quizzes/{quiz}/questions', [CourseQuizController::class, 'addQuestion'])->whereNumber('quiz');
                Route::post('/quizzes/{quiz}/questions/from-bank', [CourseQuizController::class, 'addQuestionFromBank'])->whereNumber('quiz');
                Route::post('/quizzes/{quiz}/attempts', [CourseQuizController::class, 'startAttempt'])->whereNumber('quiz');
                Route::patch('/quizzes/{quiz}/attempts/{attempt}', [CourseQuizController::class, 'submitAttempt'])->whereNumber('quiz')->whereNumber('attempt');
                Route::patch('/quizzes/{quiz}/attempts/{attempt}/questions/{question}/grade', [CourseQuizController::class, 'gradeShortAnswer'])->whereNumber('quiz')->whereNumber('attempt')->whereNumber('question');
                Route::get('/quizzes/{quiz}/attempts', [CourseQuizController::class, 'attempts'])->whereNumber('quiz');
                Route::get('/courses/{course}/assets', [CourseAssetController::class, 'index'])->whereNumber('course');
                Route::post('/courses/{course}/assets', [CourseAssetController::class, 'store'])->whereNumber('course');
                Route::patch('/assets/{record}', [CourseAssetController::class, 'update'])->whereNumber('record');
                Route::get('/assets/{record}/download', [CourseAssetController::class, 'download'])->whereNumber('record');
                Route::post('/assets/{record}/download-link', [CourseAssetController::class, 'downloadLink'])->whereNumber('record');
                Route::get('/timetable', [TimetableController::class, 'index']);
                Route::post('/timetable', [TimetableController::class, 'store']);
                Route::patch('/timetable/{record}', [TimetableController::class, 'update'])->whereNumber('record');
            });
        });
    });
});
