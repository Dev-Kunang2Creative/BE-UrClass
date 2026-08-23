<?php

use Illuminate\Support\Facades\Route;

// Route::view() instead of a closure so `php artisan route:cache` can
// serialize this route during deploys (closures are not serializable).
Route::view('/', 'welcome');
