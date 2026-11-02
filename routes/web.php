<?php

use App\Http\Controllers\Admin\AdminController;
use App\Http\Controllers\AuditController;
use App\Http\Controllers\ClientController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\IntegrationController;
use App\Http\Controllers\RecommendationController;
use App\Http\Controllers\ReportController;
use Illuminate\Support\Facades\Route;

// Strona główna → logowanie / panel (strona ofertowa agencji jest osobno)
Route::get('/', fn () => redirect()->route(auth()->check() ? 'dashboard' : 'login'))->name('home');

// Publiczny raport dla klienta (link z tokenem, bez logowania)
Route::get('r/{token}/{report}', [ReportController::class, 'public'])->name('public.report');
Route::get('r/{token}/{report}/pdf', [ReportController::class, 'pdf'])->name('public.report.pdf');

Route::middleware(['auth'])->group(function () {
    Route::get('dashboard', [DashboardController::class, 'index'])->name('dashboard');
    Route::get('tasks', [DashboardController::class, 'tasks'])->name('tasks');

    Route::resource('clients', ClientController::class)->except('show');
    Route::get('clients/{client}', [ClientController::class, 'show'])->name('clients.show');
    Route::get('clients/{client}/recommendations', [ClientController::class, 'recommendations'])->name('clients.recommendations');
    Route::post('clients/{client}/sync', [ClientController::class, 'sync'])->name('clients.sync');
    Route::post('clients/{client}/archive', [ClientController::class, 'archive'])->name('clients.archive');

    Route::get('clients/{client}/audit', [AuditController::class, 'index'])->name('clients.audit');
    Route::post('clients/{client}/audit', [AuditController::class, 'store'])->name('clients.audit.run');

    Route::get('clients/{client}/integrations', [IntegrationController::class, 'index'])->name('clients.integrations');
    Route::put('clients/{client}/integrations/{provider}', [IntegrationController::class, 'update'])->name('clients.integrations.update');
    Route::delete('clients/{client}/integrations/{integration}', [IntegrationController::class, 'destroy'])->name('clients.integrations.destroy');

    Route::get('clients/{client}/reports', [ReportController::class, 'index'])->name('clients.reports');
    Route::post('clients/{client}/reports', [ReportController::class, 'store'])->name('clients.reports.store');
    Route::get('reports/{report}', [ReportController::class, 'show'])->name('reports.show');
    Route::patch('reports/{report}/note', [ReportController::class, 'updateNote'])->name('reports.note');
    Route::post('reports/{report}/send', [ReportController::class, 'send'])->name('reports.send');
    Route::delete('reports/{report}', [ReportController::class, 'destroy'])->name('reports.destroy');

    Route::patch('recommendations/{recommendation}', [RecommendationController::class, 'update'])->name('recommendations.update');

    // Panel administratora
    Route::middleware('can:admin')->prefix('admin')->name('admin.')->group(function () {
        Route::get('status', [AdminController::class, 'status'])->name('status');
        Route::post('status/test-mail', [AdminController::class, 'testMail'])->name('status.mail');
        Route::post('status/test-ai', [AdminController::class, 'testAi'])->name('status.ai');
        Route::get('settings', [AdminController::class, 'settings'])->name('settings');
        Route::post('settings', [AdminController::class, 'updateSettings'])->name('settings.update');
        Route::get('team', [AdminController::class, 'team'])->name('team');
        Route::post('team', [AdminController::class, 'storeUser'])->name('team.store');
        Route::post('team/{user}/invite', [AdminController::class, 'resendInvite'])->name('team.invite');
        Route::patch('team/{user}', [AdminController::class, 'updateUser'])->name('team.update');
        Route::delete('team/{user}', [AdminController::class, 'destroyUser'])->name('team.destroy');
    });

    // OAuth
    Route::get('oauth/google/{client}', [IntegrationController::class, 'googleRedirect'])->name('oauth.google');
    Route::get('oauth/google-callback', [IntegrationController::class, 'googleCallback'])->name('oauth.google.callback');
    Route::get('oauth/meta/{client}', [IntegrationController::class, 'metaRedirect'])->name('oauth.meta');
    Route::get('oauth/meta-callback', [IntegrationController::class, 'metaCallback'])->name('oauth.meta.callback');
});

require __DIR__.'/settings.php';
require __DIR__.'/auth.php';
