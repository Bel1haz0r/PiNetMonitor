<?php

use App\Http\Controllers\ApiController;
use Illuminate\Support\Facades\Route;

Route::controller(ApiController::class)->group(function () {
    Route::get('status', 'status');
    Route::get('sessions', 'sessions');
    Route::post('sessions', 'newSession');
    Route::get('sessions/{session}', 'session');
    Route::get('sessions/{session}/incidents', 'incidents');
    Route::get('sessions/{session}/gaps', 'gaps');
    Route::get('sessions/{session}/series', 'series');
    Route::get('sessions/{session}/verify', 'verify');
    Route::get('sessions/{session}/export/{what}', 'export');
    Route::get('settings', 'settings');
    Route::put('settings', 'saveSettings');
    Route::get('speed', 'speedTests');
    Route::post('speed/run', 'runSpeed');
    Route::get('speed/tool', 'speedTool');
    Route::get('case', 'case');
    Route::put('case', 'saveCase');
    Route::post('case/new', 'newCase');
    Route::post('case/journal', 'addJournal');
    Route::get('case/letter/{kind}', 'letter');
});
