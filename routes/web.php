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
use App\Http\Controllers\Api\V1\ActivityTypesController;
use App\Http\Controllers\Api\V1\RequirementTypesController;
use App\Http\Controllers\Api\V1\AlertSetupController;
use App\Http\Controllers\Api\V1\AlertCalendarController;
use App\Http\Controllers\Api\V1\AnnouncementsController;
use App\Http\Controllers\Api\V1\MessageController;
use App\Http\Controllers\Api\V1\TrbOtgContentController;
use Illuminate\Support\Facades\Route;
Route::redirect( '/', '/login')->name('home');

// Student Routes
Route::middleware([ 'auth', 'account.type:student'])->group(function (): void {
    Route::inertia( '/student-dashboard', 'StudentDashboard')->name('student.dashboard');
    Route::get( '/api/v1/student-dashboard/activities', [ ActivitiesController::class, 'studentDashboard'])->name( 'api.v1.student-dashboard.activities');
    Route::get( '/api/v1/student-dashboard/documents', [ DocumentsController::class, 'studentDashboard'])->name( 'api.v1.student-dashboard.documents');
    Route::get( '/api/v1/student-dashboard/otg', [ OtgController::class, 'studentDashboard'])->name( 'api.v1.student-dashboard.otg');
    Route::get( '/api/v1/student-dashboard/journals', [ JournalsController::class, 'studentDashboard'])->name( 'api.v1.student-dashboard.journals');

    // Alerts — Student Calendar
    Route::inertia('/alerts/calendar/student/datatable','alerts/calendar/student/datatable/Index')->name('student.alerts.calendar');
    Route::get('/api/v1/student/alerts/calendar/events',[AlertCalendarController::class,'events'])->name('api.v1.student.alerts.calendar.events');
    Route::get('/api/v1/student/alerts/calendar/events/{type}/{date}',[AlertCalendarController::class,'details'])->whereIn('type',['person_activity','file_upload','person_task','person_journal'])->where('date','\d{4}-\d{2}-\d{2}')->name('api.v1.student.alerts.calendar.details');

    // Alerts — Student Announcements
    Route::inertia('/alerts/announcements/student/datatable','alerts/announcements/student/datatable/Index')->name('student.alerts.announcements');
    Route::get('/api/v1/student/alerts/datatable/announcements',[AnnouncementsController::class,'studentIndex'])->name('api.v1.student.alerts.datatable.announcements');

    // Alerts — Student Messages
    Route::inertia('/alerts/messages/student','alerts/messages/student/Index')->name('student.alerts.messages');
    Route::get('/api/v1/student/alerts/messages',[MessageController::class,'studentFetchMessageModule'])->name('api.v1.student.alerts.messages.index');
    Route::get('/api/v1/student/alerts/messages/administrators',[MessageController::class,'fetchAdministrators'])->name('api.v1.student.alerts.messages.administrators');
    Route::get('/api/v1/student/alerts/messages/students',[MessageController::class,'fetchStudents'])->name('api.v1.student.alerts.messages.students');
    Route::get('/api/v1/student/alerts/messages/{inboxId}/replies',[MessageController::class,'studentFetchMessageReplies'])->where('inboxId','[A-Za-z0-9-]+')->name('api.v1.student.alerts.messages.replies');
    Route::post('/api/v1/student/alerts/messages',[MessageController::class,'studentStoreMessage'])->name('api.v1.student.alerts.messages.store');
    Route::post('/api/v1/student/alerts/messages/{inboxId}/replies',[MessageController::class,'studentStoreMessageReply'])->where('inboxId','[A-Za-z0-9-]+')->name('api.v1.student.alerts.messages.replies.store');

    // Updates — Student Activity Updates
    Route::inertia('/monitoring/activity-updates/student/datatable','monitoring/activity-updates/student/datatable/Index')->name('student.monitoring.activity-updates');
    Route::get('/api/v1/student/activity-updates',[ActivitiesController::class,'studentIndex'])->name('api.v1.student.activity-updates.index');
    Route::get('/api/v1/student/activity-updates/options',[ActivitiesController::class,'studentOptions'])->name('api.v1.student.activity-updates.options');
    Route::post('/api/v1/student/activity-updates',[ActivitiesController::class,'studentStore'])->name('api.v1.student.activity-updates.store');
    Route::post('/api/v1/student/activity-updates/{activityRecordId}',[ActivitiesController::class,'studentUpdate'])->where('activityRecordId','[A-Za-z0-9-]+')->name('api.v1.student.activity-updates.update');
    Route::delete('/api/v1/student/activity-updates/{activityRecordId}',[ActivitiesController::class,'studentDestroy'])->where('activityRecordId','[A-Za-z0-9-]+')->name('api.v1.student.activity-updates.destroy');

    // Updates — Student Documents Uploading
    Route::inertia('/monitoring/uploaded-documents/student/datatable','monitoring/uploaded-documents/student/datatable/Index')->name('student.monitoring.uploaded-documents');
    Route::get('/api/v1/student/uploaded-documents',[DocumentsController::class,'studentIndex'])->name('api.v1.student.uploaded-documents.index');
    Route::get('/api/v1/student/uploaded-documents/options',[DocumentsController::class,'studentOptions'])->name('api.v1.student.uploaded-documents.options');
    Route::post('/api/v1/student/uploaded-documents',[DocumentsController::class,'studentStore'])->name('api.v1.student.uploaded-documents.store');
    Route::post('/api/v1/student/uploaded-documents/{fileUploadId}',[DocumentsController::class,'studentUpdate'])->where('fileUploadId','[A-Za-z0-9-]+')->name('api.v1.student.uploaded-documents.update');
    Route::delete('/api/v1/student/uploaded-documents/{fileUploadId}',[DocumentsController::class,'studentDestroy'])->where('fileUploadId','[A-Za-z0-9-]+')->name('api.v1.student.uploaded-documents.destroy');

    // Updates — Student Training Record Book (OTG)
    Route::inertia('/monitoring/otg-updates/student/datatable','monitoring/otg-updates/student/datatable/Index')->name('student.monitoring.otg-updates');
    Route::get('/api/v1/student/otg',[OtgController::class,'studentIndex'])->name('api.v1.student.otg.index');
    Route::get('/api/v1/student/otg/vessel-options',[OtgController::class,'studentVesselOptions'])->name('api.v1.student.otg.vessel-options');
    Route::post('/api/v1/student/otg/vessels',[OtgController::class,'studentStoreVessel'])->name('api.v1.student.otg.vessels.store');
    Route::put('/api/v1/student/otg/vessels/{vesselId}',[OtgController::class,'studentUpdateVessel'])->whereUuid('vesselId')->name('api.v1.student.otg.vessels.update');
    Route::delete('/api/v1/student/otg/vessels/{vesselId}',[OtgController::class,'studentDestroyVessel'])->whereUuid('vesselId')->name('api.v1.student.otg.vessels.destroy');
    Route::get('/api/v1/student/otg/workbook-options',[OtgController::class,'studentWorkbookOptions'])->name('api.v1.student.otg.workbook-options');
    Route::post('/api/v1/student/otg/workbooks',[OtgController::class,'studentStoreWorkbook'])->name('api.v1.student.otg.workbooks.store');
    Route::delete('/api/v1/student/otg/workbooks/{workbookAssignmentId}',[OtgController::class,'studentDestroyWorkbook'])->whereUuid('workbookAssignmentId')->name('api.v1.student.otg.workbooks.destroy');
    Route::get('/api/v1/student/otg/tasks/{personTaskId}',[OtgController::class,'studentTaskDetails'])->whereUuid('personTaskId')->name('api.v1.student.otg.tasks.show');
    Route::post('/api/v1/student/otg/tasks/{personTaskId}',[OtgController::class,'studentUpdateTask'])->whereUuid('personTaskId')->name('api.v1.student.otg.tasks.update');
    Route::delete('/api/v1/student/otg/tasks/objective-evidence/{fileId}',[OtgController::class,'studentRemoveObjectiveEvidence'])->whereUuid('fileId')->name('api.v1.student.otg.tasks.objective-evidence.destroy');
    Route::delete('/api/v1/student/otg/tasks/proof-of-assessment/{fileId}',[OtgController::class,'studentRemoveProofOfAssessment'])->whereUuid('fileId')->name('api.v1.student.otg.tasks.proof-of-assessment.destroy');
    Route::get('/monitoring/otg-updates/student/print',[OtgPrintController::class,'studentDownload'])->name('student.monitoring.otg-updates.print');

    // Updates — Student Daily Journals
    Route::inertia('/monitoring/daily-journals/student/datatable','monitoring/daily-journals/student/datatable/Index')->name('student.monitoring.daily-journals');
    Route::get('/api/v1/student/daily-journals',[JournalsController::class,'studentIndex'])->name('api.v1.student.daily-journals.index');
    Route::post('/api/v1/student/daily-journals',[JournalsController::class,'studentStore'])->name('api.v1.student.daily-journals.store');
    Route::get('/api/v1/student/daily-journals/{journalId}',[JournalsController::class,'studentShow'])->whereUuid('journalId')->name('api.v1.student.daily-journals.show');
    Route::put('/api/v1/student/daily-journals/{journalId}',[JournalsController::class,'studentUpdate'])->whereUuid('journalId')->name('api.v1.student.daily-journals.update');
    Route::post('/api/v1/student/daily-journals/{journalId}/evidence',[JournalsController::class,'studentUploadEvidence'])->whereUuid('journalId')->name('api.v1.student.daily-journals.evidence');
    Route::post('/api/v1/student/daily-journals/{journalId}/signature',[JournalsController::class,'studentUploadSignature'])->middleware('throttle:10,1')->whereUuid('journalId')->name('api.v1.student.daily-journals.signature');
    Route::delete('/api/v1/student/daily-journals/{journalId}',[JournalsController::class,'studentDestroy'])->whereUuid('journalId')->name('api.v1.student.daily-journals.destroy');
    Route::get('/monitoring/daily-journals/student/print',[JournalsController::class,'studentDownload'])->name('student.monitoring.daily-journals.print');

    // Assessments — Student Theoretical Internal
    Route::inertia('/assessments/theoretical-internal/student/datatable','assessments/theoretical-internal/student/datatable/Index')->name('student.assessments.theoretical-internal');
    Route::get('/api/v1/student/theoretical-assessments',[TheoreticalAssessmentsController::class,'studentIndex'])->name('api.v1.student.theoretical-assessments.index');
    Route::get('/assessments/theoretical-internal/student/{assessmentId}/certificate',[TheoreticalAssessmentsController::class,'studentCertificate'])->whereUuid('assessmentId')->name('student.assessments.theoretical-internal.certificate');
    Route::get('/assessments/theoretical-internal/student/{assessmentId}/exam',[TheoreticalAssessmentsController::class,'studentExamPage'])->whereUuid('assessmentId')->name('student.assessments.theoretical-internal.exam');
    Route::get('/api/v1/student/theoretical-assessments/{assessmentId}/exam',[TheoreticalAssessmentsController::class,'studentExamState'])->whereUuid('assessmentId')->name('api.v1.student.theoretical-assessments.exam.state');
    Route::post('/api/v1/student/theoretical-assessments/{assessmentId}/exam/start',[TheoreticalAssessmentsController::class,'studentStartExam'])->whereUuid('assessmentId')->name('api.v1.student.theoretical-assessments.exam.start');
    Route::patch('/api/v1/student/theoretical-assessments/{assessmentId}/exam/questions/{questionAttemptId}',[TheoreticalAssessmentsController::class,'studentSaveExamAnswer'])->whereUuid('assessmentId')->whereUuid('questionAttemptId')->name('api.v1.student.theoretical-assessments.exam.answer');
    Route::post('/api/v1/student/theoretical-assessments/{assessmentId}/exam/topics/{topicAttemptId}/submit',[TheoreticalAssessmentsController::class,'studentSubmitExamTopic'])->whereUuid('assessmentId')->whereUuid('topicAttemptId')->name('api.v1.student.theoretical-assessments.exam.topic.submit');
    Route::post('/api/v1/student/theoretical-assessments/{assessmentId}/exam/timeout',[TheoreticalAssessmentsController::class,'studentTimeoutExam'])->whereUuid('assessmentId')->name('api.v1.student.theoretical-assessments.exam.timeout');

    // Assessments — Student Practical Internal
    Route::inertia('/assessments/practical-internal/student/datatable','assessments/practical-internal/student/datatable/Index')->name('student.assessments.practical-internal');
    Route::get('/api/v1/student/practical-internal',[PracticalInternalAssessmentsController::class,'studentIndex'])->name('api.v1.student.practical-internal.index');
    Route::get('/api/v1/student/practical-internal/{assessmentId}',[PracticalInternalAssessmentsController::class,'studentShow'])->whereUuid('assessmentId')->name('api.v1.student.practical-internal.show');
    Route::post('/api/v1/student/practical-internal/{assessmentId}/items/{itemId}',[PracticalInternalAssessmentsController::class,'studentSaveItem'])->whereUuid('assessmentId')->whereUuid('itemId')->name('api.v1.student.practical-internal.items.update');
    Route::post('/api/v1/student/practical-internal/{assessmentId}/submit',[PracticalInternalAssessmentsController::class,'studentSubmit'])->whereUuid('assessmentId')->name('api.v1.student.practical-internal.submit');
});

