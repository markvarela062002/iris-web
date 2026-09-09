<?php

use App\Http\Controllers\Api\V1\ActivitiesController;
use App\Http\Controllers\Api\V1\DashboardController;
use App\Http\Controllers\Api\V1\DocumentsController;
use App\Http\Controllers\Api\V1\JournalsController;
use App\Http\Controllers\Api\V1\OtgController;
use App\Http\Controllers\Api\V1\OtgPrintController;
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

        /*
         * Activity Updates List page.
         */
        Route::inertia(
            '/monitoring/activity-updates',
            'monitoring/activity-updates/Index',
        )->name(
            'monitoring.activity-updates',
        );

        /*
         * Daily Journals page.
         */
        Route::inertia(
            '/dashboard/daily-journals',
            'dashboard/daily-journals/Index',
        )->name(
            'dashboard.daily-journals',
        );

        /*
        |--------------------------------------------------------------------------
        | Dashboard datatables
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

        /*
         * Activity Updates Datatable.
         */
        Route::get(
            '/api/v1/monitoring/datatable/activity-updates',
            [
                ActivitiesController::class,
                'updates',
            ],
        )->name(
            'api.v1.monitoring.datatable.activity-updates',
        );

        /*
         * Activity options for Monitoring filter.
         */
        Route::get(
            '/api/v1/monitoring/activity-updates/options',
            [
                ActivitiesController::class,
                'activityOptions',
            ],
        )->name(
            'api.v1.monitoring.activity-updates.options',
        );

        /*
         * Daily Journals DataTable.
         */
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
        | Daily Journal supporting routes
        |--------------------------------------------------------------------------
        */

        /*
         * Student search for the PrimeVue AutoComplete.
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

        /*
         * Generate and display a student's Daily Journals PDF.
         */
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
    },
);

require __DIR__.'/settings.php';