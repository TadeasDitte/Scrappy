<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Database\Factories\ConversationFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * @property int $id
 * @property int $user_id
 * @property string $title
 * @property int|null $domain_id
 * @property string|null $model
 * @property CarbonImmutable|null $last_message_at
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Fillable([
    'user_id',
    'title',
    'domain_id',
    'model',
    'last_message_at',
])]
class Conversation extends Model
{
    /** @use HasFactory<ConversationFactory> */
    use HasFactory;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'last_message_at' => 'datetime',
        ];
    }

    /**
     * The user the conversation belongs to.
     *
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * The endpoint the conversation was last pointed at, if it still exists.
     *
     * @return BelongsTo<Domain, $this>
     */
    public function domain(): BelongsTo
    {
        return $this->belongsTo(Domain::class);
    }

    /**
     * The turns in the conversation, oldest first.
     *
     * @return HasMany<ConversationMessage, $this>
     */
    public function messages(): HasMany
    {
        return $this->hasMany(ConversationMessage::class)->orderBy('id');
    }

    /**
     * Most recently used conversations first.
     *
     * @param  Builder<Conversation>  $query
     */
    public function scopeRecent(Builder $query): void
    {
        $query->orderByRaw('last_message_at is null, last_message_at desc')
            ->orderByDesc('id');
    }

    /**
     * Derive a readable title from the first thing the user asked.
     */
    public static function titleFromPrompt(string $prompt): string
    {
        $title = trim((string) preg_replace('/\s+/', ' ', $prompt));

        return $title === '' ? 'New chat' : Str::limit($title, 60, '…');
    }
}
