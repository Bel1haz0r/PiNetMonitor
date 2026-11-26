<?php

use Illuminate\Support\Facades\Route;

// Single-page React dashboard; every non-API path is handled client-side.
Route::view('/{any?}', 'app')->where('any', '^(?!api|up).*$');
