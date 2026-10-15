<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Models\Account;
use App\Services\Vote\VoteService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Voting for the server, from the player's side.
 *
 * Two actions: what the sites are and whether this account can vote on them
 * yet, and the one that mints a reference and says where to go. The callback
 * that pays for it is somewhere else entirely -- see VoteCallbackController,
 * which a stranger's server has to be able to reach.
 */
final class VoteController
{
    public function __construct(private readonly VoteService $votes) {}

    /**
     * The sites, with this account's cooldowns and its balance.
     *
     * The balance is here because the page is about earning credits and the
     * number going up is the whole reward: a player who has to navigate
     * somewhere else to find out whether it worked will assume it did not.
     *
     * An empty list is an ordinary answer, not a 404. Voting can be switched
     * on before the first listing site has been registered -- that is the
     * order an operator does it in -- and the page says so rather than
     * pretending not to exist.
     */
    public function index(Request $request): JsonResponse
    {
        abort_unless($this->votes->isEnabled(), 404);

        $account = $this->account($request);

        return response()->json([
            'data' => [
                'sites' => $this->votes->statusFor($account),
                'credits' => $this->votes->balanceFor($account),
            ],
        ]);
    }

    /**
     * Start a vote, and answer with where to send the player.
     *
     * The panel does not redirect: it hands back the URL and the page opens
     * it. A redirect would mean the browser leaves the panel on a request
     * that writes a row, so a refresh of that address would mint a second
     * reference -- and the back button would land on a page that silently
     * voted again.
     */
    public function start(Request $request, string $site): JsonResponse
    {
        abort_unless($this->votes->isEnabled(), 404);

        $started = $this->votes->start(
            $this->account($request),
            $site,
            $request->ip(),
        );

        /*
         * One refusal for both of the things that can be wrong: no such site,
         * and not yet. The page has just been told the cooldowns, so it knows
         * which it is; and anybody who has not been told is somebody this
         * endpoint owes no explanation.
         */
        abort_if($started === null, 422, 'That vote cannot be started yet.');

        return response()->json(['data' => ['url' => $started['url']]]);
    }

    private function account(Request $request): Account
    {
        $account = $request->user();

        abort_unless($account instanceof Account, 401);

        return $account;
    }
}
