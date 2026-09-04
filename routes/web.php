<?php

use App\Http\Controllers\Api\V1\DashboardController;
use App\Http\Controllers\Api\V1\OtgPrintController;
use Illuminate\Support\Facades\Route;

Route::redirect('/', '/login')->name('home');

Route::middleware(['auth'])->group(function (): void {
    Route::inertia('/dashboard', 'Dashboard')->name('dashboard');

    Route::inertia(
        '/dashboard/activity-updates',
        'dashboard/activity-updates/Index',
    )->name('dashboard.activity-updates');

    Route::get(
        '/api/v1/dashboard/students',
        [DashboardController::class, 'students'],
    )->name('api.v1.dashboard.students');

    Route::get(
        '/students/{personId}/otg/print',
        [OtgPrintController::class, 'download'],
    )
        ->whereUuid('personId')
        ->name('students.otg.print');
});

require __DIR__.'/settings.php';