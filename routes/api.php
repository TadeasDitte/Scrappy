<?php

use App\Http\Controllers\Api\ChatController;
use App\Http\Controllers\Api\DomainController;
use App\Http\Controllers\Api\IndexController;
use App\Http\Controllers\Api\ModelController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware('auth:sanctum');

// The self-describing entry point: what exists, and what each endpoint takes.
Route::get('v1', IndexController::class)->name('api.v1.index');

Route::middleware('auth:sanctum')->prefix('v1')->name('api.v1.')->group(function () {
    Route::middleware('abilities:domains:read')->group(function () {
        Route::get('domains', [DomainController::class, 'index'])->name('domains.index');
        Route::get('domains/{domain}', [DomainController::class, 'show'])->name('domains.show');
        Route::get('domains/{domain}/models', [ModelController::class, 'forDomain'])->name('domains.models.index');
        Route::get('models', [ModelController::class, 'index'])->name('models.index');
    });

    Route::middleware('abilities:chat:generate')->group(function () {
        Route::post('chat/generate', [ChatController::class, 'generate'])->name('chat.generate');
        Route::post('chat', [ChatController::class, 'chat'])->name('chat.messages');
    });
});
