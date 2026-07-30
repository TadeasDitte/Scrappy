<?php

use App\Http\Controllers\ChatController;
use App\Http\Controllers\ConversationController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\DomainController;
use App\Http\Controllers\RefreshController;
use App\Http\Controllers\WelcomeController;
use Illuminate\Support\Facades\Route;

Route::get('/', WelcomeController::class)->name('home');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('dashboard', DashboardController::class)->name('dashboard');

    Route::get('domains', [DomainController::class, 'index'])->name('domains.index');
    Route::post('domains/refresh', [RefreshController::class, 'store'])->name('domains.refresh');

    Route::get('chat', [ChatController::class, 'index'])->name('chat.index');
    Route::post('chat/stream', [ChatController::class, 'stream'])->name('chat.stream');

    Route::patch('chat/conversations/{conversation}', [ConversationController::class, 'update'])->name('conversations.update');
    Route::delete('chat/conversations/{conversation}', [ConversationController::class, 'destroy'])->name('conversations.destroy');
    Route::delete('chat/conversations', [ConversationController::class, 'clear'])->name('conversations.clear');
});

require __DIR__.'/settings.php';
