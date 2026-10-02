<?php

declare(strict_types=1);

namespace App\Services\Auth;

use App\Enums\LoginFailure;
use App\Support\Rathena\ServerGroup;
use Illuminate\Database\ConnectionResolverInterface;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Records control-panel sign-in attempts in `cp_loginlog`.
 *
 * The audit trail is preserved from FluxCP, which logged both successes and
 * failures with the failure's error code. What is not preserved is the
 * password: the legacy panel wrote the submitted password into this table on
 * every attempt, which with rAthena's cleartext storage meant every password
 * anyone ever typed into the form was persisted in the clear, including the
 * wrong ones people type from other sites.
 *
 * The column is still created so an existing FluxCP database stays readable,
 * and is written as an empty string. See docs/MIGRATION_DECISIONS.md (D2).
 */
final readonly class LoginAuditLog
{
    public function __construct(
        private ConnectionResolverInterface $connections,
    ) {}

    public function recordSuccess(
        ServerGroup $group,
        int $accountId,
        string $username,
        string $ipAddress,
    ): void {
        $this->write($group, $accountId, $username, $ipAddress, null);
    }

    /**
     * Record a refused attempt.
     *
     * The account id may be unknown, because an attempt can be refused before
     * any account is identified: an unrecognised name, or an address banned
     * ahead of the credential check. Those are logged against account 0, as the
     * legacy panel did when it could not resolve one.
     */
    public function recordFailure(
        ServerGroup $group,
        ?int $accountId,
        string $username,
        string $ipAddress,
        LoginFailure $reason,
    ): void {
        $this->write($group, $accountId ?? 0, $username, $ipAddress, $reason->value);
    }

    private function write(
        ServerGroup $group,
        int $accountId,
        string $username,
        string $ipAddress,
        ?int $errorCode,
    ): void {
        try {
            $this->connections
                ->connection($group->loginConnection())
                ->table('cp_loginlog')
                ->insert([
                    'account_id' => $accountId,
                    // The column is varchar(23), matching login.userid.
                    'username' => mb_substr($username, 0, 23),
                    'password' => '',
                    'ip' => mb_substr($ipAddress, 0, 39),
                    'error_code' => $errorCode,
                    'login_date' => now(),
                ]);
        } catch (Throwable $e) {
            /*
             * An audit write must never turn a valid sign-in into an error, or
             * a failed sign-in into a 500 that hides the real reason. A table
             * missing because the schema installer has not been run is the
             * likely cause, so the problem is logged rather than raised.
             */
            Log::warning('Could not record a sign-in attempt.', [
                'server_group' => $group->key,
                'reason' => $e->getMessage(),
            ]);
        }
    }
}
