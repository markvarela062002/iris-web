<?php

use App\Http\Controllers\Api\V1\DashboardController;
use Illuminate\Support\Facades\Route;

Route::redirect(
    '/',
    '/login',
)->name('home');

Route::middleware(['auth'])->group(
    function (): void {
        Route::inertia(
            '/dashboard',
            'Dashboard',
        )->name('dashboard');

        Route::get(
            '/api/v1/dashboard/students',
            [
                DashboardController::class,
                'students',
            ],
        )->name(
            'api.v1.dashboard.students',
        );
    },
);

require __DIR__.'/settings.php';