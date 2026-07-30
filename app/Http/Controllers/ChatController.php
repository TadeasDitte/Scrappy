<?php

namespace App\Http\Controllers;

use App\Http\Requests\ChatStreamRequest;
use App\Models\Conversation;
use App\Models\ConversationMessage;
use App\Models\Domain;
use App\Models\OllamaModel;
use App\Services\OllamaClient;
use Generator;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ChatController extends Controller
{
    /**
     * Show the chat UI: the ranked active domains, the user's saved
     * conversations, and the transcript of whichever one is open.
     */
    public function index(Request $request): Response
    {
        $conversation = $this->openConversation($request);

        return Inertia::render('Chat', [
            'domains' => $this->activeDomains(),
            'selectedDomainId' => $request->integer('domain') ?: null,
            'conversations' => $this->conversationList($request),
            'activeConversation' => $conversation === null ? null : [
                'id' => $conversation->id,
                'title' => $conversation->title,
                'domain_id' => $conversation->domain_id,
                'model' => $conversation->model,
                'messages' => $conversation->messages
                    ->map(fn (ConversationMessage $message): array => [
                        'role' => $message->role,
                        'content' => $message->content,
                        'latency_ms' => $message->latency_ms,
                    ])
                    ->all(),
            ],
        ]);
    }

    /**
     * Proxy a prompt to the selected domain's Ollama API, streaming the reply.
     *
     * Saved conversations take their context from the database and record both
     * turns; temporary chats are streamed and then forgotten.
     */
    public function stream(ChatStreamRequest $request, OllamaClient $ollama): StreamedResponse
    {
        $domain = $request->domain();
        $model = $request->model();
        $prompt = $request->prompt();
        $options = $request->generationOptions();
        $system = $request->validated('system');
        $keepAlive = $request->keepAlive();

        $conversation = $request->isTemporary()
            ? null
            : $this->recordPrompt($request, $domain, $model, $prompt);

        $turns = $conversation !== null
            ? $this->storedTurns($conversation)
            : [...$request->history(), ['role' => 'user', 'content' => $prompt]];

        $headers = [
            'Content-Type' => 'text/plain; charset=utf-8',
            'Cache-Control' => 'no-cache',
            'X-Accel-Buffering' => 'no',
        ];

        if ($conversation !== null) {
            // Lets a brand new conversation adopt its id without a round trip.
            // Only the id: a title is user text and headers are latin-1.
            $headers['X-Conversation-Id'] = (string) $conversation->id;
        }

        // Yielding (rather than echoing) makes Laravel flush the output buffer
        // between chunks, so the browser paints tokens as the model emits them.
        return response()->stream(function () use ($ollama, $domain, $model, $prompt, $options, $system, $keepAlive, $turns, $conversation): Generator {
            $reply = '';
            $startedAt = microtime(true);

            try {
                // A first message goes to /api/generate, exactly as before; only
                // an actual conversation needs /api/chat.
                $chunks = count($turns) > 1
                    ? $ollama->chatStream($domain, $model, $this->withSystem($turns, $system), $options, $keepAlive)
                    : $ollama->generateStream($domain, $model, $prompt, $options, $system, $keepAlive);

                foreach ($chunks as $chunk) {
                    $reply .= $chunk;

                    yield $chunk;
                }
            } catch (\Throwable $e) {
                yield "\n[stream error: {$e->getMessage()}]";
            } finally {
                if ($conversation !== null) {
                    $reply === ''
                        ? $this->discardPrompt($conversation)
                        : $this->recordReply($conversation, $reply, $startedAt);
                }
            }
        }, 200, $headers);
    }

    /**
     * Persist the user's turn, creating the conversation on the first message.
     */
    protected function recordPrompt(ChatStreamRequest $request, Domain $domain, string $model, string $prompt): Conversation
    {
        $conversation = $request->conversation() ?? Conversation::create([
            'user_id' => $request->user()->id,
            'title' => Conversation::titleFromPrompt($prompt),
        ]);

        $conversation->messages()->create(['role' => 'user', 'content' => $prompt]);

        $conversation->update([
            'domain_id' => $domain->id,
            'model' => $model,
            'last_message_at' => now(),
        ]);

        return $conversation;
    }

    /**
     * Persist the assistant's turn once the stream has run its course.
     */
    protected function recordReply(Conversation $conversation, string $reply, float $startedAt): void
    {
        $conversation->messages()->create([
            'role' => 'assistant',
            'content' => $reply,
            'latency_ms' => (int) round((microtime(true) - $startedAt) * 1000),
        ]);

        $conversation->update(['last_message_at' => now()]);
    }

    /**
     * Roll back a prompt that produced nothing at all.
     *
     * Leaving it behind would end the transcript on a user turn, which the next
     * message would then replay to the model as two questions in a row — and a
     * conversation created solely for a failed prompt is just noise in the
     * sidebar.
     */
    protected function discardPrompt(Conversation $conversation): void
    {
        // reorder(), not latest(): the relation already sorts ascending, and a
        // second clause would otherwise leave the oldest turn first — deleting
        // the wrong end of the conversation.
        $conversation->messages()->reorder('id', 'desc')->first()?->delete();

        if ($conversation->messages()->doesntExist()) {
            $conversation->delete();
        }
    }

    /**
     * The stored transcript, in the shape Ollama's chat API expects.
     *
     * @return array<int, array{role: string, content: string}>
     */
    protected function storedTurns(Conversation $conversation): array
    {
        return $conversation->messages()
            ->get()
            ->map(fn (ConversationMessage $message): array => [
                'role' => $message->role,
                'content' => $message->content,
            ])
            ->all();
    }

    /**
     * Prepend the system prompt to a set of turns, when one was given.
     *
     * @param  array<int, array{role: string, content: string}>  $turns
     * @return array<int, array{role: string, content: string}>
     */
    protected function withSystem(array $turns, ?string $system): array
    {
        if (! is_string($system) || $system === '') {
            return $turns;
        }

        return [['role' => 'system', 'content' => $system], ...$turns];
    }

    /**
     * The conversation the request asked to open, if the user owns it.
     */
    protected function openConversation(Request $request): ?Conversation
    {
        $id = $request->integer('conversation');

        if ($id === 0) {
            return null;
        }

        return $request->user()->conversations()->with('messages')->find($id);
    }

    /**
     * The user's conversations for the history sidebar, most recent first.
     *
     * @return array<int, array<string, mixed>>
     */
    protected function conversationList(Request $request): array
    {
        return $request->user()->conversations()
            ->recent()
            ->limit(100)
            ->get()
            ->map(fn (Conversation $conversation): array => [
                'id' => $conversation->id,
                'title' => $conversation->title,
                'last_message_at' => $conversation->last_message_at?->diffForHumans(),
            ])
            ->all();
    }

    /**
     * Active domains with the models they serve, fastest first.
     *
     * @return array<int, array<string, mixed>>
     */
    protected function activeDomains(): array
    {
        return Domain::query()
            ->active()
            ->with(['models' => fn ($q) => $q->where('available', true)->orderBy('name')])
            ->rankedBySpeed()
            ->get()
            ->map(fn (Domain $domain): array => [
                'id' => $domain->id,
                'host' => $domain->host,
                'response_time_ms' => $domain->response_time_ms,
                'model_count' => $domain->model_count,
                'models' => $domain->models
                    ->values()
                    ->map(fn (OllamaModel $model): array => [
                        'id' => $model->id,
                        'name' => $model->name,
                        'parameter_size' => $model->parameter_size,
                    ])
                    ->all(),
            ])
            ->all();
    }
}
