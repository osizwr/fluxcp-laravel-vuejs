<?php

declare(strict_types=1);

namespace App\Services\Rathena;

use App\Enums\Gender;
use App\Models\Account;
use App\Models\PanelCredential;
use App\Services\Auth\RathenaCredentialVerifier;
use App\Support\Rathena\ServerGroup;
use App\Support\Rathena\ServerRegistry;
use Illuminate\Database\ConnectionResolverInterface;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * The only place an rAthena account is created or has its password changed.
 *
 * This service exists to keep two credential systems apart, because they have
 * incompatible requirements and mixing them breaks one or the other:
 *
 *   login.user_pass      the credential rAthena's login server reads. Must be
 *                        exactly the string the game client transmits, so it
 *                        is cleartext, or an MD5 hex digest when the server
 *                        runs with use_MD5_passwords enabled. Never a bcrypt
 *                        or Argon hash -- see the note below.
 *
 *   panel_credentials    the panel's own credential, hashed with Laravel's
 *                        configured hasher. Used for signing in to this
 *                        website and nowhere else.
 *
 * Both are written here, from one submitted password, in their own formats.
 *
 * ---------------------------------------------------------------------------
 * Why login.user_pass cannot be bcrypt
 * ---------------------------------------------------------------------------
 *
 * Verified against rAthena's source rather than assumed:
 *
 *   src/login/login.cpp      login_check_password() compares with
 *                            `strcmp(sd.passwd, acc.pass)` when the client
 *                            sends an unencrypted password, and in the
 *                            passwordencrypt modes it MD5s `acc.pass` itself
 *                            together with a per-session key. Both require the
 *                            stored value to be reproducible by the client.
 *
 *   src/login/login.cpp      login_mmo_auth_new() stores the client-supplied
 *                            password verbatim; the server never hashes it.
 *
 *   src/login/account.hpp    `char pass[32+1];  // 23+1 for plaintext,
 *                            32+1 for md5-ed passwords`
 *
 *   src/common/mmo.hpp       `#define PASSWD_LENGTH (32 + 1)`
 *
 * So a bcrypt hash is not merely discouraged: at 60 characters it does not fit
 * the column or the emulator's buffer, it is not reproducible by the client,
 * and under passwordencrypt rAthena would hash the hash. An account created
 * that way could never log in to the game.
 *
 * See docs/MIGRATION_DECISIONS.md (D1).
 */
