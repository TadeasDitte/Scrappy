<?php

namespace App\Http\Requests;

use App\Concerns\ResolvesChatTarget;
use App\Concerns\ValidatesGenerationOptions;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class ChatGenerateRequest extends FormRequest
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
            ...$this->generationOptionRules(),
        ];
    }
}
