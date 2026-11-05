<?php

use App\Http\Controllers\Admin\AdminController;
use App\Http\Controllers\AgencyController;
use App\Http\Controllers\AuditController;
use App\Http\Controllers\BillingController;
use App\Http\Controllers\CareController;
use App\Http\Controllers\ClientController;
use App\Http\Controllers\CredentialController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\FinanceController;
use App\Http\Controllers\IntegrationController;
use App\Http\Controllers\QuoteController;
use App\Http\Controllers\RecommendationController;
use App\Http\Controllers\ReportController;
use App\Http\Controllers\RequestFormController;
use App\Http\Controllers\SearchController;
use App\Http\Controllers\SeoController;
use App\Http\Controllers\SiteController;
use App\Http\Controllers\TodayController;
use App\Http\Controllers\VaultController;
use App\Http\Controllers\WebAuditController;
use App\Http\Controllers\WordPressController;
use App\Http\Controllers\WorkController;
use Illuminate\Support\Facades\Route;

// Strona główna → logowanie / panel (strona ofertowa agencji jest osobno)
Route::get('/', fn () => redirect()->route(auth()->check() ? 'today' : 'login'))->name('home');

// Publiczny raport dla klienta (link z tokenem, bez logowania)
Route::get('r/{token}/{report}', [ReportController::class, 'public'])->name('public.report');
Route::get('r/{token}/{report}/pdf', [ReportController::class, 'pdf'])->name('public.report.pdf');
Route::get('a/{token}/{audit}', [SiteController::class, 'public'])->name('public.site-audit');
// Oferta dla klienta: podgląd, PDF, akceptacja / odrzucenie (link z tokenem, bez logowania)
Route::get('o/{token}', [QuoteController::class, 'public'])->name('public.quote');
// Audyt dowolnej strony – raport dla potencjalnego klienta
Route::get('audyt/{token}', [WebAuditController::class, 'public'])->name('public.web-audit');
Route::get('audyt/{token}/pdf', [WebAuditController::class, 'pdf'])->name('public.web-audit.pdf');
// Formularz zgłoszeń dla klienta / agencji (link z tokenem, bez logowania)
Route::get('zgloszenie/{token}', [RequestFormController::class, 'show'])->name('public.request');
Route::post('zgloszenie/{token}', [RequestFormController::class, 'submit'])->middleware('throttle:6,10')->name('public.request.submit');
Route::get('o/{token}/pdf', [QuoteController::class, 'pdf'])->name('public.quote.pdf');
Route::post('o/{token}', [QuoteController::class, 'decide'])->middleware('throttle:10,1')->name('public.quote.decide');
Route::get('a/{token}/{audit}/pdf', [SiteController::class, 'pdf'])->name('public.site-audit.pdf');

