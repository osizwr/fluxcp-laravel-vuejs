<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Vote for points
|--------------------------------------------------------------------------
|
| Players vote for the server on a listing site; the site tells this panel
| they did; the panel adds credits to their account. It is how a small server
| climbs a ranking list, and it is the one feature players will use every day
| whether or not anybody designed it well.
|
| ---------------------------------------------------------------------------
| How a vote actually travels
| ---------------------------------------------------------------------------
|
|   1. The player presses Vote. The panel writes a pending row -- who, which
|      site, a single-use reference -- and sends them to the site's URL with
|      that reference embedded in whatever field the site calls "custom".
|
|   2. They vote. The site calls this panel back on
|      /api/vote/{site}/callback, quoting the reference and the shared secret
|      below.
|
|   3. The panel checks the secret, finds the pending row, checks that the
|      cooldown has passed, and adds the credits.
|
| The reference is the part worth understanding. It is what makes a callback
| provable: without it the panel would be awarding credits to whichever
| account last pressed the button from that IP address, which is a system that
| pays the wrong person every time two players vote at once -- and pays
| anybody who can guess a username, since the only other thing a listing site
| sends is a name.
|
| ---------------------------------------------------------------------------
| Wiring up a listing site
| ---------------------------------------------------------------------------
|
| Every site worth using has an "incentive", "postback" or "callback" setting.
| Two things go into it:
|
|   the callback URL   https://your-panel.example/api/vote/gtop100/callback
|                      Some sites take the secret as a query parameter, some
|                      send it in the body -- both are read; see below.
|
|   the custom field   The reference, which this panel substitutes into the
|                      site's URL wherever you write {reference}.
|
| For the three most common:
|
|   Gtop100        Set "Incentive URL" to the callback URL. Put
|                  &Custom={reference} on the vote URL. Gtop100 posts back
|                  `Successful`, `pingUsername` and `Custom`.
|
|   XtremeTop100   Set the postback URL; it sends `custom` straight back.
|
|   TopG           Set the callback URL; the custom value comes back as
|                  `custom` or `voter_id` depending on their panel version.
|
| If a site cannot send a custom value back, it cannot be made to pay the
| right player, and it belongs in the navigation as an ordinary link rather
| than in here.
|
| ---------------------------------------------------------------------------
| The secret is the whole of the security
| ---------------------------------------------------------------------------
|
| A callback endpoint has to be reachable by a stranger's server, so it cannot
| be behind a session. What stands in the way of anyone calling it in a loop
| is the per-site secret, compared in constant time, plus the reference being
| single-use and short-lived. Generate one per site with
| `php artisan key:generate --show` or any random string of that sort, and
| keep them in .env rather than here.
|
*/

return [

    /*
    |--------------------------------------------------------------------------
    | Whether voting is offered at all
    |--------------------------------------------------------------------------
    |
    | On, and the only switch that decides whether the account menu offers Vote
    | for points. Deliberately not conditional on a site being configured as
    | well: the two are set up in that order, and a feature that stayed hidden
    | until the last field was filled in would leave an operator with no way to
    | see what they were setting up. The page says plainly when no site has
    | been registered yet.
    |
    | An operator who does not want to offer voting at all sets this to false,
    | and the entry and the page both go away.
    |
    */

    'enabled' => (bool) env('VOTE_ENABLED', true),

    /*
    |--------------------------------------------------------------------------
    | How long between votes, in hours
    |--------------------------------------------------------------------------
    |
    | The default for a site that does not set its own. Twelve is what most
    | listing sites allow, and a panel that paid out faster than the site
    | counts votes would be paying for nothing.
    |
    */

    'cooldown_hours' => (int) env('VOTE_COOLDOWN_HOURS', 12),

    /*
    |--------------------------------------------------------------------------
    | How long a started vote waits to be claimed, in minutes
    |--------------------------------------------------------------------------
    |
    | A reference that is never used expires. Long enough for somebody to read
    | a captcha and get distracted, short enough that a reference leaked in a
    | referrer header or a browser history is not worth collecting.
    |
    */

    'reference_minutes' => (int) env('VOTE_REFERENCE_MINUTES', 60),

    /*
    |--------------------------------------------------------------------------
    | The sites
    |--------------------------------------------------------------------------
    |
    | Keyed by the name that appears in the callback URL, so it may contain
    | lower-case letters, digits and hyphens and nothing else.
    |
    |   name        What the player sees.
    |   url         Where Vote sends them. {reference} is replaced with the
    |               single-use reference; anything else is left alone.
    |   secret      The shared secret the site quotes back. A site with no
    |               secret set is not offered, because it could not be told
    |               apart from a stranger.
    |   credits     What a confirmed vote is worth.
    |   cooldown    Hours, when this site differs from the default above.
    |   note        An optional line under the name: "once per IP", say.
    |
    | Two examples, left commented out because an operator's own IDs go in
    | them and a half-filled one would appear on the page as a dead link.
    |
    |   'gtop100' => [
    |       'name' => 'Gtop100',
    |       'url' => 'https://gtop100.com/topsites/Ragnarok/sitedetails/Your-Server-99999?vote=1&Custom={reference}',
    |       'secret' => env('VOTE_GTOP100_SECRET'),
    |       'credits' => 5,
    |   ],
    |
    |   'xtremetop100' => [
    |       'name' => 'XtremeTop100',
    |       'url' => 'https://www.xtremetop100.com/in.php?site=0123456789&custom={reference}',
    |       'secret' => env('VOTE_XTREMETOP100_SECRET'),
    |       'credits' => 5,
    |       'cooldown' => 24,
    |   ],
    |
    */

    'sites' => [
        //
    ],

];
