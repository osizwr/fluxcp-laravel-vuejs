<?php

declare(strict_types=1);

namespace App\Actions\Account;

use App\Enums\Gender;
use App\Models\Account;
use App\Models\PanelCredential;
use App\Services\Mail\AccountMailer;
use App\Services\Rathena\AccountConfirmationService;
use App\Services\Rathena\RathenaAccountService;
use App\Support\Rathena\ServerGroup;
use App\Support\Rathena\ServerRegistry;
use App\Support\Tokens\ConfirmationSecrets;
use Illuminate\Database\ConnectionResolverInterface;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Register an account, and hold it for confirmation if the operator requires
 * one.
 *
 * Ports modules/account/create.php. The account creation itself belongs to
 * {@see RathenaAccountService}, which is the only thing that writes a
 * credential; this action is the sequence around it.
 *
 * The two database steps run in one transaction. The legacy panel did them as
 * separate statements, which left a window -- between the account existing and
 * the hold being applied -- in which a registration that failed partway
 * produced a live, unconfirmed, fully usable account. That is the failure mode
 * worth closing: it turns "confirmation required" into "confirmation required
 * unless something goes wrong".
 */
final readonly class RegisterAccount
{
    public function __construct(
        private ServerRegistry $servers,
        private RathenaAccountService $accounts,
        private AccountConfirmationService $confirmations,
        private AccountMailer $mailer,
        private ConnectionResolverInterface $connections,
    ) {}

    /**
     * @throws ValidationException
     */
    public function handle(
        ?string $serverGroup,
        string $username,
        string $password,
        string $email,
        Gender $gender,
        string $registeredFromIp,
    ): RegistrationOutcome {
        $group = $serverGroup === null || $serverGroup === ''
            ? $this->servers->current()
            : $this->servers->get($serverGroup);

        $this->servers->use($group->key);

        $requiresConfirmation = config('panel.registration.require_email_confirmation') === true;

        [$account, $secrets] = $this->persist(
            $group,
            $username,
            $password,
            $email,
            $gender,
            $registeredFromIp,
            $requiresConfirmation,
        );

        if ($secrets === null) {
            return new RegistrationOutcome($account, false, false);
        }

        /*
         * Sent after the transaction commits. Sending inside it would mean a
         * confirmation link could reach somebody for an account the database
         * then rolled back.
         */
        $sent = $this->mailer->sendAccountConfirmation($group, $account, $secrets);

        return new RegistrationOutcome($account, true, $sent);
    }

    /**
     * The database half, as one unit.
     *
     * @return array{0: Account, 1: ConfirmationSecrets|null}
     *
     * @throws ValidationException
     */
    private function persist(
        ServerGroup $group,
        string $username,
        string $password,
        string $email,
        Gender $gender,
        string $registeredFromIp,
        bool $requiresConfirmation,
    ): array {
        $connection = $this->connections->connection($group->loginConnection());

        $account = null;

        try {
            return $connection->transaction(function () use (
                $group,
                $username,
                $password,
                $email,
                $gender,
                $registeredFromIp,
                $requiresConfirmation,
                &$account,
            ): array {
                $account = $this->accounts->create(
                    username: $username,
                    password: $password,
                    email: $email,
                    gender: $gender,
                    registeredFromIp: $registeredFromIp,
                    serverGroup: $group->key,
                );

                return [
                    $account,
                    $requiresConfirmation
                        ? $this->confirmations->issue($group, $account)
                        : null,
                ];
            });
        } catch (Throwable $e) {
            /*
             * The panel's own credential lives on a different connection, so
             * the rollback above does not cover it.
             *
             * In practice an orphan is harmless: MySQL does not reissue an
             * auto-increment value consumed by a rolled-back INSERT, so no
             * future account can inherit this row. But sign-in trusts a
             * matching panel credential before it looks at rAthena's column,
             * so a row keyed to an id that somehow did get reused would be an
             * accepted password for the wrong account. Cheap to remove, and
             * not a thing to leave resting on an invariant of the storage
             * engine.
             */
            if ($account instanceof Account) {
                PanelCredential::query()
                    ->where('server_group', $group->key)
                    ->where('account_id', $account->account_id)
                    ->delete();
            }

            throw $e;
        }
    }
}
