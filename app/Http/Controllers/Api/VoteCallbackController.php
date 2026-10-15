<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Services\Vote\VoteService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The endpoint a listing site calls when somebody has voted.
 *
 * The only unauthenticated write in this panel, because it has to be: the
 * caller is Gtop100's server, which has no session and never will. What
 * stands in place of one is the per-site secret, the single-use reference and
 * the cooldown -- see VoteService, which holds all three.
 *
 * ---------------------------------------------------------------------------
 * Why the parameters are read so loosely
 * ---------------------------------------------------------------------------
 *
 * Every listing site invented its own names for the same two values, and some
 * of them changed their minds between versions of their own control panel. A
 * reference arrives as `Custom` from Gtop100 and `custom` from XtremeTop100;
 * the secret arrives in the query string from one and in the body from
 * another. Insisting on one spelling would mean an operator whose votes
 * silently never pay, and no way to find out why.
 *
 * So the names are an allow-list rather than a single key, and nothing is
 * decided by which name it came under -- the value still has to be a secret
 * that matches and a reference that exists.
 */
final class VoteCallbackController
{
    /** What the reference might be called on its way back. */
    private const REFERENCES = ['reference', 'custom', 'Custom', 'voter_id', 'customvalue'];

    /** And the secret. */
    private const SECRETS = ['token', 'secret', 'key', 'postback_key'];

    public function __construct(private readonly VoteService $votes) {}

    public function __invoke(Request $request, string $site): JsonResponse
    {
        abort_unless($this->votes->isEnabled(), 404);

        $paid = $this->votes->claim(
            $site,
            $this->first($request, self::REFERENCES),
            $this->first($request, self::SECRETS),
            $request->ip(),
        );

        /*
         * 200 either way, and the same body shape.
         *
         * Listing sites treat a non-200 as a failure to be retried, often for
         * days, and a vote that was refused for a good reason -- a replayed
         * reference, a cooldown -- is not going to start succeeding. The
         * `recorded` flag is there for an operator reading their site's
         * delivery log; it tells a stranger nothing they could not learn by
         * trying twice.
         */
        return response()->json(['recorded' => $paid]);
    }

    /**
     * The first of these parameter names that was sent, as a string.
     *
     * @param  list<string>  $names
     */
    private function first(Request $request, array $names): string
    {
        foreach ($names as $name) {
            $value = $request->input($name);

            if (is_string($value) && $value !== '') {
                return $value;
            }
        }

        return '';
    }
}
