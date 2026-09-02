<?php

use App\Http\Controllers\Settings\ProfileController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth'])->group(
    function (): void {
        Route::redirect(
            'settings',
            '/settings/profile',
        )->name('settings');

        Route::get(
            'settings/profile',
            [
                ProfileController::class,
                'edit',
            ],
        )->name('profile.edit');

        Route::patch(
            'settings/profile',
            [
                ProfileController::class,
                'update',
            ],
        )->name('profile.update');

        Route::inertia(
            'settings/appearance',
            'settings/Appearance',
        )->name('appearance.edit');
    },
);