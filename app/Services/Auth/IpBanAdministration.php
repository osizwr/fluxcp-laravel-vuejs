<?php

declare(strict_types=1);

namespace App\Services\Auth;

use App\Enums\BanType;
use App\Support\Rathena\ServerGroup;
use App\Support\Rathena\ServerRegistry;
use Carbon\CarbonImmutable;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\ConnectionResolverInterface;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * Adding, editing and lifting IP bans.
 *
 * Ports Flux_LoginServer::addIpBan() and ::removeIpBan(), and the bodies of
 * modules/ipban/add.php, edit.php, remove.php and unban.php.
 *
 * Two tables are written together and they mean different things:
 *
 *   ipbanlist     rAthena's own, read by the login server. This is what
 *                 actually refuses a connection.
 *   cp_ipbanlog   the panel's append-only history, read by nothing but the
 *                 panel. A lift is a new row, not a deletion.
 *
 * ---------------------------------------------------------------------------
 * The whitelist is the important part
 * ---------------------------------------------------------------------------
 *
 * A pattern like `*.*.*.*` or one covering the operator's own range locks
 * every administrator out of their own panel and game server, and the only way
 * back is editing the database by hand. FluxCP had `IpWhitelistPattern` for
 * exactly this, and it is checked here before anything is written.
 *
 * The check is deliberately not "is this my current address": somebody banning
 * from the office should still not be able to ban the range their colleagues
 * connect from.
 */