Route::middleware(['auth'])->group(function () {
    Route::get('today', [TodayController::class, 'index'])->name('today');
    Route::get('search', SearchController::class)->middleware('throttle:120,1')->name('search');
    Route::get('dashboard', [DashboardController::class, 'index'])->name('dashboard');

    // Stoper przy zadaniach, zgłoszenia, przypomnienia o kontakcie
    Route::post('care/tasks/{task}/timer/start', [WorkController::class, 'timerStart'])->name('timer.start');
    Route::post('care/tasks/{task}/timer/stop', [WorkController::class, 'timerStop'])->name('timer.stop');
    Route::get('requests', [WorkController::class, 'requests'])->name('requests');
    Route::post('followups/{type}/{id}', [WorkController::class, 'followup'])->whereIn('type', ['quote', 'audit'])->whereNumber('id')->name('followups.handle');

    // SEO: Google Search Console
    Route::get('clients/{client}/seo', [SeoController::class, 'index'])->name('clients.seo');
    Route::post('clients/{client}/seo/sync', [SeoController::class, 'sync'])->middleware('throttle:10,1')->name('clients.seo.sync');
    Route::get('tasks', [DashboardController::class, 'tasks'])->name('tasks');

    // Agencje (zleceniodawcy) i strony obsługiwane na ich zlecenie
    Route::resource('agencies', AgencyController::class);
    Route::post('agencies/{agency}/archive', [AgencyController::class, 'archive'])->name('agencies.archive');
    Route::post('agencies/{agency}/attach', [AgencyController::class, 'attach'])->name('agencies.attach');
    Route::delete('agencies/{agency}/clients/{client}', [AgencyController::class, 'detach'])->name('agencies.detach');
    Route::middleware('can:admin')->group(function () {
        Route::get('agencies/{agency}/settlement', [AgencyController::class, 'settlement'])->name('agencies.settlement');
        Route::get('agencies/{agency}/settlement/pdf', [AgencyController::class, 'settlementPdf'])->name('agencies.settlement.pdf');
        Route::post('agencies/{agency}/settlement/invoiced', [AgencyController::class, 'settlementInvoiced'])->name('agencies.settlement.invoiced');
    });

    Route::resource('clients', ClientController::class)->except('show');
    Route::get('clients/{client}', [ClientController::class, 'show'])->name('clients.show');
    Route::patch('clients/{client}/notes', [ClientController::class, 'updateNotes'])->name('clients.notes');
    Route::get('clients/{client}/ads', [ClientController::class, 'ads'])->name('clients.ads');
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
    Route::post('clients/{client}/work-reports', [ReportController::class, 'storeWork'])->name('clients.work-reports.store');
    Route::put('reports/{report}/work', [ReportController::class, 'updateWork'])->name('reports.work.update');
    Route::post('reports/{report}/work/refresh', [ReportController::class, 'refreshWork'])->name('reports.work.refresh');
    Route::get('reports/{report}', [ReportController::class, 'show'])->name('reports.show');
    Route::patch('reports/{report}/note', [ReportController::class, 'updateNote'])->name('reports.note');
    Route::post('reports/{report}/send', [ReportController::class, 'send'])->name('reports.send');
    Route::delete('reports/{report}', [ReportController::class, 'destroy'])->name('reports.destroy');

    // Opieka nad stroną: plan cykliczny + zadania na miesiąc
    Route::get('care', [CareController::class, 'overview'])->name('care');
    Route::get('clients/{client}/care', [CareController::class, 'index'])->name('clients.care');
    Route::post('clients/{client}/care/tasks', [CareController::class, 'storeTask'])->name('care.tasks.store');
    Route::patch('care/tasks/{task}', [CareController::class, 'updateTask'])->name('care.tasks.update');
    Route::delete('care/tasks/{task}', [CareController::class, 'destroyTask'])->name('care.tasks.destroy');
    Route::post('clients/{client}/care/templates', [CareController::class, 'storeTemplate'])->name('care.templates.store');
    Route::post('clients/{client}/care/preset', [CareController::class, 'applyPreset'])->name('care.preset');
    Route::patch('care/templates/{template}', [CareController::class, 'updateTemplate'])->name('care.templates.update');
    Route::delete('care/templates/{template}', [CareController::class, 'destroyTemplate'])->name('care.templates.destroy');

    // Oferty i rozliczenia – tylko administratorzy (ceny, przychody)
    Route::middleware('can:admin')->group(function () {
        Route::get('quotes', [QuoteController::class, 'index'])->name('quotes.index');
        Route::post('quotes', [QuoteController::class, 'store'])->name('quotes.store');
        Route::get('quotes/{quote}', [QuoteController::class, 'edit'])->name('quotes.edit');
        Route::put('quotes/{quote}', [QuoteController::class, 'update'])->name('quotes.update');
        Route::post('quotes/{quote}/send', [QuoteController::class, 'send'])->middleware('throttle:10,1')->name('quotes.send');
        Route::post('quotes/{quote}/mark-sent', [QuoteController::class, 'markSent'])->name('quotes.mark-sent');
        Route::post('quotes/{quote}/duplicate', [QuoteController::class, 'duplicate'])->name('quotes.duplicate');
        Route::delete('quotes/{quote}', [QuoteController::class, 'destroy'])->name('quotes.destroy');

        Route::get('billing', [BillingController::class, 'index'])->name('billing.index');
        // Finanse: rentowność i faktury (Fakturownia)
        Route::get('profitability', [FinanceController::class, 'profitability'])->name('profitability');
        Route::get('invoices', [FinanceController::class, 'invoices'])->name('invoices.index');
        Route::get('invoices/draft', [FinanceController::class, 'draft'])->name('invoices.draft');
        Route::post('invoices', [FinanceController::class, 'store'])->middleware('throttle:20,1')->name('invoices.store');
        Route::post('invoices/{invoice}/send', [FinanceController::class, 'send'])->middleware('throttle:20,1')->name('invoices.send');
        Route::post('invoices/{invoice}/paid', [FinanceController::class, 'paid'])->name('invoices.paid');
        Route::post('invoices/test', [FinanceController::class, 'testFakturownia'])->middleware('throttle:10,1')->name('invoices.test');
        Route::get('clients/{client}/billing', [BillingController::class, 'client'])->name('clients.billing');
        Route::post('clients/{client}/billing', [BillingController::class, 'store'])->name('billing.store');
        Route::put('billing/{item}', [BillingController::class, 'update'])->name('billing.update');
        Route::delete('billing/{item}', [BillingController::class, 'destroy'])->name('billing.destroy');
        Route::post('billing/{item}/advance', [BillingController::class, 'advance'])->name('billing.advance');
        Route::post('clients/{client}/billing/extra-work-invoiced', [BillingController::class, 'invoiceExtraWork'])->name('billing.extra-work');
        Route::post('billing/{item}/lookup', [BillingController::class, 'lookup'])->middleware('throttle:20,1')->name('billing.lookup');
    });

    // Audyt dowolnej strony (potencjalni klienci)
    Route::get('audits', [WebAuditController::class, 'index'])->name('audits.index');
    Route::post('audits', [WebAuditController::class, 'store'])->middleware('throttle:20,1')->name('audits.store');
    Route::get('audits/{audit}', [WebAuditController::class, 'show'])->name('audits.show');
    Route::post('audits/{audit}/rerun', [WebAuditController::class, 'rerun'])->middleware('throttle:20,1')->name('audits.rerun');
    Route::post('audits/{audit}/convert', [WebAuditController::class, 'convert'])->name('audits.convert');
    Route::delete('audits/{audit}', [WebAuditController::class, 'destroy'])->name('audits.destroy');

    // Strona: dostępność, SSL, audyt SEO/techniczny
    Route::get('clients/{client}/site', [SiteController::class, 'index'])->name('clients.site');
    Route::get('clients/{client}/llms-txt', [SiteController::class, 'llmsTxt'])->name('clients.llms-txt');
    Route::post('clients/{client}/site/audit', [SiteController::class, 'runAudit'])->middleware('throttle:10,1')->name('clients.site.audit');
    Route::post('clients/{client}/site/monitor', [SiteController::class, 'updateMonitor'])->name('clients.site.monitor');
    Route::post('clients/{client}/site/check', [SiteController::class, 'checkNow'])->middleware('throttle:10,1')->name('clients.site.check');

    // WordPress (wtyczka CC Connector)
    Route::get('clients/{client}/wordpress', [WordPressController::class, 'index'])->name('clients.wordpress');
    Route::post('clients/{client}/wordpress/pull', [WordPressController::class, 'pull'])->name('clients.wordpress.pull');
    Route::post('clients/{client}/wordpress/ack-all', [WordPressController::class, 'acknowledgeAll'])->name('clients.wordpress.ack-all');
    Route::post('wp-events/{event}/ack', [WordPressController::class, 'acknowledge'])->name('wp-events.ack');
    Route::middleware('can:admin')->group(function () {
        Route::post('clients/{client}/wordpress/connect', [WordPressController::class, 'connect'])->name('clients.wordpress.connect');
        Route::post('clients/{client}/wordpress/regenerate', [WordPressController::class, 'regenerate'])->middleware('password.confirm')->name('clients.wordpress.regenerate');
        Route::delete('clients/{client}/wordpress', [WordPressController::class, 'destroy'])->name('clients.wordpress.destroy');
        Route::get('clients/{client}/wordpress/download', [WordPressController::class, 'download'])->middleware('password.confirm')->name('clients.wordpress.download');
    });

    // Sejf dostępów – tylko administratorzy; podgląd wymaga ponownego podania hasła (ważne 3 h)
    Route::middleware('can:admin')->group(function () {
        Route::post('vault/unlock', [VaultController::class, 'unlock'])->middleware('throttle:20,1')->name('vault.unlock');
        Route::post('vault/lock', [VaultController::class, 'lock'])->name('vault.lock');
        Route::get('clients/{client}/credentials', [CredentialController::class, 'index'])->middleware('password.confirm')->name('clients.credentials');
        Route::post('credentials/{credential}/reveal', [CredentialController::class, 'reveal'])->middleware('vault.unlocked')->name('credentials.reveal');
        Route::post('clients/{client}/credentials', [CredentialController::class, 'store'])->name('credentials.store');
        Route::put('credentials/{credential}', [CredentialController::class, 'update'])->name('credentials.update');
        Route::delete('credentials/{credential}', [CredentialController::class, 'destroy'])->name('credentials.destroy');
    });

    Route::patch('recommendations/{recommendation}', [RecommendationController::class, 'update'])->name('recommendations.update');

    // Panel administratora
    Route::middleware('can:admin')->prefix('admin')->name('admin.')->group(function () {
        Route::get('status', [AdminController::class, 'status'])->name('status');
        Route::post('status/test-mail', [AdminController::class, 'testMail'])->name('status.mail');
        Route::post('status/test-ai', [AdminController::class, 'testAi'])->name('status.ai');
        Route::get('settings', [AdminController::class, 'settings'])->name('settings');
        Route::post('settings', [AdminController::class, 'updateSettings'])->name('settings.update');
        Route::get('team', [AdminController::class, 'team'])->name('team');
        Route::post('team', [AdminController::class, 'storeUser'])->middleware('password.confirm')->name('team.store');
        Route::post('team/{user}/invite', [AdminController::class, 'resendInvite'])->name('team.invite');
        Route::patch('team/{user}', [AdminController::class, 'updateUser'])->name('team.update');
        Route::delete('team/{user}', [AdminController::class, 'destroyUser'])->middleware('password.confirm')->name('team.destroy');
    });

    // OAuth
    Route::get('oauth/google/{client}', [IntegrationController::class, 'googleRedirect'])->name('oauth.google');
    Route::get('oauth/google-callback', [IntegrationController::class, 'googleCallback'])->name('oauth.google.callback');
    Route::get('oauth/meta/{client}', [IntegrationController::class, 'metaRedirect'])->name('oauth.meta');
    Route::get('oauth/meta-callback', [IntegrationController::class, 'metaCallback'])->name('oauth.meta.callback');
});

require __DIR__.'/settings.php';
require __DIR__.'/auth.php';