// Administrator and Staff Routes
Route::middleware([ 'auth', 'account.type:administrator'])->group(function (): void {

    // Inertia pages

    // Dashboard
    Route::inertia('/dashboard', 'Dashboard')->name('dashboard');
    Route::inertia('/dashboard/activity-updates', 'dashboard/activity-updates/Index')->name('dashboard.activity-updates');
    Route::inertia('/dashboard/uploaded-documents', 'dashboard/uploaded-documents/Index')->name('dashboard.uploaded-documents');
    Route::inertia('/dashboard/otg-updates', 'dashboard/otg-updates/Index')->name('dashboard.otg-updates');
    Route::inertia('/dashboard/daily-journals', 'dashboard/daily-journals/Index')->name('dashboard.daily-journals');
    Route::inertia('/dashboard/theoretical-internal', 'dashboard/theoretical-internal/datatable/Index')->name('dashboard.theoretical-internal');
    Route::inertia('/dashboard/theoretical-external', 'dashboard/theoretical-external/datatable/Index')->name('dashboard.theoretical-external');
    Route::inertia('/dashboard/theoretical-internal/batch', 'dashboard/theoretical-internal/batch/Index')->name('dashboard.theoretical-internal.batch');
    Route::inertia('/dashboard/theoretical-external/batch', 'dashboard/theoretical-external/batch/Index')->name('dashboard.theoretical-external.batch');
    Route::inertia('/dashboard/practical-internal', 'dashboard/practical-internal/datatable/Index')->name('dashboard.practical-internal');
    Route::inertia('/dashboard/practical-external', 'dashboard/practical-external/datatable/Index')->name('dashboard.practical-external');
    Route::inertia('/dashboard/practical-internal/batch', 'dashboard/practical-internal/batch/Index')->name('dashboard.practical-internal.batch');
    Route::inertia('/dashboard/practical-external/batch', 'dashboard/practical-external/batch/Index')->name('dashboard.practical-external.batch');

    // Alerts — Calendar
    Route::inertia('/alerts/calendar/datatable','alerts/calendar/datatable/Index')->name('alerts.calendar');
    Route::get('/api/v1/alerts/calendar/events',[AlertCalendarController::class,'events'])->name('api.v1.alerts.calendar.events');
    Route::get('/api/v1/alerts/calendar/events/{type}/{date}',[AlertCalendarController::class,'details'])->whereIn('type',['person_activity','file_upload','person_task','person_journal'])->where('date','\d{4}-\d{2}-\d{2}')->name('api.v1.alerts.calendar.details');

    // Alerts — Announcements
    Route::inertia('/alerts/announcements/datatable','alerts/announcements/datatable/Index')->name('alerts.announcements');
    Route::get('/api/v1/alerts/datatable/announcements',[AnnouncementsController::class,'index'])->name('api.v1.alerts.datatable.announcements');
    Route::post('/api/v1/alerts/announcements',[AnnouncementsController::class,'store'])->name('api.v1.alerts.announcements.store');
    Route::put('/api/v1/alerts/announcements/{announcementId}',[AnnouncementsController::class,'update'])->whereUuid('announcementId')->name('api.v1.alerts.announcements.update');
    Route::delete('/api/v1/alerts/announcements/{announcementId}',[AnnouncementsController::class,'destroy'])->whereUuid('announcementId')->name('api.v1.alerts.announcements.destroy');

    // Alerts — Messages
    Route::inertia('/alerts/messages','alerts/messages/Index')->name('alerts.messages');
    Route::get('/api/v1/alerts/messages',[MessageController::class,'fetchMessageModule'])->name('api.v1.alerts.messages.index');
    Route::get('/api/v1/alerts/messages/administrators',[MessageController::class,'fetchAdministrators'])->name('api.v1.alerts.messages.administrators');
    Route::get('/api/v1/alerts/messages/students',[MessageController::class,'fetchStudents'])->name('api.v1.alerts.messages.students');
    Route::get('/api/v1/alerts/messages/{inboxId}/replies',[MessageController::class,'fetchMessageReplies'])->name('api.v1.alerts.messages.replies');
    Route::post('/api/v1/alerts/messages',[MessageController::class,'storeMessage'])->name('api.v1.alerts.messages.store');
    Route::post('/api/v1/alerts/messages/{inboxId}/replies',[MessageController::class,'storeMessageReply'])->name('api.v1.alerts.messages.replies.store');
    Route::delete('/api/v1/alerts/messages/{inboxId}/history',[MessageController::class,'deleteConversationHistory'])->where('inboxId','[A-Za-z0-9-]+');
    Route::delete('/api/v1/alerts/messages/{inboxId}/original',[MessageController::class,'deleteOriginalMessage'])->where('inboxId','[A-Za-z0-9-]+');
    Route::delete('/api/v1/alerts/messages/{inboxId}/replies/{replyId}',[MessageController::class,'deleteMessageReply'])->where(['inboxId' =>'[A-Za-z0-9-]+','replyId' =>'[A-Za-z0-9-]+']);

    // Monitoring
    Route::inertia('/monitoring/activity-updates', 'monitoring/activity-updates/Index')->name('monitoring.activity-updates');
    Route::inertia('/monitoring/uploaded-documents', 'monitoring/uploaded-documents/Index')->name('monitoring.uploaded-documents');
    Route::inertia('/monitoring/otg-updates', 'monitoring/otg-updates/Index')->name('monitoring.otg-updates');
    Route::inertia('/monitoring/daily-journals', 'dashboard/daily-journals/Index')->name('monitoring.daily-journals');
    Route::inertia('/monitoring/reports', 'monitoring/reports/Index')->name('monitoring.reports');

    // Setup — Activity Types
    Route::inertia('/setup/activity-types/datatable','setup/activity-types/datatable/Index')->name('setup.activity-types');
    Route::get('/api/v1/setup/activity-types/datatable',[ActivityTypesController::class,'index'])->name('api.v1.setup.datatable.activity-types');
    Route::post('/api/v1/setup/activity-types',[ActivityTypesController::class,'store'])->name('api.v1.setup.activity-types.store');
    Route::put('/api/v1/setup/activity-types/{activityId}',[ActivityTypesController::class,'update'])->whereUuid('activityId')->name('api.v1.setup.activity-types.update');
    Route::delete('/api/v1/setup/activity-types/{activityId}',[ActivityTypesController::class,'destroy'])->whereUuid('activityId')->name('api.v1.setup.activity-types.destroy');

    // Setup — Requirement Types
    Route::inertia('/setup/requirement-types/datatable','setup/requirement-types/datatable/Index')->name('setup.requirement-types');
    Route::get('/api/v1/setup/requirement-types/datatable',[RequirementTypesController::class,'index'])->name('api.v1.setup.datatable.requirement-types');
    Route::get('/api/v1/setup/requirement-types/options',[RequirementTypesController::class,'options'])->name('api.v1.setup.requirement-types.options');
    Route::post('/api/v1/setup/requirement-types',[RequirementTypesController::class,'store'])->name('api.v1.setup.requirement-types.store');
    Route::put('/api/v1/setup/requirement-types/{requirementId}',[RequirementTypesController::class,'update'])->whereUuid('requirementId')->name('api.v1.setup.requirement-types.update');
    Route::delete('/api/v1/setup/requirement-types/{requirementId}',[RequirementTypesController::class,'destroy'])->whereUuid('requirementId')->name('api.v1.setup.requirement-types.destroy');

    // Setup - Alert Setup
    Route::inertia('/setup/alert-setup/datatable','setup/alert-setup/datatable/Index')->name('setup.alert-setup');
    Route::get('/api/v1/setup/alert-setup/datatable',[AlertSetupController::class,'index'])->name('api.v1.setup.alert-setup.datatable');
    Route::put('/api/v1/setup/alert-setup/{alertSetupId}',[AlertSetupController::class,'update'])->whereUuid('alertSetupId')->name('api.v1.setup.alert-setup.update');

    // Setup — TRB - OTG Content Setup
    Route::inertia('/setup/trb-otg-content/datatable','setup/trb-otg-content/datatable/Index')->name('setup.trb-otg-content');
    Route::get('/api/v1/setup/trb-otg-content/options',[TrbOtgContentController::class,'options'])->name('api.v1.setup.trb-otg-content.options');
    Route::get('/api/v1/setup/trb-otg-content/template',[TrbOtgContentController::class,'template'])->name('api.v1.setup.trb-otg-content.template');
    Route::get('/api/v1/setup/trb-otg-content/{trbTypeId}/content',[TrbOtgContentController::class,'show'])->whereUuid('trbTypeId')->name('api.v1.setup.trb-otg-content.show');
    Route::post('/api/v1/setup/trb-otg-content/import',[TrbOtgContentController::class,'import'])->middleware('throttle:10,1')->name('api.v1.setup.trb-otg-content.import');
    Route::delete('/api/v1/setup/trb-otg-content/{trbTypeId}',[TrbOtgContentController::class,'destroy'])->whereUuid('trbTypeId')->name('api.v1.setup.trb-otg-content.destroy');

    // Assessment Setup
    Route::inertia('/assessment-setup/question-bank', 'assessment-setup/question-bank/datatable/Index')->name('assessment-setup.question-bank');
    Route::inertia('/assessment-setup/question-bank-upload', 'assessment-setup/question-bank-upload/datatable/Index')->name('assessment-setup.question-bank-upload');
    Route::inertia('/assessment-setup/question-bank-activation', 'assessment-setup/question-bank-activation/datatable/Index')->name('assessment-setup.question-bank-activation');
    Route::inertia('/assessment-setup/exam-session', 'assessment-setup/exam-session/datatable/Index')->name('assessment-setup.exam-session');
    Route::inertia('/assessment-setup/exam-package', 'assessment-setup/exam-package/datatable/Index')->name('assessment-setup.exam-package');
    Route::inertia('/assessment-setup/practical-assessment-setup', 'assessment-setup/practical-assessment-setup/datatable/Index')->name('assessment-setup.practical-assessment-setup');
    Route::inertia('/assessment-setup/rubric-setup', 'assessment-setup/rubric-setup/datatable/Index')->name('assessment-setup.rubric-setup');
    Route::inertia('/assessment-setup/subject-batch', 'assessment-setup/subject-batch/datatable/Index')->name('assessment-setup.subject-batch');

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
    Route::inertia('/databases/students/datatable', 'databases/students/datatable/Index')->name('databases.students');
    Route::inertia('/databases/students/profile/new','databases/students/profile/Index',['studentId' => null])->name('databases.students.profile.create');
    Route::inertia('/databases/students/profile/{studentId}', 'databases/students/profile/Index',['studentId' => fn () =>(string) request()->route('studentId')])->whereUuid('studentId')->name('databases.students.edit');
    Route::post('/api/v1/databases/students', [StudentsController::class,'store'])->name('api.v1.databases.students.store');
    Route::get('/api/v1/databases/datatable/students', [StudentsController::class, 'index'])->name('api.v1.databases.datatable.students');
    Route::get('/api/v1/databases/students/options', [StudentsController::class, 'options'])->name('api.v1.databases.students.options');
    Route::get('/api/v1/databases/students/{studentId}', [StudentsController::class, 'show'])->whereUuid('studentId')->name('api.v1.databases.students.show');
    Route::put('/api/v1/databases/students/{studentId}', [StudentsController::class, 'update'])->whereUuid('studentId')->name('api.v1.databases.students.update');
    Route::post('/api/v1/databases/students/{studentId}/send-credentials', [StudentsController::class, 'sendCredentials'])->middleware('throttle:5,1')->whereUuid('studentId')->name('api.v1.databases.students.send-credentials');
    Route::post('/api/v1/databases/students/{studentId}/photo', [StudentsController::class, 'uploadPhoto'])->whereUuid('studentId')->name('api.v1.databases.students.photo');

    // Databases — Batch Upload
    Route::inertia('/databases/batch-upload/datatable', 'databases/batch-upload/datatable/Index')->name('databases.batch-upload');
    Route::prefix('/api/v1/databases/students/batch-upload')->controller(StudentBatchUploadController::class)->group(function (): void {
        Route::get('/template', 'template')->name('api.v1.databases.students.batch-upload.template');
        Route::post('/import', 'import')->middleware('throttle:10,1')->name('api.v1.databases.students.batch-upload.import');
    });

    // Databases - Student List Report
    Route::inertia('/databases/student-list-report/datatable', 'databases/student-list-report/datatable/Index')->name('databases.student-list-report');
    Route::prefix('/api/v1/databases/student-list-report')->controller(StudentListReportController::class)->group(function (): void {
        Route::get('/options', 'options')->name('api.v1.databases.student-list-report.options');
        Route::get('/export', 'export')->middleware('throttle:10,1')->name('api.v1.databases.student-list-report.export');
        Route::get('/', 'index')->name('api.v1.databases.student-list-report.index');
    });

    // Dashboard — authenticated API and supporting routes
    Route::get('/api/v1/dashboard/datatable/students', [DashboardController::class, 'students'])->name('api.v1.dashboard.datatable.students');
    Route::get('/api/v1/dashboard/datatable/activity-updates', [ActivitiesController::class, 'index'])->name('api.v1.dashboard.datatable.activity-updates');
    Route::get('/api/v1/dashboard/datatable/uploaded-documents', [DocumentsController::class, 'index'])->name('api.v1.dashboard.datatable.uploaded-documents');
    Route::get('/api/v1/dashboard/datatable/otg-updates', [OtgController::class, 'index'])->name('api.v1.dashboard.datatable.otg-updates');
    Route::get('/api/v1/dashboard/datatable/daily-journals', [JournalsController::class, 'index'])->name('api.v1.dashboard.datatable.daily-journals');
    Route::get('/dashboard/files/uploads/{filename}', [RemoteFileController::class, 'upload'])->where('filename', '[^/]+')->name('dashboard.files.upload');
    Route::get('/dashboard/files/person-task/{filename}', [RemoteFileController::class, 'personTask'])->where('filename', '[^/]+')->name('dashboard.files.person-task');
    Route::get('/dashboard/files/signatures/{filename}', [RemoteFileController::class, 'signature'])->where('filename', '[^/]+')->name('dashboard.files.signature');
    Route::get('/api/v1/dashboard/daily-journals/students', [JournalsController::class, 'students'])->name('api.v1.dashboard.daily-journals.students');
    Route::get('/dashboard/daily-journals/print', [JournalsController::class, 'download'])->name('dashboard.daily-journals.print');
    Route::patch('/api/v1/dashboard/activity-updates/{activityId}/verify', [ActivitiesController::class, 'verify'])->name('api.v1.dashboard.activity-updates.verify');
    Route::patch('/api/v1/dashboard/activity-updates/{activityId}/revise', [ActivitiesController::class, 'revise'])->name('api.v1.dashboard.activity-updates.revise');
    Route::patch('/api/v1/dashboard/uploaded-documents/{fileUploadId}/verify', [DocumentsController::class, 'verify'])->name('api.v1.dashboard.uploaded-documents.verify');
    Route::patch('/api/v1/dashboard/uploaded-documents/{fileUploadId}/revise', [DocumentsController::class, 'revise'])->name('api.v1.dashboard.uploaded-documents.revise');
    Route::get('/students/{personId}/otg/print', [OtgPrintController::class, 'download'])->whereUuid('personId')->name('students.otg.print');
    Route::get('/api/v1/dashboard/datatable/theoretical-assessments', [TheoreticalAssessmentsController::class, 'index'])->name('api.v1.dashboard.datatable.theoretical-assessments');
    Route::get('/api/v1/dashboard/theoretical-assessments/options', [TheoreticalAssessmentsController::class, 'options'])->name('api.v1.dashboard.theoretical-assessments.options');
    Route::get('/api/v1/dashboard/theoretical-assessments/students', [TheoreticalAssessmentsController::class, 'students'])->name('api.v1.dashboard.theoretical-assessments.students');
    Route::get('/api/v1/dashboard/theoretical-assessments/{assessmentId}', [TheoreticalAssessmentsController::class, 'show'])->name('api.v1.dashboard.theoretical-assessments.show');
    Route::get('/dashboard/theoretical-assessments/{assessmentId}/certificate', [TheoreticalAssessmentsController::class, 'certificate'])->name('dashboard.theoretical-assessments.certificate');
    Route::get('/api/v1/dashboard/datatable/theoretical-external', [TheoreticalExternalAssessmentsController::class, 'index'])->name('api.v1.dashboard.datatable.theoretical-external');
    Route::get('/api/v1/dashboard/theoretical-external/options', [TheoreticalExternalAssessmentsController::class, 'options'])->name('api.v1.dashboard.theoretical-external.options');
    Route::get('/api/v1/dashboard/theoretical-external/{assessmentId}', [TheoreticalExternalAssessmentsController::class, 'show'])->name('api.v1.dashboard.theoretical-external.show');
    Route::get('/dashboard/theoretical-external/{assessmentId}/certificate', [TheoreticalExternalAssessmentsController::class, 'certificate'])->name('dashboard.theoretical-external.certificate');
    Route::get('/api/v1/dashboard/datatable/practical-internal', [PracticalInternalAssessmentsController::class, 'index'])->name('api.v1.dashboard.datatable.practical-internal');
    Route::get('/api/v1/dashboard/practical-internal/options', [PracticalInternalAssessmentsController::class, 'options'])->name('api.v1.dashboard.practical-internal.options');
    Route::get('/api/v1/dashboard/practical-internal/{assessmentId}', [PracticalInternalAssessmentsController::class, 'show'])->where('assessmentId', '[A-Za-z0-9-]+')->name('api.v1.dashboard.practical-internal.show');
    Route::patch('/api/v1/dashboard/practical-internal/{assessmentId}/grade', [PracticalInternalAssessmentsController::class, 'grade'])->where('assessmentId', '[A-Za-z0-9-]+')->name('api.v1.dashboard.practical-internal.grade');
    Route::get('/api/v1/dashboard/datatable/practical-external', [PracticalExternalAssessmentsController::class, 'index'])->name('api.v1.dashboard.datatable.practical-external');
    Route::get('/api/v1/dashboard/practical-external/options', [PracticalExternalAssessmentsController::class, 'options'])->name('api.v1.dashboard.practical-external.options');
    Route::get('/api/v1/dashboard/practical-external/{assessmentId}', [PracticalExternalAssessmentsController::class, 'show'])->where('assessmentId', '[A-Za-z0-9-]+')->name('api.v1.dashboard.practical-external.show');
    Route::patch('/api/v1/dashboard/practical-external/{assessmentId}/grade', [PracticalExternalAssessmentsController::class, 'grade'])->where('assessmentId', '[A-Za-z0-9-]+')->name('api.v1.dashboard.practical-external.grade');
    Route::get('/api/v1/dashboard/datatable/theoretical-batches', [TheoreticalBatchController::class, 'index'])->name('api.v1.dashboard.datatable.theoretical-batches');
    Route::get('/api/v1/dashboard/theoretical/batch/options', [TheoreticalBatchController::class, 'options'])->name('api.v1.dashboard.theoretical.batch.options');
    Route::get('/api/v1/dashboard/theoretical/batch/students', [TheoreticalBatchController::class, 'students'])->name('api.v1.dashboard.theoretical.batch.students');
    Route::post('/api/v1/dashboard/theoretical/batch', [TheoreticalBatchController::class, 'store'])->name('api.v1.dashboard.theoretical.batch.store');
    Route::post('/api/v1/dashboard/theoretical-assessments', [TheoreticalAssessmentsController::class, 'store'])->name('api.v1.dashboard.theoretical-assessments.store');
    Route::get('/api/v1/dashboard/theoretical/batch/{batchId}', [TheoreticalBatchController::class, 'show'])->where('batchId', '[A-Za-z0-9-]+')->name('api.v1.dashboard.theoretical.batch.show');
    Route::patch('/api/v1/dashboard/theoretical/batch/{batchId}', [TheoreticalBatchController::class, 'update'])->where('batchId', '[A-Za-z0-9-]+')->name('api.v1.dashboard.theoretical.batch.update');
    Route::get('/api/v1/dashboard/datatable/theoretical-external-batches', [TheoreticalExternalBatchController::class, 'index'])->name('api.v1.dashboard.datatable.theoretical-external-batches');
    Route::get('/api/v1/dashboard/theoretical-external/batch/options', [TheoreticalExternalBatchController::class, 'options'])->name('api.v1.dashboard.theoretical-external.batch.options');
    Route::post('/api/v1/dashboard/theoretical-external/batch', [TheoreticalExternalBatchController::class, 'store'])->name('api.v1.dashboard.theoretical-external.batch.store');
    Route::post('/api/v1/dashboard/theoretical-external', [TheoreticalExternalAssessmentsController::class, 'store'])->name('api.v1.dashboard.theoretical-external.store');
    Route::get('/api/v1/dashboard/theoretical-external/batch/{batchId}', [TheoreticalExternalBatchController::class, 'show'])->where('batchId', '[A-Za-z0-9-]+')->name('api.v1.dashboard.theoretical-external.batch.show');
    Route::prefix('/api/v1/dashboard/practical-internal/batches')->name('api.v1.dashboard.practical-internal.batches.')->controller(PracticalInternalBatchController::class)->group(function (): void {
        Route::get('/', 'index')->name('index');
        Route::get('/options', 'options')->name('options');
        Route::get('/students', 'students')->name('students');
        Route::post('/', 'store')->name('store');
        Route::get('/{batchId}', 'show')->whereUuid('batchId')->name('show');
    });
    Route::post('/api/v1/dashboard/practical-internal', [PracticalInternalAssessmentsController::class, 'store'])->name('api.v1.dashboard.practical-internal.store');
    Route::prefix('/api/v1/dashboard/practical-external/batches')->name('api.v1.dashboard.practical-external.batches.')->controller(PracticalExternalBatchController::class)->group(function (): void {
        Route::get('/', 'index')->name('index');
        Route::get('/options', 'options')->name('options');
        Route::post('/import', 'import')->name('import');
        Route::post('/', 'store')->name('store');
        Route::get('/{batchId}', 'show')->whereUuid('batchId')->name('show');
    });
    Route::post('/api/v1/dashboard/practical-external', [PracticalExternalAssessmentsController::class, 'store'])->name('api.v1.dashboard.practical-external.store');

    // Monitoring — authenticated API and supporting routes
    Route::get('/api/v1/monitoring/datatable/activity-updates', [ActivitiesController::class, 'index'])->name('api.v1.monitoring.datatable.activity-updates');
    Route::get('/api/v1/monitoring/activity-updates/options', [ActivitiesController::class, 'activityOptions'])->name('api.v1.monitoring.activity-updates.options');
    Route::get('/api/v1/monitoring/datatable/uploaded-documents', [DocumentsController::class, 'index'])->name('api.v1.monitoring.datatable.uploaded-documents');
    Route::get('/api/v1/monitoring/datatable/otg-updates', [OtgController::class, 'index'])->name('api.v1.monitoring.datatable.otg-updates');
    Route::get('/api/v1/monitoring/reports/options', [ReportsController::class, 'options'])->name('api.v1.monitoring.reports.options');
    Route::get('/api/v1/monitoring/reports', [ReportsController::class, 'index'])->name('api.v1.monitoring.reports.index');

    // Monitoring — Daily Journals
    Route::inertia('/monitoring/daily-journals/datatable/{journalId}','monitoring/daily-journals/datatable/Index',['journalId' => fn () => (string) request()->route('journalId')])->whereUuid('journalId')->name('monitoring.daily-journals.edit');
    Route::prefix('/api/v1/monitoring/daily-journals')->controller(JournalsController::class)->group(function (): void {
        Route::get('/{journalId}', 'show')->whereUuid('journalId')->name('api.v1.monitoring.daily-journals.show');
        Route::put('/{journalId}', 'update')->whereUuid('journalId')->name('api.v1.monitoring.daily-journals.update');
        Route::post('/{journalId}/evidence', 'uploadEvidence')->whereUuid('journalId')->name('api.v1.monitoring.daily-journals.evidence');
        Route::post('/{journalId}/signature', 'uploadSignature')->middleware('throttle:10,1')->whereUuid('journalId')->name('api.v1.monitoring.daily-journals.signature');
    });

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
    Route::put( '/api/v1/dashboard/theoretical-assessments/{assessmentId}', [TheoreticalAssessmentsController::class, 'update'])->name('api.v1.dashboard.theoretical-assessments.update');
    Route::get('/api/v1/dashboard/theoretical-external/{assessmentId}/edit', [TheoreticalExternalAssessmentsController::class, 'edit']) ->name('api.v1.dashboard.theoretical-external.edit');
    Route::put('/api/v1/dashboard/theoretical-external/{assessmentId}', [TheoreticalExternalAssessmentsController::class, 'update']) ->name('api.v1.dashboard.theoretical-external.update');
    Route::get('/api/v1/dashboard/practical-external/{assessmentId}/edit', [PracticalExternalAssessmentsController::class, 'edit']) ->name('api.v1.dashboard.practical-external.edit');
    Route::put('/api/v1/dashboard/practical-external/{assessmentId}', [PracticalExternalAssessmentsController::class, 'update']) ->name('api.v1.dashboard.practical-external.update');
});

