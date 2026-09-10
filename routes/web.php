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
use Illuminate\Support\Facades\Route;

Route::redirect(
    '/',
    '/login',
)->name('home');

Route::middleware(['auth'])->group(
    function (): void {
        /*
        |--------------------------------------------------------------------------
        | Inertia pages
        |--------------------------------------------------------------------------
        */

        Route::inertia(
            '/dashboard',
            'Dashboard',
        )->name('dashboard');

        Route::inertia(
            '/dashboard/activity-updates',
            'dashboard/activity-updates/Index',
        )->name(
            'dashboard.activity-updates',
        );

        Route::inertia(
            '/dashboard/uploaded-documents',
            'dashboard/uploaded-documents/Index',
        )->name(
            'dashboard.uploaded-documents',
        );

        Route::inertia(
            '/dashboard/otg-updates',
            'dashboard/otg-updates/Index',
        )->name(
            'dashboard.otg-updates',
        );

        Route::inertia(
            '/dashboard/daily-journals',
            'dashboard/daily-journals/Index',
        )->name(
            'dashboard.daily-journals',
        );

        Route::inertia(
            '/dashboard/theoretical-internal',
            'dashboard/theoretical-internal/Index',
        )->name(
            'dashboard.theoretical-internal',
        );

        Route::inertia(
            '/dashboard/theoretical-external',
            'dashboard/theoretical-external/Index',
        )->name(
            'dashboard.theoretical-external',
        );

        /*
        |--------------------------------------------------------------------------
        | Monitoring pages
        |--------------------------------------------------------------------------
        */

        Route::inertia(
            '/monitoring/activity-updates',
            'monitoring/activity-updates/Index',
        )->name(
            'monitoring.activity-updates',
        );

        Route::inertia(
            '/monitoring/uploaded-documents',
            'monitoring/uploaded-documents/Index',
        )->name(
            'monitoring.uploaded-documents',
        );

        /*
        |--------------------------------------------------------------------------
        | Dashboard DataTables
        |--------------------------------------------------------------------------
        */

        Route::get(
            '/api/v1/dashboard/datatable/students',
            [
                DashboardController::class,
                'students',
            ],
        )->name(
            'api.v1.dashboard.datatable.students',
        );

        Route::get(
            '/api/v1/dashboard/datatable/activity-updates',
            [
                ActivitiesController::class,
                'index',
            ],
        )->name(
            'api.v1.dashboard.datatable.activity-updates',
        );

        Route::get(
            '/api/v1/dashboard/datatable/uploaded-documents',
            [
                DocumentsController::class,
                'index',
            ],
        )->name(
            'api.v1.dashboard.datatable.uploaded-documents',
        );

        Route::get(
            '/api/v1/dashboard/datatable/otg-updates',
            [
                OtgController::class,
                'index',
            ],
        )->name(
            'api.v1.dashboard.datatable.otg-updates',
        );

        Route::get(
            '/api/v1/dashboard/datatable/daily-journals',
            [
                JournalsController::class,
                'index',
            ],
        )->name(
            'api.v1.dashboard.datatable.daily-journals',
        );

        /*
        |--------------------------------------------------------------------------
        | Monitoring DataTables
        |--------------------------------------------------------------------------
        */

        Route::get(
            '/api/v1/monitoring/datatable/activity-updates',
            [
                ActivitiesController::class,
                'index',
            ],
        )->name(
            'api.v1.monitoring.datatable.activity-updates',
        );

        Route::get(
            '/api/v1/monitoring/activity-updates/options',
            [
                ActivitiesController::class,
                'activityOptions',
            ],
        )->name(
            'api.v1.monitoring.activity-updates.options',
        );

        Route::get(
            '/api/v1/monitoring/datatable/uploaded-documents',
            [
                DocumentsController::class,
                'index',
            ],
        )->name(
            'api.v1.monitoring.datatable.uploaded-documents',
        );

        /*
        |--------------------------------------------------------------------------
        | Remote FTP Files
        |--------------------------------------------------------------------------
        */

        Route::get(
            '/dashboard/files/uploads/{filename}',
            [
                RemoteFileController::class,
                'upload',
            ],
        )
            ->where(
                'filename',
                '[^/]+',
            )
            ->name(
                'dashboard.files.upload',
            );

        /*
        |--------------------------------------------------------------------------
        | Daily Journal supporting routes
        |--------------------------------------------------------------------------
        */

        Route::get(
            '/api/v1/dashboard/daily-journals/students',
            [
                JournalsController::class,
                'students',
            ],
        )->name(
            'api.v1.dashboard.daily-journals.students',
        );

        Route::get(
            '/dashboard/daily-journals/print',
            [
                JournalsController::class,
                'download',
            ],
        )->name(
            'dashboard.daily-journals.print',
        );

        /*
        |--------------------------------------------------------------------------
        | Activity actions
        |--------------------------------------------------------------------------
        */

        Route::patch(
            '/api/v1/dashboard/activity-updates/{activityId}/verify',
            [
                ActivitiesController::class,
                'verify',
            ],
        )->name(
            'api.v1.dashboard.activity-updates.verify',
        );

        Route::patch(
            '/api/v1/dashboard/activity-updates/{activityId}/revise',
            [
                ActivitiesController::class,
                'revise',
            ],
        )->name(
            'api.v1.dashboard.activity-updates.revise',
        );

        /*
        |--------------------------------------------------------------------------
        | Uploaded-document actions
        |--------------------------------------------------------------------------
        */

        Route::patch(
            '/api/v1/dashboard/uploaded-documents/{fileUploadId}/verify',
            [
                DocumentsController::class,
                'verify',
            ],
        )->name(
            'api.v1.dashboard.uploaded-documents.verify',
        );

        Route::patch(
            '/api/v1/dashboard/uploaded-documents/{fileUploadId}/revise',
            [
                DocumentsController::class,
                'revise',
            ],
        )->name(
            'api.v1.dashboard.uploaded-documents.revise',
        );

        /*
        |--------------------------------------------------------------------------
        | Student OTG printing
        |--------------------------------------------------------------------------
        */

        Route::get(
            '/students/{personId}/otg/print',
            [
                OtgPrintController::class,
                'download',
            ],
        )
            ->whereUuid('personId')
            ->name(
                'students.otg.print',
            );

        /*
        |--------------------------------------------------------------------------
        | Theoretical Assessments - Internal
        |--------------------------------------------------------------------------
        */

        Route::get(
            '/api/v1/dashboard/datatable/theoretical-assessments',
            [
                TheoreticalAssessmentsController::class,
                'index',
            ],
        )->name(
            'api.v1.dashboard.datatable.theoretical-assessments',
        );

        Route::get(
            '/api/v1/dashboard/theoretical-assessments/options',
            [
                TheoreticalAssessmentsController::class,
                'options',
            ],
        )->name(
            'api.v1.dashboard.theoretical-assessments.options',
        );

        Route::get(
            '/api/v1/dashboard/theoretical-assessments/students',
            [
                TheoreticalAssessmentsController::class,
                'students',
            ],
        )->name(
            'api.v1.dashboard.theoretical-assessments.students',
        );

        Route::get(
            '/api/v1/dashboard/theoretical-assessments/{assessmentId}',
            [
                TheoreticalAssessmentsController::class,
                'show',
            ],
        )->name(
            'api.v1.dashboard.theoretical-assessments.show',
        );

        Route::get(
            '/dashboard/theoretical-assessments/{assessmentId}/certificate',
            [
                TheoreticalAssessmentsController::class,
                'certificate',
            ],
        )->name(
            'dashboard.theoretical-assessments.certificate',
        );

        /*
        |--------------------------------------------------------------------------
        | Theoretical Assessments - External
        |--------------------------------------------------------------------------
        */

        Route::get(
            '/api/v1/dashboard/datatable/theoretical-external',
            [
                TheoreticalExternalAssessmentsController::class,
                'index',
            ],
        )->name(
            'api.v1.dashboard.datatable.theoretical-external',
        );

        Route::get(
            '/api/v1/dashboard/theoretical-external/options',
            [
                TheoreticalExternalAssessmentsController::class,
                'options',
            ],
        )->name(
            'api.v1.dashboard.theoretical-external.options',
        );

        Route::get(
            '/api/v1/dashboard/theoretical-external/{assessmentId}',
            [
                TheoreticalExternalAssessmentsController::class,
                'show',
            ],
        )->name(
            'api.v1.dashboard.theoretical-external.show',
        );

        Route::get(
            '/dashboard/theoretical-external/{assessmentId}/certificate',
            [
                TheoreticalExternalAssessmentsController::class,
                'certificate',
            ],
        )->name(
            'dashboard.theoretical-external.certificate',
        );
    },
);

require __DIR__.'/settings.php';