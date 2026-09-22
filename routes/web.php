<?php
use App\Http\Controllers\Api\V1\ActivitiesController;
use App\Http\Controllers\Api\V1\DashboardController;
use App\Http\Controllers\Api\V1\DocumentsController;
use App\Http\Controllers\Api\V1\JournalsController;
use App\Http\Controllers\Api\V1\OtgController;
use App\Http\Controllers\Api\V1\OtgPrintController;
use App\Http\Controllers\Api\V1\RemoteFileController;
use App\Http\Controllers\Api\V1\TheoreticalAssessmentsController;
use App\Http\Controllers\Api\V1\TheoreticalExternalAssessmentsController;
use App\Http\Controllers\Api\V1\PracticalInternalAssessmentsController;
use App\Http\Controllers\Api\V1\PracticalExternalAssessmentsController;
use App\Http\Controllers\Api\V1\TheoreticalBatchController;
use App\Http\Controllers\Api\V1\TheoreticalExternalBatchController;
use App\Http\Controllers\Api\V1\PracticalInternalBatchController;
use App\Http\Controllers\Api\V1\PracticalExternalBatchController;
use App\Http\Controllers\Api\V1\ReportsController;
use App\Http\Controllers\Api\V1\ExamSessionController;
use App\Http\Controllers\Api\V1\ExamPackageController;
use App\Http\Controllers\Api\V1\PracticalAssessmentSetupController;
use App\Http\Controllers\Api\V1\RubricSetupController;
use App\Http\Controllers\Api\V1\QuestionBankController;
use App\Http\Controllers\Api\V1\QuestionUploadController;
use App\Http\Controllers\Api\V1\QuestionActivationController;
use App\Http\Controllers\Api\V1\SubjectBatchUpdateController;
use App\Http\Controllers\Api\V1\StudentsController;
use App\Http\Controllers\Api\V1\StudentBatchUploadController;
use App\Http\Controllers\Api\V1\ItemAnalysisHistoryController;
use App\Http\Controllers\Api\V1\QuestionListController;
use App\Http\Controllers\Api\V1\ItemAnalysisController;
use App\Http\Controllers\Api\V1\DiscriminabilityIndexController;
use App\Http\Controllers\Api\V1\DifficultyLevelController;
use App\Http\Controllers\Api\V1\CorrectAnswerFrequencyController;
use App\Http\Controllers\Api\V1\ExamResultsSummaryController;
use App\Http\Controllers\Api\V1\ExamPackagesSummaryController;
use App\Http\Controllers\Api\V1\StudentListReportController;
use Illuminate\Support\Facades\Route;

Route::redirect(
    '/',
    '/login',
)->name('home');

/*
|--------------------------------------------------------------------------
| Student Routes
|--------------------------------------------------------------------------
|
| Only authenticated student accounts may access these routes.
|
*/

Route::middleware([
    'auth',
    'account.type:student',
])->group(function (): void {
    Route::inertia(
        '/student-dashboard',
        'StudentDashboard',
    )->name('student.dashboard');

    Route::get(
        '/api/v1/student-dashboard/activities',
        [
            ActivitiesController::class,
            'studentDashboard',
        ],
    )->name(
        'api.v1.student-dashboard.activities',
    );
});

/*
|--------------------------------------------------------------------------
| Administrator and Staff Routes
|--------------------------------------------------------------------------
|
| Accounts authenticated through the login table may access the existing
| dashboard, monitoring, assessment and database management routes.
|
*/

