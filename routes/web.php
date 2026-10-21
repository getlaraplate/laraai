<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Modules\AI\Http\Controllers\AgentController;
use Modules\AI\Http\Controllers\AssistantWriteController;
use Modules\AI\Http\Controllers\CapabilitiesController;
use Modules\AI\Http\Controllers\ChatController;
use Modules\AI\Http\Controllers\SuggestionController;
use Modules\AI\Http\Middleware\EnsureAssistantEnabled;
use Modules\AI\Http\Middleware\ResolveAssistantApplicationContext;

Route::get('ai/capabilities', [CapabilitiesController::class, 'show'])->middleware('auth')->name('capabilities');
// Authenticated by the controller, like the message routes: an `auth` redirect to a login page cannot serve a stream client.
Route::post('ai/agent', [AgentController::class, 'run'])->middleware([EnsureAssistantEnabled::class, ResolveAssistantApplicationContext::class])->name('agent');

Route::prefix('crud')->name('crud.')->group(function (): void {
    // Chat routes: every one answers 403 FEATURE_DISABLED while the assistant is off
    Route::controller(ChatController::class)->middleware(EnsureAssistantEnabled::class)->group(function (): void {
        Route::get('select/ai/conversations', 'listConversations')->name('conversations.list');
        Route::post('insert/ai/conversations', 'insertConversation')->name('conversations.insert');
        Route::get('detail/ai/conversations/{conversation}', 'detailConversation')->name('conversations.detail');
        Route::delete('delete/ai/conversations/{conversation}', 'deleteConversation')->name('conversations.delete');
        Route::get('select/ai/conversations/{conversation}/messages', 'listMessages')->name('messages.list');
        Route::post('stream/ai/conversations/{conversation}/messages', 'streamMessage')->middleware(ResolveAssistantApplicationContext::class)->name('messages.stream');
        Route::post('insert/ai/conversations/{conversation}/messages', 'insertMessage')->middleware(ResolveAssistantApplicationContext::class)->name('messages.insert');
    });

    // The person decides on what the assistant proposed: outside the model, as the authenticated user
    Route::controller(AssistantWriteController::class)->middleware(EnsureAssistantEnabled::class)->group(function (): void {
        Route::get('detail/ai/assistant-writes/{proposal}', 'show')->name('assistant-writes.show');
        Route::post('update/ai/assistant-writes/{proposal}/confirm', 'confirm')->name('assistant-writes.confirm');
        Route::post('update/ai/assistant-writes/{proposal}/reject', 'reject')->name('assistant-writes.reject');
    });

    // Contextual suggestions routes
    Route::controller(SuggestionController::class)->group(function (): void {
        Route::get('select/ai/suggestions', 'listSuggestions')->name('suggestions.list');
        Route::post('insert/ai/suggestions', 'generateSuggestion')->name('suggestions.generate');
        Route::post('update/ai/suggestions/{suggestion}/dismiss', 'dismissSuggestion')->name('suggestions.dismiss');
    });
});
