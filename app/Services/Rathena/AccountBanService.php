<?php

declare(strict_types=1);

namespace App\Services\Rathena;

use App\Enums\AccountState;
use App\Enums\BanType;
use App\Models\Account;
use App\Support\Rathena\ServerGroup;
use Illuminate\Database\ConnectionResolverInterface;

/**
 * Account ban state, and the history behind it.
 *
 * Ports Flux_LoginServer::permanentlyBan() and ::unban(). Two tables are
 * involved and they mean different things:
 *
 *   login.state      current state, read by rAthena's login server. 5 refuses
 *                    the account at the game's login screen.
 *
 *   cp_banlog        append-only history, read by nothing but the panel. A
 *                    lift is a new row, not a deletion of the ban it undoes.
 *
 * Both are written here, in that order, so an account is never left refused
 * with no record of why.
 *
 * ---------------------------------------------------------------------------
 * The sentinel dates
 * ---------------------------------------------------------------------------
 *
 * `ban_until` is '9999-12-31 23:59:59' for a permanent ban and
 * '1000-01-01 00:00:00' for a lift. They are not meaningful timestamps; they
 * are the values FluxCP wrote, and they are preserved so a panel reading this
 * history -- including an existing FluxCP install pointed at the same
 * database -- sorts and renders these rows as it always did. They are also
 * MariaDB's maximum and minimum DATETIME, so neither can be mistaken for a
 * real date.
 */
final readonly class AccountBanService
{
    private const PERMANENT_UNTIL = '9999-12-31 23:59:59';

    private const LIFTED_UNTIL = '1000-01-01 00:00:00';

    public function __construct(private ConnectionResolverInterface $connections) {}

    /**
     * Refuse an account indefinitely.
     *
     * @param  int|null  $bannedBy  The acting account, or null when the panel
     *                              itself did it -- as when a registration is
     *                              held pending e-mail confirmation.
     */
    public function permanentlyBan(
        ServerGroup $group,
        Account $account,
        string $reason,
        ?int $bannedBy = null,
    ): void {
        $this->record($group, $account, BanType::Permanent, self::PERMANENT_UNTIL, $reason, $bannedBy);

        $this->setState($group, $account, AccountState::PermanentlyBanned);
    }

    /**
     * Allow an account to sign in again.
     *
     * Clears `unban_time` as well as `state`, because a lifted permanent ban
     * on an account that also has a stale temporary ban timestamp would
     * otherwise still be refused by the game server.
     */
    public function unban(
        ServerGroup $group,
        Account $account,
        string $reason,
        ?int $unbannedBy = null,
    ): void {
        $this->record($group, $account, BanType::Lifted, self::LIFTED_UNTIL, $reason, $unbannedBy);

        $this->setState($group, $account, AccountState::Normal);
    }

    private function record(
        ServerGroup $group,
        Account $account,
        BanType $type,
        string $until,
        string $reason,
        ?int $actor,
    ): void {
        $this->connections
            ->connection($group->loginConnection())
            ->table('cp_banlog')
            ->insert([
                'account_id' => $account->account_id,
                'banned_by' => $actor,
                'ban_type' => $type->value,
                'ban_until' => $until,
                'ban_date' => now(),
                'ban_reason' => $reason,
            ]);
    }

    private function setState(ServerGroup $group, Account $account, AccountState $state): void
    {
        $this->connections
            ->connection($group->loginConnection())
            ->table('login')
            ->where('account_id', $account->account_id)
            ->update([
                'state' => $state->value,
                'unban_time' => 0,
            ]);

        // Keep the in-memory model consistent with what was just written, so a
        // caller that goes on to read $account->state does not see the old
        // value and act on it.
        $account->state = $state->value;
        $account->unban_time = 0;
        $account->syncOriginalAttributes(['state', 'unban_time']);
    }
}