Route::middleware([
    'auth',
    'account.type:administrator',
])->group(function (): void {
    // Inertia pages

    // Dashboard
    Route::inertia('/dashboard', 'Dashboard',)->name('dashboard');
    Route::inertia('/dashboard/activity-updates', 'dashboard/activity-updates/Index',)->name('dashboard.activity-updates');
    Route::inertia('/dashboard/uploaded-documents', 'dashboard/uploaded-documents/Index',)->name('dashboard.uploaded-documents');
    Route::inertia('/dashboard/otg-updates', 'dashboard/otg-updates/Index',)->name('dashboard.otg-updates');
    Route::inertia('/dashboard/daily-journals', 'dashboard/daily-journals/Index',)->name('dashboard.daily-journals');
    Route::inertia('/dashboard/theoretical-internal', 'dashboard/theoretical-internal/datatable/Index',)->name('dashboard.theoretical-internal');
    Route::inertia('/dashboard/theoretical-external', 'dashboard/theoretical-external/datatable/Index',)->name('dashboard.theoretical-external');
    Route::inertia('/dashboard/theoretical-internal/batch', 'dashboard/theoretical-internal/batch/Index',)->name('dashboard.theoretical-internal.batch',);
    Route::inertia('/dashboard/theoretical-external/batch', 'dashboard/theoretical-external/batch/Index',)->name('dashboard.theoretical-external.batch',);
    Route::inertia('/dashboard/practical-internal', 'dashboard/practical-internal/datatable/Index',)->name('dashboard.practical-internal');
    Route::inertia('/dashboard/practical-external', 'dashboard/practical-external/datatable/Index',)->name('dashboard.practical-external');
    Route::inertia('/dashboard/practical-internal/batch', 'dashboard/practical-internal/batch/Index',)->name('dashboard.practical-internal.batch');
    Route::inertia('/dashboard/practical-external/batch', 'dashboard/practical-external/batch/Index',)->name('dashboard.practical-external.batch');

    // Monitoring
    Route::inertia('/monitoring/activity-updates', 'monitoring/activity-updates/Index',)->name('monitoring.activity-updates',);
    Route::inertia('/monitoring/uploaded-documents', 'monitoring/uploaded-documents/Index',)->name('monitoring.uploaded-documents',);
    Route::inertia('/monitoring/otg-updates', 'monitoring/otg-updates/Index',)->name('monitoring.otg-updates',);
    Route::inertia('/monitoring/daily-journals', 'dashboard/daily-journals/Index',)->name('monitoring.daily-journals',);
    Route::inertia('/monitoring/reports', 'monitoring/reports/Index',)->name('monitoring.reports',);

    // Assessment Setup
    Route::inertia('/assessment-setup/question-bank', 'assessment-setup/question-bank/datatable/Index',)->name('assessment-setup.question-bank');
    Route::inertia('/assessment-setup/question-bank-upload', 'assessment-setup/question-bank-upload/datatable/Index',)->name('assessment-setup.question-bank-upload');
    Route::inertia('/assessment-setup/question-bank-activation', 'assessment-setup/question-bank-activation/datatable/Index',)->name('assessment-setup.question-bank-activation');
    Route::inertia('/assessment-setup/exam-session', 'assessment-setup/exam-session/datatable/Index',)->name('assessment-setup.exam-session',);
    Route::inertia('/assessment-setup/exam-package', 'assessment-setup/exam-package/datatable/Index',)->name('assessment-setup.exam-package');
    Route::inertia('/assessment-setup/practical-assessment-setup', 'assessment-setup/practical-assessment-setup/datatable/Index',)->name('assessment-setup.practical-assessment-setup');
    Route::inertia('/assessment-setup/rubric-setup', 'assessment-setup/rubric-setup/datatable/Index',)->name('assessment-setup.rubric-setup');
    Route::inertia('/assessment-setup/subject-batch', 'assessment-setup/subject-batch/datatable/Index',)->name('assessment-setup.subject-batch');

    // Assessment Report 
    Route::inertia('/assessment-reports/item-analysis-history', 'assessment-reports/item-analysis-history/datatable/Index')->name('assessment-reports.item-analysis-history');
    Route::inertia('/assessment-reports/question-list', 'assessment-reports/question-list/datatable/Index')->name('assessment-reports.question-list');
    Route::inertia('/assessment-reports/item-analysis', 'assessment-reports/item-analysis/datatable/Index')->name('assessment-reports.item-analysis');
    Route::inertia('/assessment-reports/discriminability-index', 'assessment-reports/discriminability-index/datatable/Index')->name('assessment-reports.discriminability-index');
    Route::inertia('/assessment-reports/difficulty-level', 'assessment-reports/difficulty-level/datatable/Index')->name('assessment-reports.difficulty-level');
    Route::inertia('/assessment-reports/correct-answer-frequency', 'assessment-reports/correct-answer-frequency/datatable/Index')->name('assessment-reports.correct-answer-frequency');
    Route::inertia('/assessment-reports/exam-results-summary', 'assessment-reports/exam-results-summary/datatable/Index')->name('assessment-reports.exam-results-summary');
    Route::inertia('/assessment-reports/exam-packages-summary', 'assessment-reports/exam-packages-summary/datatable/Index')->name('assessment-reports.exam-packages-summary');

    // Databases — Students
    Route::inertia('/databases/students/datatable', 'databases/students/datatable/Index',)->name('databases.students');
    Route::inertia('/databases/students/profile/{studentId}', 'databases/students/profile/Index',['studentId' => fn () =>(string) request()->route('studentId',)])->whereUuid('studentId')->name('databases.students.edit');
    Route::inertia('/databases/students/profile/new','databases/students/profile/Index',['studentId' => null,])->name('databases.students.profile.create');

    Route::post('/api/v1/databases/students', [StudentsController::class,'store'])->name('api.v1.databases.students.store');

    Route::get('/api/v1/databases/datatable/students', [StudentsController::class, 'index',],)->name('api.v1.databases.datatable.students');
    Route::get('/api/v1/databases/students/options', [StudentsController::class, 'options',],)->name('api.v1.databases.students.options');
    Route::get('/api/v1/databases/students/{studentId}', [StudentsController::class, 'show',],)->whereUuid('studentId')->name('api.v1.databases.students.show');
    Route::put('/api/v1/databases/students/{studentId}', [StudentsController::class, 'update',],)->whereUuid('studentId')->name('api.v1.databases.students.update');
    Route::post('/api/v1/databases/students/{studentId}/send-credentials', [StudentsController::class, 'sendCredentials',],)->middleware('throttle:5,1')->whereUuid('studentId')->name('api.v1.databases.students.send-credentials');
    Route::post('/api/v1/databases/students/{studentId}/photo', [StudentsController::class, 'uploadPhoto',],)->whereUuid('studentId')->name('api.v1.databases.students.photo');

    // Databases — Batch Upload
    Route::inertia('/databases/batch-upload/datatable', 'databases/batch-upload/datatable/Index',)->name('databases.batch-upload');

    Route::prefix('/api/v1/databases/students/batch-upload')->controller(StudentBatchUploadController::class)->group(function (): void {
        Route::get('/template', 'template')->name('api.v1.databases.students.batch-upload.template');
        Route::post('/import', 'import')->middleware('throttle:10,1')->name('api.v1.databases.students.batch-upload.import');
    });

    // Databases - Student List Report
    Route::inertia('/databases/student-list-report/datatable', 'databases/student-list-report/datatable/Index',)->name('databases.student-list-report');

    Route::prefix('/api/v1/databases/student-list-report')->controller(StudentListReportController::class)->group(function (): void {
        Route::get('/options', 'options')->name('api.v1.databases.student-list-report.options');
        Route::get('/export', 'export')->middleware('throttle:10,1')->name('api.v1.databases.student-list-report.export');
        Route::get('/', 'index')->name('api.v1.databases.student-list-report.index');
    });

    // Dashboard — authenticated API and supporting routes
    Route::get('/api/v1/dashboard/datatable/students', [DashboardController::class, 'students',],)->name('api.v1.dashboard.datatable.students',);
    Route::get('/api/v1/dashboard/datatable/activity-updates', [ActivitiesController::class, 'index',],)->name('api.v1.dashboard.datatable.activity-updates',);
    Route::get('/api/v1/dashboard/datatable/uploaded-documents', [DocumentsController::class, 'index',],)->name('api.v1.dashboard.datatable.uploaded-documents',);
    Route::get('/api/v1/dashboard/datatable/otg-updates', [OtgController::class, 'index',],)->name('api.v1.dashboard.datatable.otg-updates',);
    Route::get('/api/v1/dashboard/datatable/daily-journals', [JournalsController::class, 'index',],)->name('api.v1.dashboard.datatable.daily-journals',);
    Route::get('/dashboard/files/uploads/{filename}', [RemoteFileController::class, 'upload',],)->where('filename', '[^/]+',)->name('dashboard.files.upload',);
    Route::get('/dashboard/files/person-task/{filename}', [RemoteFileController::class, 'personTask',],)->where('filename', '[^/]+',)->name('dashboard.files.person-task',);
    Route::get('/dashboard/files/signatures/{filename}', [RemoteFileController::class, 'signature',],)->where('filename', '[^/]+',)->name('dashboard.files.signature',);
    Route::get('/api/v1/dashboard/daily-journals/students', [JournalsController::class, 'students',],)->name('api.v1.dashboard.daily-journals.students',);
    Route::get('/dashboard/daily-journals/print', [JournalsController::class, 'download',],)->name('dashboard.daily-journals.print',);
    Route::patch('/api/v1/dashboard/activity-updates/{activityId}/verify', [ActivitiesController::class, 'verify',],)->name('api.v1.dashboard.activity-updates.verify',);
    Route::patch('/api/v1/dashboard/activity-updates/{activityId}/revise', [ActivitiesController::class, 'revise',],)->name('api.v1.dashboard.activity-updates.revise',);
    Route::patch('/api/v1/dashboard/uploaded-documents/{fileUploadId}/verify', [DocumentsController::class, 'verify',],)->name('api.v1.dashboard.uploaded-documents.verify',);
    Route::patch('/api/v1/dashboard/uploaded-documents/{fileUploadId}/revise', [DocumentsController::class, 'revise',],)->name('api.v1.dashboard.uploaded-documents.revise',);
    Route::get('/students/{personId}/otg/print', [OtgPrintController::class, 'download',],)->whereUuid('personId')->name('students.otg.print');
    Route::get('/api/v1/dashboard/datatable/theoretical-assessments', [TheoreticalAssessmentsController::class, 'index',],)->name('api.v1.dashboard.datatable.theoretical-assessments',);
    Route::get('/api/v1/dashboard/theoretical-assessments/options', [TheoreticalAssessmentsController::class, 'options',],)->name('api.v1.dashboard.theoretical-assessments.options',);
    Route::get('/api/v1/dashboard/theoretical-assessments/students', [TheoreticalAssessmentsController::class, 'students',],)->name('api.v1.dashboard.theoretical-assessments.students',);
    Route::get('/api/v1/dashboard/theoretical-assessments/{assessmentId}', [TheoreticalAssessmentsController::class, 'show',],)->name('api.v1.dashboard.theoretical-assessments.show',);
    Route::get('/dashboard/theoretical-assessments/{assessmentId}/certificate', [TheoreticalAssessmentsController::class, 'certificate',],)->name('dashboard.theoretical-assessments.certificate',);
    Route::get('/api/v1/dashboard/datatable/theoretical-external', [TheoreticalExternalAssessmentsController::class, 'index',],)->name('api.v1.dashboard.datatable.theoretical-external',);
    Route::get('/api/v1/dashboard/theoretical-external/options', [TheoreticalExternalAssessmentsController::class, 'options',],)->name('api.v1.dashboard.theoretical-external.options',);
    Route::get('/api/v1/dashboard/theoretical-external/{assessmentId}', [TheoreticalExternalAssessmentsController::class, 'show',],)->name('api.v1.dashboard.theoretical-external.show',);
    Route::get('/dashboard/theoretical-external/{assessmentId}/certificate', [TheoreticalExternalAssessmentsController::class, 'certificate',],)->name('dashboard.theoretical-external.certificate',);
    Route::get('/api/v1/dashboard/datatable/practical-internal', [PracticalInternalAssessmentsController::class, 'index',],)->name('api.v1.dashboard.datatable.practical-internal',);
    Route::get('/api/v1/dashboard/practical-internal/{assessmentId}', [PracticalInternalAssessmentsController::class, 'show',],)->where('assessmentId', '[A-Za-z0-9\-]+',)->name('api.v1.dashboard.practical-internal.show',);
    Route::patch('/api/v1/dashboard/practical-internal/{assessmentId}/grade', [PracticalInternalAssessmentsController::class, 'grade',],)->where('assessmentId', '[A-Za-z0-9\-]+',)->name('api.v1.dashboard.practical-internal.grade',);
    Route::get('/api/v1/dashboard/datatable/practical-external', [PracticalExternalAssessmentsController::class, 'index',],)->name('api.v1.dashboard.datatable.practical-external',);
    Route::get('/api/v1/dashboard/practical-external/{assessmentId}', [PracticalExternalAssessmentsController::class, 'show',],)->where('assessmentId', '[A-Za-z0-9\-]+',)->name('api.v1.dashboard.practical-external.show',);
    Route::patch('/api/v1/dashboard/practical-external/{assessmentId}/grade', [PracticalExternalAssessmentsController::class, 'grade',],)->where('assessmentId', '[A-Za-z0-9\-]+',)->name('api.v1.dashboard.practical-external.grade',);
    Route::get('/api/v1/dashboard/datatable/theoretical-batches', [TheoreticalBatchController::class, 'index',],)->name('api.v1.dashboard.datatable.theoretical-batches',);
    Route::get('/api/v1/dashboard/theoretical/batch/options', [TheoreticalBatchController::class, 'options',],)->name('api.v1.dashboard.theoretical.batch.options',);
    Route::get('/api/v1/dashboard/theoretical/batch/students', [TheoreticalBatchController::class, 'students',],)->name('api.v1.dashboard.theoretical.batch.students',);
    Route::post('/api/v1/dashboard/theoretical/batch', [TheoreticalBatchController::class, 'store',],)->name('api.v1.dashboard.theoretical.batch.store',);
    Route::get('/api/v1/dashboard/theoretical/batch/{batchId}', [TheoreticalBatchController::class, 'show',],)->where('batchId', '[A-Za-z0-9\-]+',)->name('api.v1.dashboard.theoretical.batch.show',);
    Route::patch('/api/v1/dashboard/theoretical/batch/{batchId}', [TheoreticalBatchController::class, 'update',],)->where('batchId', '[A-Za-z0-9\-]+',)->name('api.v1.dashboard.theoretical.batch.update',);
    Route::get('/api/v1/dashboard/datatable/theoretical-external-batches', [TheoreticalExternalBatchController::class, 'index',],)->name('api.v1.dashboard.datatable.theoretical-external-batches',);
    Route::get('/api/v1/dashboard/theoretical-external/batch/options', [TheoreticalExternalBatchController::class, 'options',],)->name('api.v1.dashboard.theoretical-external.batch.options',);
    Route::post('/api/v1/dashboard/theoretical-external/batch', [TheoreticalExternalBatchController::class, 'store',],)->name('api.v1.dashboard.theoretical-external.batch.store',);
    Route::get('/api/v1/dashboard/theoretical-external/batch/{batchId}', [TheoreticalExternalBatchController::class, 'show',],)->where('batchId', '[A-Za-z0-9\-]+',)->name('api.v1.dashboard.theoretical-external.batch.show',);
    Route::prefix('/api/v1/dashboard/practical-internal/batches',)->name('api.v1.dashboard.practical-internal.batches.',)->controller(PracticalInternalBatchController::class,)->group(function (): void {
        Route::get('/', 'index',)->name('index');
        Route::get('/options', 'options',)->name('options');
        Route::get('/students', 'students',)->name('students');
        Route::post('/', 'store',)->name('store');
        Route::get('/{batchId}', 'show',)->whereUuid('batchId')->name('show');
    });
    Route::get('/api/v1/dashboard/datatable/practical-internal', [PracticalInternalAssessmentsController::class, 'index',],)->name('api.v1.dashboard.datatable.practical-internal',);
    Route::get('/api/v1/dashboard/practical-internal/{assessmentId}', [PracticalInternalAssessmentsController::class, 'show',],)->whereUuid('assessmentId')->name('api.v1.dashboard.practical-internal.show',);
    Route::patch('/api/v1/dashboard/practical-internal/{assessmentId}/grade', [PracticalInternalAssessmentsController::class, 'grade',],)->whereUuid('assessmentId')->name('api.v1.dashboard.practical-internal.grade',);
    Route::prefix('/api/v1/dashboard/practical-external/batches',)->name('api.v1.dashboard.practical-external.batches.',)->controller(PracticalExternalBatchController::class,)->group(function (): void {
        Route::get('/', 'index')->name('index');
        Route::get('/options', 'options')->name('options');
        Route::post('/import', 'import')->name('import');
        Route::post('/', 'store')->name('store');
        Route::get('/{batchId}', 'show')->whereUuid('batchId')->name('show');
    });
    Route::get('/api/v1/dashboard/practical-external/{assessmentId}', [PracticalExternalBatchController::class, 'show'],)->whereUuid('assessmentId')->name('api.v1.dashboard.practical-external.show');

    // Monitoring — authenticated API and supporting routes
    Route::get('/api/v1/monitoring/datatable/activity-updates', [ActivitiesController::class, 'index',],)->name('api.v1.monitoring.datatable.activity-updates',);
    Route::get('/api/v1/monitoring/activity-updates/options', [ActivitiesController::class, 'activityOptions',],)->name('api.v1.monitoring.activity-updates.options',);
    Route::get('/api/v1/monitoring/datatable/uploaded-documents', [DocumentsController::class, 'index',],)->name('api.v1.monitoring.datatable.uploaded-documents',);
    Route::get('/api/v1/monitoring/datatable/otg-updates', [OtgController::class, 'index',],)->name('api.v1.monitoring.datatable.otg-updates',);
    Route::get('/api/v1/monitoring/reports/options', [ReportsController::class, 'options',],)->name('api.v1.monitoring.reports.options',);
    Route::get('/api/v1/monitoring/reports', [ReportsController::class, 'index',],)->name('api.v1.monitoring.reports.index',);

    // Assessment Report — Item Analysis History API. Static routes precede {historyId}.
    Route::get('/api/v1/assessment-reports/item-analysis-history/options', [ItemAnalysisHistoryController::class, 'options'])->name('api.v1.assessment-reports.item-analysis-history.options');
    Route::get('/api/v1/assessment-reports/item-analysis-history/packages/{courseId}/subjects', [ItemAnalysisHistoryController::class, 'subjects'])->whereUuid('courseId')->name('api.v1.assessment-reports.item-analysis-history.subjects');
    Route::get('/api/v1/assessment-reports/item-analysis-history', [ItemAnalysisHistoryController::class, 'index'])->name('api.v1.assessment-reports.item-analysis-history.index');
    Route::get('/api/v1/assessment-reports/item-analysis-history/{historyId}/export', [ItemAnalysisHistoryController::class, 'export'])->whereUuid('historyId')->middleware('throttle:10,1')->name('api.v1.assessment-reports.item-analysis-history.export');
    Route::get('/api/v1/assessment-reports/item-analysis-history/{historyId}', [ItemAnalysisHistoryController::class, 'show'])->whereUuid('historyId')->middleware('throttle:30,1')->name('api.v1.assessment-reports.item-analysis-history.show');
    Route::delete('/api/v1/assessment-reports/item-analysis-history/{historyId}', [ItemAnalysisHistoryController::class, 'destroy'])->whereUuid('historyId')->name('api.v1.assessment-reports.item-analysis-history.destroy');

        // Assessment Report — Questions List API.
    Route::get('/api/v1/assessment-reports/question-list/options', [QuestionListController::class, 'options'])->name('api.v1.assessment-reports.question-list.options');
    Route::get('/api/v1/assessment-reports/question-list/packages/{courseId}/subjects', [QuestionListController::class, 'subjects'])->whereUuid('courseId')->name('api.v1.assessment-reports.question-list.subjects');
    Route::get('/api/v1/assessment-reports/question-list/export', [QuestionListController::class, 'export'])->middleware('throttle:10,1')->name('api.v1.assessment-reports.question-list.export');
    Route::get('/api/v1/assessment-reports/question-list', [QuestionListController::class, 'index'])->name('api.v1.assessment-reports.question-list.index');
        
    // Assessment Report — Questions List API.
    Route::get('/api/v1/assessment-reports/item-analysis/options', [ItemAnalysisController::class, 'options'])->name('api.v1.assessment-reports.item-analysis.options');
    Route::get('/api/v1/assessment-reports/item-analysis/packages/{courseId}/subjects', [ItemAnalysisController::class, 'subjects'])->whereUuid('courseId')->name('api.v1.assessment-reports.item-analysis.subjects');
    Route::post('/api/v1/assessment-reports/item-analysis/preview', [ItemAnalysisController::class, 'preview'])->middleware('throttle:30,1')->name('api.v1.assessment-reports.item-analysis.preview');
    Route::post('/api/v1/assessment-reports/item-analysis/generate', [ItemAnalysisController::class, 'generate'])->middleware('throttle:10,1')->name('api.v1.assessment-reports.item-analysis.generate');
    // Assessment Report: Discriminability Index.
    Route::get('/api/v1/assessment-reports/discriminability-index/options', [DiscriminabilityIndexController::class, 'options'])->name('api.v1.discriminability-index.options');
    Route::get('/api/v1/assessment-reports/discriminability-index/packages/{courseId}/subjects', [DiscriminabilityIndexController::class, 'subjects'])->whereUuid('courseId')->name('api.v1.discriminability-index.subjects');
    Route::post('/api/v1/assessment-reports/discriminability-index/generate', [DiscriminabilityIndexController::class, 'generate'])->middleware('throttle:30,1')->name('api.v1.discriminability-index.generate');

     // Assessment Report: Difficulty Level.
    Route::get('/api/v1/assessment-reports/difficulty-level/options', [DifficultyLevelController::class, 'options'])->name('api.v1.difficulty-level.options');
    Route::get('/api/v1/assessment-reports/difficulty-level/packages/{courseId}/subjects', [DifficultyLevelController::class, 'subjects'])->whereUuid('courseId')->name('api.v1.difficulty-level.subjects');
    Route::post('/api/v1/assessment-reports/difficulty-level/generate', [DifficultyLevelController::class, 'generate'])->middleware('throttle:30,1')->name('api.v1.difficulty-level.generate');

// Assessment Report: Frequency of Correct Answer.
    Route::get('/api/v1/assessment-reports/correct-answer-frequency/options', [CorrectAnswerFrequencyController::class, 'options'])->name('api.v1.correct-answer-frequency.options');
    Route::get('/api/v1/assessment-reports/correct-answer-frequency/packages/{courseId}/subjects', [CorrectAnswerFrequencyController::class, 'subjects'])->whereUuid('courseId')->name('api.v1.correct-answer-frequency.subjects');
    Route::post('/api/v1/assessment-reports/correct-answer-frequency/generate', [CorrectAnswerFrequencyController::class, 'generate'])->middleware('throttle:30,1')->name('api.v1.correct-answer-frequency.generate');
  // Assessment Report: Exam Results Summary.
    Route::get('/api/v1/assessment-reports/exam-results-summary/options', [ExamResultsSummaryController::class, 'options'])->name('api.v1.exam-results-summary.options');
    Route::post('/api/v1/assessment-reports/exam-results-summary/generate', [ExamResultsSummaryController::class, 'generate'])->middleware('throttle:30,1')->name('api.v1.exam-results-summary.generate');
    // Assessment Report: Exam Packages Summary.
    Route::get('/api/v1/assessment-reports/exam-packages-summary/options', [ExamPackagesSummaryController::class, 'options'])->name('api.v1.exam-packages-summary.options');
    Route::get('/api/v1/assessment-reports/exam-packages-summary/subjects', [ExamPackagesSummaryController::class, 'subjects'])->name('api.v1.exam-packages-summary.subjects');
    Route::post('/api/v1/assessment-reports/exam-packages-summary/generate', [ExamPackagesSummaryController::class, 'generate'])->middleware('throttle:30,1')->name('api.v1.exam-packages-summary.generate');
});


