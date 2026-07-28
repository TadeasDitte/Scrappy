<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class ApiTokenController extends Controller
{
    /**
     * The abilities that may be granted to a token.
     *
     * @var array<int, string>
     */
    protected array $abilities = ['domains:read', 'chat:generate'];

    /**
     * Show the API token management page.
     */
    public function index(Request $request): Response
    {
        return Inertia::render('settings/ApiTokens', [
            'availableAbilities' => $this->abilities,
            'tokens' => $request->user()->tokens()
                ->latest()
                ->get()
                ->map(fn ($token): array => [
                    'id' => $token->id,
                    'name' => $token->name,
                    'abilities' => $token->abilities,
                    'last_used_at' => $token->last_used_at?->diffForHumans(),
                    'created_at' => $token->created_at?->toDayDateTimeString(),
                ])
                ->all(),
        ]);
    }

    /**
     * Create a new personal access token and flash its plaintext value once.
     */
    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'abilities' => ['array'],
            'abilities.*' => [Rule::in($this->abilities)],
        ]);

        $token = $request->user()->createToken(
            $validated['name'],
            $validated['abilities'] ?? $this->abilities,
        );

        // The plaintext token is only ever available at creation time.
        Inertia::flash('token', [
            'name' => $validated['name'],
            'plainText' => $token->plainTextToken,
        ]);

        return back();
    }

    /**
     * Revoke a token belonging to the authenticated user.
     */
    public function destroy(Request $request, string $tokenId): RedirectResponse
    {
        $request->user()->tokens()->whereKey($tokenId)->delete();

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Token revoked.')]);

        return back();
    }
}
