<?php

namespace App\Http\Requests;

use App\Concerns\ResolvesChatTarget;
use App\Concerns\ValidatesGenerationOptions;
use App\Models\Conversation;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ChatStreamRequest extends FormRequest
{
    use ResolvesChatTarget;
    use ValidatesGenerationOptions;

    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            ...$this->chatTargetRules(),
            'prompt' => ['required', 'string', 'max:8000'],
            'conversation_id' => [
                'sometimes',
                'nullable',
                Rule::exists('conversations', 'id')->where('user_id', $this->user()?->id),
            ],
            'temporary' => ['sometimes', 'boolean'],
            // Prior turns, sent by the client so a temporary chat still has
            // context. Saved conversations read their history from the database.
            'history' => ['sometimes', 'array', 'max:100'],
            'history.*.role' => ['required', Rule::in(['user', 'assistant'])],
            // Far looser than `prompt`: this replays turns the model already
            // produced, and a single long answer must not wedge the thread by
            // failing validation on every subsequent message.
            'history.*.content' => ['required', 'string', 'max:100000'],
            ...$this->generationOptionRules(),
        ];
    }

    /**
     * Whether this exchange should be left out of the user's history.
     */
    public function isTemporary(): bool
    {
        return $this->boolean('temporary');
    }

    /**
     * The prompt being sent.
     */
    public function prompt(): string
    {
        return $this->string('prompt')->value();
    }

    /**
     * The conversation being continued, if the client named one it owns.
     */
    public function conversation(): ?Conversation
    {
        $id = $this->integer('conversation_id');

        if ($id === 0 || $this->isTemporary()) {
            return null;
        }

        return $this->user()?->conversations()->find($id);
    }

    /**
     * The client-side transcript, used only for temporary chats.
     *
     * @return array<int, array{role: string, content: string}>
     */
    public function history(): array
    {
        /** @var array<int, array{role: string, content: string}> $history */
        $history = $this->validated('history', []);

        return $history;
    }
}
