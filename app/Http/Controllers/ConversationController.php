<?php

namespace App\Http\Controllers;

use App\Models\Conversation;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class ConversationController extends Controller
{
    /**
     * Rename a conversation.
     */
    public function update(Request $request, Conversation $conversation): RedirectResponse
    {
        $this->authorizeOwnership($request, $conversation);

        $validated = $request->validate([
            'title' => ['required', 'string', 'max:120'],
        ]);

        $conversation->update($validated);

        return back();
    }

    /**
     * Delete a conversation and everything said in it.
     */
    public function destroy(Request $request, Conversation $conversation): RedirectResponse
    {
        $this->authorizeOwnership($request, $conversation);

        $conversation->delete();

        return back();
    }

    /**
     * Delete every conversation the user has.
     */
    public function clear(Request $request): RedirectResponse
    {
        $request->user()->conversations()->delete();

        return back();
    }

    /**
     * Someone else's conversation is not theirs to see, let alone edit.
     */
    protected function authorizeOwnership(Request $request, Conversation $conversation): void
    {
        abort_unless($conversation->user_id === $request->user()->id, 404);
    }
}
