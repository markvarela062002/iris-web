<?php

use App\Http\Controllers\Api\V1\ActivitiesController;
use App\Http\Controllers\Api\V1\DashboardController;
use App\Http\Controllers\Api\V1\DocumentsController;
use App\Http\Controllers\Api\V1\JournalsController;
use App\Http\Controllers\Api\V1\OtgController;
use App\Http\Controllers\Api\V1\OtgPrintController;
use App\Http\Controllers\Api\V1\RemoteFileController;
use Illuminate\Support\Facades\Route;

Route::redirect(
    '/',
    '/login',
)->name('home');

Route::middleware(['auth'])->group(function (): void {
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
    )->name('dashboard.activity-updates');

    Route::inertia(
        '/dashboard/uploaded-documents',
        'dashboard/uploaded-documents/Index',
    )->name('dashboard.uploaded-documents');

    Route::inertia(
        '/dashboard/otg-updates',
        'dashboard/otg-updates/Index',
    )->name('dashboard.otg-updates');

    Route::inertia(
        '/dashboard/daily-journals',
        'dashboard/daily-journals/Index',
    )->name('dashboard.daily-journals');

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
    | Remote FTP Files
    |--------------------------------------------------------------------------
    |
    | These routes stream files from the school FTP account selected
    | during login. FTP credentials remain on the Laravel backend.
    |
    */

    /*
     * Documents and journal evidence stored in the uploads folder.
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
     * Activity evidence and files stored in person_task.
     */
    Route::get(
        '/dashboard/files/person-task/{filename}',
        [
            RemoteFileController::class,
            'personTask',
        ],
    )
        ->where(
            'filename',
            '[^/]+',
        )
        ->name(
            'dashboard.files.person-task',
        );

    /*
     * Student electronic signatures stored in images.
     */
    Route::get(
        '/dashboard/files/signatures/{filename}',
        [
            RemoteFileController::class,
            'signature',
        ],
    )
        ->where(
            'filename',
            '[^/]+',
        )
        ->name(
            'dashboard.files.signature',
        );

    /*
    |--------------------------------------------------------------------------
    | Daily Journal Supporting Routes
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
    | Activity Actions
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
    | Uploaded-Document Actions
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
    | Student OTG Printing
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
        ->name('students.otg.print');
});

require __DIR__.'/settings.php';