final readonly class IpBanAdministration
{
    /**
     * rAthena's pattern shape: four octets, where the first must be numeric
     * and the rest may be a literal `*`. Taken from the legacy validation
     * rather than loosened -- the login server matches on exactly this form,
     * so a pattern it cannot parse is a ban that silently does nothing.
     */
    private const PATTERN = '/^([0-9]|[1-9][0-9]|1[0-9]{2}|2[0-4][0-9]|25[0-5])'
        .'\.([0-9]|[1-9][0-9]|1[0-9]{2}|2[0-4][0-9]|25[0-5]|\*)'
        .'\.([0-9]|[1-9][0-9]|1[0-9]{2}|2[0-4][0-9]|25[0-5]|\*)'
        .'\.([0-9]|[1-9][0-9]|1[0-9]{2}|2[0-4][0-9]|25[0-5]|\*)$/';

    public function __construct(
        private ConnectionResolverInterface $connections,
        private ServerRegistry $servers,
    ) {}

    /**
     * Current bans, as a query the caller can filter and paginate.
     */
    public function query(?ServerGroup $group = null): Builder
    {
        return $this->connection($group)
            ->table('ipbanlist')
            ->select('list', 'reason', 'btime', 'rtime');
    }

    /**
     * Ban a pattern.
     *
     * @throws ValidationException
     */
    public function add(
        string $pattern,
        string $reason,
        CarbonImmutable $until,
        int $bannedBy,
        ?ServerGroup $group = null,
    ): void {
        $group ??= $this->servers->current();

        $this->validate($pattern, $reason, $until, $group);

        $connection = $this->connection($group);

        $connection->transaction(function () use ($connection, $pattern, $reason, $until, $bannedBy): void {
            $connection->table('cp_ipbanlog')->insert([
                'ip_address' => $pattern,
                'banned_by' => $bannedBy,
                'ban_type' => BanType::Temporary->value,
                'ban_until' => $until,
                'ban_date' => now(),
                'ban_reason' => $reason,
            ]);

            /*
             * updateOrInsert rather than insert: `ipbanlist.list` is the
             * primary key, so re-banning a pattern that is already listed
             * would fail outright. Replacing it is what an operator means by
             * banning it again.
             */
            $connection->table('ipbanlist')->updateOrInsert(
                ['list' => $pattern],
                ['reason' => $reason, 'rtime' => $until, 'btime' => now()],
            );
        });
    }

    /**
     * Change an existing ban's pattern, reason or expiry.
     *
     * @throws ValidationException
     */
    public function edit(
        string $currentPattern,
        string $pattern,
        string $reason,
        CarbonImmutable $until,
        int $editedBy,
        string $editReason,
        ?ServerGroup $group = null,
    ): bool {
        $group ??= $this->servers->current();

        $this->validate($pattern, $reason, $until, $group);

        $connection = $this->connection($group);

        if (! $connection->table('ipbanlist')->where('list', $currentPattern)->exists()) {
            return false;
        }

        $connection->transaction(function () use (
            $connection, $currentPattern, $pattern, $reason, $until, $editedBy, $editReason
        ): void {
            /*
             * The edit is recorded as its own history row. Without it, an
             * administrator widening a ban from one address to a whole /8
             * leaves no trace of who did it or why.
             */
            $connection->table('cp_ipbanlog')->insert([
                'ip_address' => $pattern,
                'banned_by' => $editedBy,
                'ban_type' => BanType::Temporary->value,
                'ban_until' => $until,
                'ban_date' => now(),
                'ban_reason' => $editReason === ''
                    ? $reason
                    : "Edited from {$currentPattern}: {$editReason}",
            ]);

            $connection->table('ipbanlist')
                ->where('list', $currentPattern)
                ->update(['list' => $pattern, 'reason' => $reason, 'rtime' => $until]);
        });

        return true;
    }

    /**
     * Lift a ban.
     *
     * The `ipbanlist` row is deleted, because that table is current state and
     * the login server reads it directly. The history row is the record that
     * it ever existed.
     */
    public function remove(
        string $pattern,
        string $reason,
        int $unbannedBy,
        ?ServerGroup $group = null,
    ): bool {
        $connection = $this->connection($group);

        if (! $connection->table('ipbanlist')->where('list', $pattern)->exists()) {
            return false;
        }

        $connection->transaction(function () use ($connection, $pattern, $reason, $unbannedBy): void {
            $connection->table('cp_ipbanlog')->insert([
                'ip_address' => $pattern,
                'banned_by' => $unbannedBy,
                'ban_type' => BanType::Lifted->value,
                // The same sentinel the account ban history uses for a lift.
                'ban_until' => '1000-01-01 00:00:00',
                'ban_date' => now(),
                'ban_reason' => $reason,
            ]);

            $connection->table('ipbanlist')->where('list', $pattern)->delete();
        });

        return true;
    }

    /**
     * Lift several bans, reporting which could not be found.
     *
     * @param  list<string>  $patterns
     * @return array{lifted: list<string>, missing: list<string>}
     */
    public function removeMany(
        array $patterns,
        string $reason,
        int $unbannedBy,
        ?ServerGroup $group = null,
    ): array {
        $lifted = [];
        $missing = [];

        foreach (array_unique($patterns) as $pattern) {
            $this->remove($pattern, $reason, $unbannedBy, $group)
                ? $lifted[] = $pattern
                : $missing[] = $pattern;
        }

        return ['lifted' => $lifted, 'missing' => $missing];
    }

    /**
     * Whether a pattern is protected from being banned.
     */
    public function isWhitelisted(string $pattern): bool
    {
        foreach ((array) config('panel.ip_bans.whitelist', []) as $whitelisted) {
            $whitelisted = trim((string) $whitelisted);

            if ($whitelisted === '') {
                continue;
            }

            /*
             * Compared as a wildcard glob rather than a regular expression.
             * FluxCP interpolated this setting straight into a PCRE, so an
             * operator writing `192.168.*.*` -- the same syntax the ban list
             * itself uses -- got a pattern where `.` matched anything and `*`
             * was a quantifier on the preceding character. That silently
             * whitelisted far more than intended, or failed to compile.
             */
            if (fnmatch($whitelisted, $pattern, FNM_CASEFOLD)) {
                return true;
            }

            // A whitelisted single address is also protected by any pattern
            // that would cover it.
            if ($this->patternCovers($pattern, $whitelisted)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether a ban pattern would match a given address.
     */
    private function patternCovers(string $pattern, string $address): bool
    {
        if (filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) {
            return false;
        }

        $patternOctets = explode('.', $pattern);
        $addressOctets = explode('.', $address);

        if (count($patternOctets) !== 4 || count($addressOctets) !== 4) {
            return false;
        }

        foreach ($patternOctets as $index => $octet) {
            if ($octet !== '*' && $octet !== $addressOctets[$index]) {
                return false;
            }
        }

        return true;
    }

    /**
     * @throws ValidationException
     */
    private function validate(
        string $pattern,
        string $reason,
        CarbonImmutable $until,
        ServerGroup $group,
    ): void {
        $validator = Validator::make(
            ['pattern' => $pattern, 'reason' => $reason],
            [
                'pattern' => ['required', 'string', 'regex:'.self::PATTERN],
                'reason' => ['required', 'string', 'max:255'],
            ],
            [
                'pattern.regex' => 'An IP ban must be four octets, with * allowed in all but the first — for example 203.0.113.* or 198.51.100.7.',
            ],
        );

        $validator->after(function ($validator) use ($pattern, $until): void {
            if ($this->isWhitelisted($pattern)) {
                $validator->errors()->add(
                    'pattern',
                    'That pattern is protected by the whitelist and cannot be banned.',
                );
            }

            if ($until->isPast()) {
                $validator->errors()->add('until', 'The ban must end in the future.');
            }
        });

        $validator->validate();
    }

    private function connection(?ServerGroup $group): ConnectionInterface
    {
        $group ??= $this->servers->current();

        return $this->connections->connection($group->loginConnection());
    }
}
