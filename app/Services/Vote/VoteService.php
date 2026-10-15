<?php

declare(strict_types=1);

namespace App\Services\Vote;

use App\Models\Account;
use App\Models\VoteLog;
use App\Support\Rathena\ServerRegistry;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\ConnectionResolverInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Voting for the server, and being paid for it.
 *
 * The flow is described at length in config/vote.php; what this class is for
 * is the two decisions in it. Starting a vote mints a single-use reference,
 * and claiming one decides whether a callback that just arrived is owed
 * anything. Everything else -- which sites exist, what they pay -- is
 * configuration.
 *
 * ---------------------------------------------------------------------------
 * Why a callback is believed
 * ---------------------------------------------------------------------------
 *
 * It is believed because of three things together, and no one of them alone:
 *
 *   the secret     proves the caller is the listing site and not a stranger
 *   the reference  proves which player the vote belongs to, which a username
 *                  or an IP address could never prove
 *   the cooldown   decides whether that player is owed anything yet
 *
 * A reference is spent the moment it pays, so the same callback arriving
 * twice -- which listing sites do, on retries -- pays once.
 */
final readonly class VoteService
{
    public function __construct(
        private ConfigRepository $config,
        private ConnectionResolverInterface $connections,
        private ServerRegistry $servers,
    ) {}

    /**
     * Whether the panel offers voting at all.
     *
     * The operator's switch, and nothing else. It deliberately does not also
     * require a configured site: the menu entry and the page are drawn from
     * this, and a feature that hid itself until the last field was filled in
     * would leave an operator setting it up with no way to see what they were
     * setting up. A page with no sites on it says so -- see hasSites().
     */
    public function isEnabled(): bool
    {
        return (bool) $this->config->get('vote.enabled', false);
    }

    /** Whether any configured site can actually pay somebody. */
    public function hasSites(): bool
    {
        return $this->sites() !== [];
    }

    /**
     * The sites that are actually usable, keyed by the name in their URL.
     *
     * A site missing a URL or a secret is dropped rather than drawn greyed
     * out: it cannot pay anybody, and a button that cannot work is worse on
     * the page than absent. Half-configured is the normal state of a site
     * somebody is in the middle of setting up.
     *
     * @return array<string, array{
     *     key: string, name: string, url: string, secret: string,
     *     credits: int, cooldown: int, note: string|null
     * }>
     */
    public function sites(): array
    {
        /** @var array<string, mixed> $configured */
        $configured = (array) $this->config->get('vote.sites', []);

        $sites = [];

        foreach ($configured as $key => $site) {
            if (! is_string($key) || ! is_array($site)) {
                continue;
            }

            $url = is_string($site['url'] ?? null) ? trim($site['url']) : '';
            $secret = is_string($site['secret'] ?? null) ? trim($site['secret']) : '';

            /*
             * The key becomes a URL segment, so it is held to the same shape
             * the route is constrained to. A key the route cannot match would
             * be a site whose callback is a 404 -- discovered, if ever, by an
             * operator wondering why nobody is being paid.
             */
            if ($url === '' || $secret === '' || preg_match('/^[a-z0-9][a-z0-9\-]*$/', $key) !== 1) {
                continue;
            }

            $sites[$key] = [
                'key' => $key,
                'name' => is_string($site['name'] ?? null) ? $site['name'] : Str::headline($key),
                'url' => $url,
                'secret' => $secret,
                'credits' => max(0, (int) ($site['credits'] ?? 0)),
                'cooldown' => max(
                    0,
                    (int) ($site['cooldown'] ?? $this->config->get('vote.cooldown_hours', 12)),
                ),
                'note' => is_string($site['note'] ?? null) ? $site['note'] : null,
            ];
        }

        return $sites;
    }

    /**
     * Every site, with what this account can do about it right now.
     *
     * The secret is not in what comes back. It is the one field on a site
     * that must never leave the server, and leaving it out here means no
     * controller can leak it by forwarding this straight to a response.
     *
     * @return list<array{
     *     key: string, name: string, credits: int, cooldown: int,
     *     note: string|null, ready: bool, next_at: string|null
     * }>
     */
    public function statusFor(Account $account): array
    {
        $claimed = VoteLog::query()
            ->where('account_id', $account->account_id)
            ->whereNotNull('claimed_at')
            ->selectRaw('site, MAX(claimed_at) as last_claim')
            ->groupBy('site')
            ->pluck('last_claim', 'site');

        $status = [];

        foreach ($this->sites() as $key => $site) {
            $last = $claimed[$key] ?? null;

            $next = $last === null
                ? null
                : Carbon::parse((string) $last)->addHours($site['cooldown']);

            $waiting = $next !== null && $next->isFuture();

            $status[] = [
                'key' => $site['key'],
                'name' => $site['name'],
                'credits' => $site['credits'],
                'cooldown' => $site['cooldown'],
                'note' => $site['note'],
                'ready' => ! $waiting,
                'next_at' => $waiting ? $next->toIso8601String() : null,
            ];
        }

        return $status;
    }

    /**
     * Mint a reference and answer with where to send the player.
     *
     * The row is written before they have voted and before anything is owed,
     * because the row is what the callback will be matched against. A vote
     * that never happens leaves an unclaimed row, which is an ordinary
     * outcome -- most of them, in fact.
     *
     * @return array{url: string, reference: string}|null null when the site
     *                                                    is not configured or the account is still inside its cooldown.
     */
    public function start(Account $account, string $key, ?string $ip): ?array
    {
        $site = $this->sites()[$key] ?? null;

        if ($site === null || ! $this->isReady($account, $site)) {
            return null;
        }

        /*
         * Random rather than derived from the account or the time. A
         * reference that could be computed from something a stranger knows
         * is a reference a stranger can claim, and 32 hex characters is well
         * past the point where guessing one is worth anybody's afternoon.
         */
        $reference = bin2hex(random_bytes(16));

        VoteLog::query()->create([
            'account_id' => $account->account_id,
            'site' => $key,
            'reference' => $reference,
            'credits' => 0,
            'started_ip' => $ip,
        ]);

        return [
            'url' => str_replace('{reference}', $reference, $site['url']),
            'reference' => $reference,
        ];
    }

    /**
     * Pay for a callback, if it is owed.
     *
     * Everything that can refuse, refuses the same way: by answering false.
     * The endpoint tells the listing site nothing about which of these it
     * was, because the ones a stranger would be probing for -- is this
     * reference real, is this secret right -- are exactly the questions worth
     * not answering.
     */
    public function claim(string $key, string $reference, string $secret, ?string $ip): bool
    {
        $site = $this->sites()[$key] ?? null;

        // Constant time, so the endpoint cannot be used to learn the secret
        // one character at a time.
        if ($site === null || ! hash_equals($site['secret'], $secret)) {
            return false;
        }

        $vote = VoteLog::query()
            ->where('site', $key)
            ->where('reference', $reference)
            ->whereNull('claimed_at')
            ->first();

        if ($vote === null) {
            return false;
        }

        $minutes = max(1, (int) $this->config->get('vote.reference_minutes', 60));

        if ($vote->created_at === null || $vote->created_at->addMinutes($minutes)->isPast()) {
            return false;
        }

        $account = Account::query()->find($vote->account_id);

        if ($account === null || ! $this->isReady($account, $site)) {
            return false;
        }

        /*
         * Spent first, and only then paid.
         *
         * The update is conditional on the row still being unclaimed, so two
         * callbacks arriving at once -- which is a retry, not two votes --
         * leave exactly one of them holding the claim. The loser stops here
         * rather than adding a second lot of credits.
         */
        $taken = VoteLog::query()
            ->whereKey($vote->id)
            ->whereNull('claimed_at')
            ->update([
                'claimed_at' => now(),
                'credits' => $site['credits'],
                'voter_ip' => $ip,
            ]);

        if ($taken === 0) {
            return false;
        }

        $this->award($account, $site['credits']);

        return true;
    }

    /**
     * Whether this account's cooldown on a site has passed.
     *
     * @param  array{cooldown: int, key: string}  $site
     */
    private function isReady(Account $account, array $site): bool
    {
        if ($site['cooldown'] <= 0) {
            return true;
        }

        return ! VoteLog::query()
            ->where('account_id', $account->account_id)
            ->where('site', $site['key'])
            ->whereNotNull('claimed_at')
            ->where('claimed_at', '>', now()->subHours($site['cooldown']))
            ->exists();
    }

    /**
     * Put the credits on the account.
     *
     * Deliberately not DonationService::addCredits(), which also writes
     * `last_donation_date` and `last_donation_amount`. A vote is not a
     * donation, and a panel that recorded one as the other would answer
     * "when did this player last donate" with the day they clicked a
     * ranking link -- quietly wrong, in the table an operator checks before
     * refunding somebody.
     */
    private function award(Account $account, int $credits): void
    {
        if ($credits <= 0) {
            return;
        }

        $connection = $this->connection();

        $updated = $connection->table('cp_credits')
            ->where('account_id', $account->account_id)
            // Incremented in SQL rather than read-then-written, so two
            // callbacks arriving together cannot lose one.
            ->update(['balance' => $connection->raw('balance + '.$credits)]);

        if ($updated === 0) {
            $connection->table('cp_credits')->insert([
                'account_id' => $account->account_id,
                'balance' => $credits,
            ]);
        }
    }

    public function balanceFor(Account $account): int
    {
        return (int) ($this->connection()->table('cp_credits')
            ->where('account_id', $account->account_id)
            ->value('balance') ?? 0);
    }

    private function connection(): ConnectionInterface
    {
        return $this->connections->connection($this->servers->current()->loginConnection());
    }
}
