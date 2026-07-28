<?php

namespace App\Http\Requests;

use App\Concerns\ResolvesChatTarget;
use App\Concerns\ValidatesGenerationOptions;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ChatMessagesRequest extends FormRequest
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
            'messages' => ['required', 'array', 'min:1', 'max:100'],
            'messages.*.role' => ['required', Rule::in(['system', 'user', 'assistant'])],
            'messages.*.content' => ['required', 'string', 'max:8000'],
            ...$this->generationOptionRules(),
        ];
    }

    /**
     * The conversation to send, with the optional `system` prompt prepended.
     *
     * Deliberately not named `messages()`: that is FormRequest's own hook for
     * custom validation messages.
     *
     * @return array<int, array{role: string, content: string}>
     */
    public function conversation(): array
    {
        /** @var array<int, array{role: string, content: string}> $messages */
        $messages = $this->validated('messages');

        $system = $this->validated('system');

        if (is_string($system) && $system !== '') {
            array_unshift($messages, ['role' => 'system', 'content' => $system]);
        }

        return $messages;
    }
}
