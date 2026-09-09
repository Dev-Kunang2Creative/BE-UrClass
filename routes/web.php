<?php

use App\Http\Controllers\Api\AuthController;
use Illuminate\Support\Facades\Route;

// Route::view() instead of a closure so `php artisan route:cache` can
// serialize this route during deploys (closures are not serializable).
Route::view('/', 'welcome');

// Named so route('login') resolves. A controller action rather than a closure
// because deploys run `artisan route:cache`, which cannot serialize closures.
Route::get('/login', [AuthController::class, 'loginNotice'])->name('login');
