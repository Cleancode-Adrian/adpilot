<?php

use App\Http\Controllers\WordPressController;
use Illuminate\Support\Facades\Route;

// Dane z wtyczki CC Connector (podpis HMAC weryfikowany w kontrolerze)
Route::post('wp/push', [WordPressController::class, 'push'])->middleware('throttle:120,1')->name('api.wp.push');
