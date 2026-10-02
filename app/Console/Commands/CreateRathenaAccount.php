<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\Gender;
use App\Services\Auth\RathenaCredentialVerifier;
use App\Services\Rathena\RathenaAccountService;
use App\Support\Rathena\ServerRegistry;
use Illuminate\Console\Command;
use Illuminate\Validation\ValidationException;

/**
 * Creates an rAthena account from the command line.
 *
 * Goes through the same service as every other creation path, so the password
 * is stored in rAthena's format here exactly as it would be from a web
 * registration. A second code path that wrote the column itself is how the two
 * credential systems get mixed up again.
 */
final class CreateRathenaAccount extends Command
{
    protected $signature = 'panel:create-account
                            {username : The rAthena account name}
                            {--email= : Account e-mail address}
                            {--gender=M : M or F}
                            {--birthdate= : YYYY-MM-DD}
                            {--group= : Server group, defaults to the configured default}
                            {--password= : Supply non-interactively; prefer the prompt}';

    protected $description = 'Create an rAthena account, storing the password in the format the emulator expects';

    public function handle(
        RathenaAccountService $accounts,
        RathenaCredentialVerifier $credentials,
        ServerRegistry $servers,
    ): int {
        $username = (string) $this->argument('username');

        $password = (string) ($this->option('password') ?? '');

        if ($password === '') {
            $password = (string) $this->secret('Password');

            if ($password !== (string) $this->secret('Confirm password')) {
                $this->components->error('The passwords do not match.');

                return self::FAILURE;
            }
        }

        $email = (string) ($this->option('email') ?? '');

        if ($email === '') {
            $email = (string) $this->ask('E-mail address');
        }

        $gender = Gender::tryFrom(strtoupper((string) $this->option('gender')));

        if ($gender === null || ! $gender->isPlayer()) {
            $this->components->error('Gender must be M or F.');

            return self::FAILURE;
        }

        $groupKey = $this->option('group');
        $group = $groupKey === null ? $servers->default() : $servers->get((string) $groupKey);

        /*
         * Said out loud before writing anything, because an operator running
         * this on a server configured one way while the emulator runs the
         * other will produce an account that cannot log in, and the symptom
         * ("wrong password") points nowhere near the cause.
         */
        $this->components->info(sprintf(
            "Server group '%s' stores credentials as %s. This must match your emulator's use_MD5_passwords setting.",
            $group->key,
            $credentials->storageFormat($group),
        ));

        try {
            $account = $accounts->create(
                username: $username,
                password: $password,
                email: $email,
                gender: $gender,
                birthdate: $this->option('birthdate') === null ? null : (string) $this->option('birthdate'),
                registeredFromIp: null,
                serverGroup: $group->key,
            );
        } catch (ValidationException $e) {
            foreach ($e->errors() as $field => $messages) {
                foreach ($messages as $message) {
                    $this->components->error("{$field}: {$message}");
                }
            }

            return self::FAILURE;
        }

        $this->components->info("Created account {$account->userid} (id {$account->account_id}).");

        $this->table(
            ['Field', 'Value'],
            [
                ['account_id', (string) $account->account_id],
                ['userid', $account->userid],
                ['group_id', (string) $account->group_id],
                ['character_slots', (string) $account->character_slots],
                ['credential format', $credentials->storageFormat($group)],
                /*
                 * Deliberately not printed. The point of this command is that
                 * the stored value is a game credential, and echoing it to a
                 * terminal that may be logged defeats that.
                 */
                ['login.user_pass', '(not shown)'],
            ],
        );

        return self::SUCCESS;
    }
}