final readonly class RathenaAccountService
{
    public function __construct(
        private ServerRegistry $servers,
        private RathenaCredentialVerifier $credentials,
        private ConnectionResolverInterface $connections,
    ) {}

    /**
     * Create an rAthena account.
     *
     * @param  string|null  $registeredFromIp  Recorded in the audit trail.
     *
     * @throws ValidationException
     */
    public function create(
        string $username,
        string $password,
        string $email,
        Gender $gender,
        ?string $birthdate = null,
        ?string $registeredFromIp = null,
        ?string $serverGroup = null,
    ): Account {
        $group = $serverGroup === null
            ? $this->servers->current()
            : $this->servers->get($serverGroup);

        $this->servers->use($group->key);

        $this->validate($group, $username, $password, $email, $birthdate);

        $account = $this->insertAccount($group, $username, $password, $email, $gender, $birthdate);

        $this->recordRegistration($group, $account, $registeredFromIp);
        $this->storePanelCredential($group, $account, $password);

        return $account;
    }

    /**
     * Change an existing account's password.
     *
     * Updates rAthena's column in its own format and the panel's hash in its
     * own, so the account keeps working in both the game and the website.
     *
     * @throws ValidationException
     */
    public function changePassword(
        Account $account,
        string $newPassword,
        ?string $changedFromIp = null,
    ): void {
        $group = $this->servers->current();

        $this->validatePassword($group, $newPassword, $account->userid);

        $this->connections
            ->connection($group->loginConnection())
            ->table('login')
            ->where('account_id', $account->account_id)
            ->update([
                // The single, explicit transformation. No Eloquent cast and no
                // Laravel hasher is involved on this column.
                'user_pass' => $this->credentials->encode($group, $newPassword),
            ]);

        $this->recordPasswordChange($group, $account, $changedFromIp);
        $this->storePanelCredential($group, $account, $newPassword);

        $account->refresh();
    }

    /*
    |--------------------------------------------------------------------------
    | Writes
    |--------------------------------------------------------------------------
    */

    private function insertAccount(
        ServerGroup $group,
        string $username,
        string $password,
        string $email,
        Gender $gender,
        ?string $birthdate,
    ): Account {
        $account = new Account;

        /*
         * forceFill rather than create(): the model declares an empty
         * $fillable on purpose, so that nothing can write to rAthena's account
         * table by mass assignment. This service is the authorised writer, and
         * says so explicitly.
         *
         * Note what is *not* happening here. Account has no cast on
         * user_pass, so the value assigned is the value stored. The encoding
         * below is the only transformation applied to it.
         */
        $account->forceFill([
            'userid' => $username,
            'user_pass' => $this->credentials->encode($group, $password),
            'email' => $email,
            'sex' => $gender,
            'group_id' => $group->loginServer->defaultGroupId,
            'birthdate' => $birthdate,
            'character_slots' => $this->defaultCharacterSlots($group),
        ])->save();

        return $account;
    }

    /**
     * Write the registration audit row.
     *
     * The legacy panel also wrote the password into this table. This one does
     * not: the column is left empty so an existing FluxCP database stays
     * readable without the panel adding to a cleartext password store. See
     * docs/MIGRATION_DECISIONS.md (D2).
     */
    private function recordRegistration(ServerGroup $group, Account $account, ?string $ip): void
    {
        $this->connections
            ->connection($group->loginConnection())
            ->table('cp_createlog')
            ->insert([
                'account_id' => $account->account_id,
                'userid' => $account->userid,
                'user_pass' => '',
                'sex' => $account->sex->value,
                'email' => $account->email,
                'reg_date' => now(),
                'reg_ip' => mb_substr($ip ?? '', 0, 39),
                'confirmed' => 1,
            ]);
    }

    /**
     * Write the password-change audit row, again without the passwords the
     * legacy panel recorded there (D2).
     */
    private function recordPasswordChange(ServerGroup $group, Account $account, ?string $ip): void
    {
        $this->connections
            ->connection($group->loginConnection())
            ->table('cp_pwchange')
            ->insert([
                'account_id' => $account->account_id,
                'old_password' => '',
                'new_password' => '',
                'change_date' => now(),
                'change_ip' => mb_substr($ip ?? '', 0, 39),
            ]);
    }

    /**
     * Store the panel's own hashed credential.
     *
     * This is the half that *should* go through Laravel's hasher, and does:
     * PanelCredential casts password_hash as 'hashed', so assigning the raw
     * password here hashes it. The two systems meet only at this one call
     * site, in opposite directions, which is the point of the separation.
     */
    private function storePanelCredential(ServerGroup $group, Account $account, string $password): void
    {
        PanelCredential::query()->updateOrCreate(
            [
                'server_group' => $group->key,
                'account_id' => $account->account_id,
            ],
            ['password_hash' => $password],
        );

        $account->unsetRelation('panelCredential');
    }

    /*
    |--------------------------------------------------------------------------
    | Validation
    |--------------------------------------------------------------------------
    */

    /**
     * @throws ValidationException
     */
    private function validate(
        ServerGroup $group,
        string $username,
        string $password,
        string $email,
        ?string $birthdate,
    ): void {
        $policy = (array) config('panel.registration');

        $validator = Validator::make(
            [
                'username' => $username,
                'email' => $email,
                'birthdate' => $birthdate,
            ],
            [
                'username' => [
                    'required',
                    'string',
                    'min:'.(int) ($policy['username']['min_length'] ?? 4),
                    // rAthena's login.userid is varchar(23), matching
                    // NAME_LENGTH (23 + 1) in src/common/mmo.hpp.
                    'max:23',
                    'regex:'.($policy['username']['pattern'] ?? '/^[a-zA-Z0-9_]+$/'),
                ],
                'email' => ['required', 'string', 'email', 'max:39'],
                'birthdate' => ['nullable', 'date_format:Y-m-d'],
            ],
        );

        $validator->after(function ($validator) use ($group, $username, $email, $policy): void {
            if ($this->usernameTaken($group, $username)) {
                $validator->errors()->add('username', 'That account name is already taken.');
            }

            if (($policy['allow_duplicate_emails'] ?? false) !== true && $this->emailTaken($group, $email)) {
                $validator->errors()->add('email', 'That e-mail address is already in use.');
            }
        });

        $validator->validate();

        $this->validatePassword($group, $password, $username);
    }

    /**
     * Password rules, including the one that is not a policy choice.
     *
     * The maximum length is a hard storage constraint, not a preference: with
     * cleartext storage anything beyond the column's 32 characters is silently
     * truncated on write and could then never be matched, producing an account
     * that cannot log in anywhere.
     *
     * @throws ValidationException
     */
    private function validatePassword(ServerGroup $group, string $password, string $username): void
    {
        $policy = (array) config('panel.registration.password');
        $storageLimit = $this->credentials->maximumPasswordLength($group);

        $max = (int) ($policy['max_length'] ?? 31);

        if ($storageLimit !== null) {
            $max = min($max, $storageLimit);
        }

        $rules = [
            'required',
            'string',
            'min:'.(int) ($policy['min_length'] ?? 8),
            'max:'.$max,
        ];

        $errors = [];

        $counts = [
            'min_uppercase' => ['/[A-Z]/', 'uppercase letter'],
            'min_lowercase' => ['/[a-z]/', 'lowercase letter'],
            'min_numbers' => ['/[0-9]/', 'number'],
            'min_symbols' => ['/[^A-Za-z0-9]/', 'symbol'],
        ];

        foreach ($counts as $key => [$pattern, $label]) {
            $required = (int) ($policy[$key] ?? 0);

            if ($required > 0 && preg_match_all($pattern, $password) < $required) {
                $errors[] = "at least {$required} {$label}".($required === 1 ? '' : 's');
            }
        }

        if (($policy['allow_username_inside'] ?? false) !== true
            && $username !== ''
            && stripos($password, $username) !== false) {
            $errors[] = 'no part matching the account name';
        }

        $validator = Validator::make(['password' => $password], ['password' => $rules]);

        if ($errors !== []) {
            $validator->after(function ($validator) use ($errors): void {
                $validator->errors()->add(
                    'password',
                    'The password must contain '.implode(', ', $errors).'.',
                );
            });
        }

        $validator->validate();
    }

    private function usernameTaken(ServerGroup $group, string $username): bool
    {
        $query = $this->connections
            ->connection($group->loginConnection())
            ->table('login');

        // Case sensitivity has to match the emulator, or two accounts can be
        // created that rAthena then treats as one.
        $group->loginServer->caseSensitive
            ? $query->whereRaw('CAST(userid AS BINARY) = ?', [$username])
            : $query->whereRaw('LOWER(userid) = LOWER(?)', [$username]);

        return $query->exists();
    }

    private function emailTaken(ServerGroup $group, string $email): bool
    {
        return $this->connections
            ->connection($group->loginConnection())
            ->table('login')
            ->whereRaw('LOWER(email) = LOWER(?)', [$email])
            ->exists();
    }

    private function defaultCharacterSlots(ServerGroup $group): int
    {
        return $group->defaultCharMapServer()->maxCharacterSlots;
    }
}