// Dashboard — existing routes outside the auth group
Route::get('/api/v1/dashboard/reports/yearly', [DashboardController::class, 'yearlyReport'])->name('api.v1.dashboard.reports.yearly');

// Assessment Setup — existing routes outside the auth group
Route::get('/api/v1/assessment-setup/datatable/exam-sessions', [ExamSessionController::class, 'index'])->name('api.v1.assessment-setup.datatable.exam-sessions');
Route::post('/api/v1/assessment-setup/exam-sessions', [ExamSessionController::class, 'store'])->name('api.v1.assessment-setup.exam-sessions.store');
Route::get('/api/v1/assessment-setup/exam-sessions/{examSessionId}', [ExamSessionController::class, 'show'])->name('api.v1.assessment-setup.exam-sessions.show');
Route::put('/api/v1/assessment-setup/exam-sessions/{examSessionId}', [ExamSessionController::class, 'update'])->name('api.v1.assessment-setup.exam-sessions.update');
Route::delete('/api/v1/assessment-setup/exam-sessions/{examSessionId}', [ExamSessionController::class, 'destroy'])->name('api.v1.assessment-setup.exam-sessions.destroy');
Route::get('/api/v1/assessment-setup/datatable/exam-packages', [ExamPackageController::class, 'index'])->name('api.v1.assessment-setup.datatable.exam-packages');
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
