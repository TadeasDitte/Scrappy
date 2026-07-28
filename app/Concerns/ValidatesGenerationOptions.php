<?php

namespace App\Concerns;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Support\Arr;

trait ValidatesGenerationOptions
{
    /**
     * The Ollama sampling options callers may set, with the range we accept.
     *
     * @var array<string, array<int, string>>
     */
    protected static array $generationOptions = [
        'temperature' => ['numeric', 'between:0,2'],
        'top_p' => ['numeric', 'between:0,1'],
        'top_k' => ['integer', 'between:1,200'],
        'min_p' => ['numeric', 'between:0,1'],
        'repeat_penalty' => ['numeric', 'between:0,2'],
        'repeat_last_n' => ['integer', 'between:-1,2048'],
        'presence_penalty' => ['numeric', 'between:-2,2'],
        'frequency_penalty' => ['numeric', 'between:-2,2'],
        'num_predict' => ['integer', 'between:-1,8192'],
        'num_ctx' => ['integer', 'between:128,131072'],
        'seed' => ['integer'],
    ];

    /**
     * The accepted options and the constraint applied to each, for the docs.
     *
     * @return array<string, string>
     */
    public static function documentedOptions(): array
    {
        return [
            ...array_map(
                fn (array $rules): string => implode('|', $rules),
                static::$generationOptions,
            ),
            'stop' => 'array|max:4|each string, max:64 chars',
        ];
    }

    /**
     * Validation rules for the generation options shared by every chat route.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    protected function generationOptionRules(): array
    {
        $rules = [
            'system' => ['sometimes', 'nullable', 'string', 'max:4000'],
            'stream' => ['sometimes', 'boolean'],
            'options' => ['sometimes', 'array', $this->rejectsUnknownOptions()],
            'options.stop' => ['sometimes', 'array', 'max:4'],
            'options.stop.*' => ['string', 'min:1', 'max:64'],
        ];

        foreach (static::$generationOptions as $option => $optionRules) {
            $rules["options.{$option}"] = array_merge(['sometimes'], $optionRules);
        }

        return $rules;
    }

    /**
     * The validated options, limited to the keys Ollama actually understands.
     *
     * Numbers are cast to their declared type so a form-encoded `"0.2"` still
     * reaches Ollama as a JSON number.
     *
     * @return array<string, mixed>
     */
    public function generationOptions(): array
    {
        /** @var array<string, mixed> $options */
        $options = $this->validated('options', []);

        $options = Arr::only($options, $this->supportedOptions());

        foreach (static::$generationOptions as $option => $rules) {
            if (! array_key_exists($option, $options)) {
                continue;
            }

            $options[$option] = in_array('integer', $rules, true)
                ? (int) $options[$option]
                : (float) $options[$option];
        }

        return $options;
    }

    /**
     * Fail validation when an option we do not forward is supplied.
     */
    protected function rejectsUnknownOptions(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            if (! is_array($value)) {
                return;
            }

            $unknown = array_diff(array_keys($value), $this->supportedOptions());

            if ($unknown !== []) {
                $fail(sprintf(
                    'Unsupported generation option(s): %s. Supported: %s.',
                    implode(', ', $unknown),
                    implode(', ', $this->supportedOptions()),
                ));
            }
        };
    }

    /**
     * Every option key the API accepts, sampling parameters plus `stop`.
     *
     * @return array<int, string>
     */
    protected function supportedOptions(): array
    {
        return [...array_keys(static::$generationOptions), 'stop'];
    }
}
