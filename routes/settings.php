<?php

use App\Http\Controllers\Settings\PasswordController;
use App\Http\Controllers\Settings\ProfileController;
use App\Http\Controllers\Settings\SecurityController;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::middleware('auth')->group(function () {
    Route::redirect('settings', 'settings/profile');

    Route::get('settings/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('settings/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('settings/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');

    Route::get('settings/password', [PasswordController::class, 'edit'])->name('password.edit');
    Route::put('settings/password', [PasswordController::class, 'update'])->name('password.update');

    Route::get('settings/security', [SecurityController::class, 'edit'])->name('security.edit');
    Route::post('settings/security/two-factor/confirm', [SecurityController::class, 'confirm'])->middleware('throttle:10,1')->name('security.2fa.confirm');
    Route::delete('settings/security/two-factor/setup', [SecurityController::class, 'cancelSetup'])->name('security.2fa.cancel');
    Route::post('settings/security/logout-others', [SecurityController::class, 'logoutOthers'])->middleware('throttle:10,1')->name('security.logout-others');
    Route::middleware('password.confirm')->group(function () {
        Route::post('settings/security/two-factor', [SecurityController::class, 'enable'])->name('security.2fa.enable');
        Route::post('settings/security/two-factor/reset', [SecurityController::class, 'reset'])->name('security.2fa.reset');
        Route::delete('settings/security/two-factor', [SecurityController::class, 'disable'])->name('security.2fa.disable');
        Route::post('settings/security/recovery-codes', [SecurityController::class, 'recoveryCodes'])->name('security.recovery-codes');
        Route::delete('settings/security/devices/{device}', [SecurityController::class, 'removeDevice'])->name('security.devices.destroy');
    });

    Route::get('settings/appearance', function () {
        return Inertia::render('settings/appearance');
    })->name('appearance');
});