// Dashboard — existing routes outside the auth group
Route::get('/api/v1/dashboard/reports/yearly', [DashboardController::class, 'yearlyReport'],)->name('api.v1.dashboard.reports.yearly');

// Assessment Setup — existing routes outside the auth group
Route::get('/api/v1/assessment-setup/datatable/exam-sessions', [ExamSessionController::class, 'index'],)->name('api.v1.assessment-setup.datatable.exam-sessions');
Route::post('/api/v1/assessment-setup/exam-sessions', [ExamSessionController::class, 'store'],)->name('api.v1.assessment-setup.exam-sessions.store');
Route::get('/api/v1/assessment-setup/exam-sessions/{examSessionId}', [ExamSessionController::class, 'show'],)->name('api.v1.assessment-setup.exam-sessions.show');
Route::put('/api/v1/assessment-setup/exam-sessions/{examSessionId}', [ExamSessionController::class, 'update'],)->name('api.v1.assessment-setup.exam-sessions.update');
Route::delete('/api/v1/assessment-setup/exam-sessions/{examSessionId}', [ExamSessionController::class, 'destroy'],)->name('api.v1.assessment-setup.exam-sessions.destroy');
Route::get('/api/v1/assessment-setup/datatable/exam-packages', [ExamPackageController::class, 'index'],)->name('api.v1.assessment-setup.datatable.exam-packages');
Route::post('/api/v1/assessment-setup/exam-packages', [ExamPackageController::class, 'store']);
Route::get('/api/v1/assessment-setup/exam-packages/{packageId}', [ExamPackageController::class, 'show']);
Route::put('/api/v1/assessment-setup/exam-packages/{packageId}', [ExamPackageController::class, 'update']);
Route::delete('/api/v1/assessment-setup/exam-packages/{packageId}', [ExamPackageController::class, 'destroy']);
Route::get('/api/v1/assessment-setup/exam-packages/{packageId}/subjects', [ExamPackageController::class, 'subjects']);
Route::post('/api/v1/assessment-setup/exam-packages/{packageId}/subjects', [ExamPackageController::class, 'storeSubject']);
Route::put('/api/v1/assessment-setup/exam-packages/{packageId}/subjects/{subjectId}', [ExamPackageController::class, 'updateSubject']);
Route::delete('/api/v1/assessment-setup/exam-packages/{packageId}/subjects/{subjectId}', [ExamPackageController::class, 'destroySubject']);
Route::get('/api/v1/assessment-setup/datatable/practical-assessments', [PracticalAssessmentSetupController::class, 'index']);
Route::get('/api/v1/assessment-setup/practical-assessments/options', [PracticalAssessmentSetupController::class, 'options']);
Route::post('/api/v1/assessment-setup/practical-assessments', [PracticalAssessmentSetupController::class, 'store']);
Route::put('/api/v1/assessment-setup/practical-assessments/{assessmentId}', [PracticalAssessmentSetupController::class, 'update']);
Route::delete('/api/v1/assessment-setup/practical-assessments/{assessmentId}', [PracticalAssessmentSetupController::class, 'destroy']);
Route::get('/api/v1/assessment-setup/practical-assessments/{assessmentId}/items', [PracticalAssessmentSetupController::class, 'items']);
Route::post('/api/v1/assessment-setup/practical-assessments/{assessmentId}/items', [PracticalAssessmentSetupController::class, 'storeItem']);
Route::put('/api/v1/assessment-setup/practical-assessments/{assessmentId}/items/{itemId}', [PracticalAssessmentSetupController::class, 'updateItem']);
Route::delete('/api/v1/assessment-setup/practical-assessments/{assessmentId}/items/{itemId}', [PracticalAssessmentSetupController::class, 'destroyItem']);
Route::post('/api/v1/assessment-setup/practical-assessments/item-files', [PracticalAssessmentSetupController::class, 'uploadItemFile']);
Route::get('/api/v1/assessment-setup/practical-assessments/{assessmentId}/attachments', [PracticalAssessmentSetupController::class, 'attachments']);
Route::post('/api/v1/assessment-setup/practical-assessments/{assessmentId}/attachments', [PracticalAssessmentSetupController::class, 'storeAttachment']);
Route::delete('/api/v1/assessment-setup/practical-assessments/{assessmentId}/attachments/{attachmentId}', [PracticalAssessmentSetupController::class, 'destroyAttachment']);
Route::get('/api/v1/assessment-setup/datatable/rubrics', [RubricSetupController::class, 'index']);
Route::post('/api/v1/assessment-setup/rubrics', [RubricSetupController::class, 'store']);
Route::put('/api/v1/assessment-setup/rubrics/{rubricId}', [RubricSetupController::class, 'update']);
Route::delete('/api/v1/assessment-setup/rubrics/{rubricId}', [RubricSetupController::class, 'destroy']);
Route::get('/api/v1/assessment-setup/rubrics/{rubricId}/criteria', [RubricSetupController::class, 'criteria']);
Route::post('/api/v1/assessment-setup/rubrics/{rubricId}/criteria', [RubricSetupController::class, 'storeCriterion']);
Route::put('/api/v1/assessment-setup/rubrics/{rubricId}/criteria/{criterionId}', [RubricSetupController::class, 'updateCriterion']);
Route::delete('/api/v1/assessment-setup/rubrics/{rubricId}/criteria/{criterionId}', [RubricSetupController::class, 'destroyCriterion']);
Route::post('/api/v1/assessment-setup/rubrics/{rubricId}/criteria/{criterionId}/levels', [RubricSetupController::class, 'storeLevel']);
Route::put('/api/v1/assessment-setup/rubrics/{rubricId}/criteria/{criterionId}/levels/{levelId}', [RubricSetupController::class, 'updateLevel']);
Route::delete('/api/v1/assessment-setup/rubrics/{rubricId}/criteria/{criterionId}/levels/{levelId}', [RubricSetupController::class, 'destroyLevel']);
Route::get('/api/v1/assessment-setup/datatable/questions', [QuestionBankController::class, 'index']);
Route::get('/api/v1/assessment-setup/questions/options', [QuestionBankController::class, 'options']);
Route::get('/api/v1/assessment-setup/questions/packages/{courseId}/subjects', [QuestionBankController::class, 'subjects']);
Route::post('/api/v1/assessment-setup/questions/images', [QuestionBankController::class, 'uploadImage']);
Route::post('/api/v1/assessment-setup/questions', [QuestionBankController::class, 'store']);
Route::put('/api/v1/assessment-setup/questions/{questionId}', [QuestionBankController::class, 'update']);
Route::delete('/api/v1/assessment-setup/questions/{questionId}', [QuestionBankController::class, 'destroy']);
Route::get('/api/v1/assessment-setup/questions/{questionId}/answers', [QuestionBankController::class, 'answers']);
Route::post('/api/v1/assessment-setup/questions/{questionId}/answers', [QuestionBankController::class, 'storeAnswer']);
Route::put('/api/v1/assessment-setup/questions/{questionId}/answers/{answerId}', [QuestionBankController::class, 'updateAnswer']);
Route::delete('/api/v1/assessment-setup/questions/{questionId}/answers/{answerId}', [QuestionBankController::class, 'destroyAnswer']);
Route::prefix('/api/v1/assessment-setup/question-upload')->group(function (): void {
    Route::get('/options', [QuestionUploadController::class, 'options']);
    Route::get('/packages/{courseId}/subjects', [QuestionUploadController::class, 'subjects']);
    Route::get('/template', [QuestionUploadController::class, 'template']);
    Route::post('/import', [QuestionUploadController::class, 'import'])->middleware('throttle:10,1');
});
Route::prefix('/api/v1/assessment-setup/question-activation')->group(function (): void {
    Route::get('/options', [QuestionActivationController::class, 'options']);
    Route::get('/packages/{courseId}/subjects', [QuestionActivationController::class, 'subjects']);
    Route::get('/questions', [QuestionActivationController::class, 'index']);
    Route::patch('/bulk', [QuestionActivationController::class, 'bulkUpdate']);
    Route::patch('/questions/{questionId}', [QuestionActivationController::class, 'update']);
});
Route::prefix('/api/v1/assessment-setup/subject-batch')->group(function (): void {
    Route::get('/options', [SubjectBatchUpdateController::class, 'options']);
    Route::get('/packages', [SubjectBatchUpdateController::class, 'packages']);
    Route::get('/packages/{courseId}/subjects', [SubjectBatchUpdateController::class, 'subjects']);
    Route::patch('/packages/{courseId}/subjects', [SubjectBatchUpdateController::class, 'update']);
});

require __DIR__.'/settings.php';
