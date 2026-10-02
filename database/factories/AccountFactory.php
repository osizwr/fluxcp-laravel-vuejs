<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\AccountState;
use App\Enums\Gender;
use App\Models\Account;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Builds rows in rAthena's `login` table.
 *
 * Only ever for tests against a disposable database. The panel does not
 * create accounts this way; registration goes through its own action so the
 * audit trail and credential handling are exercised.
 *
 * The default `user_pass` is cleartext, because that is what rAthena ships
 * with (UseMD5 off) and therefore what the compatibility path has to handle.
 *
 * @extends Factory<Account>
 */
final class AccountFactory extends Factory
{
    protected $model = Account::class;

    public const PASSWORD = 'Passw0rd!';

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'userid' => $this->faker->unique()->userName(),
            'user_pass' => self::PASSWORD,
            'sex' => Gender::Male,
            'email' => $this->faker->unique()->safeEmail(),
            'group_id' => 0,
            'state' => AccountState::Normal->value,
            'unban_time' => 0,
            'expiration_time' => 0,
            'logincount' => 0,
            'last_ip' => '127.0.0.1',
            'birthdate' => $this->faker->dateTimeBetween('-40 years', '-18 years')->format('Y-m-d'),
            'character_slots' => 9,
        ];
    }

    public function named(string $userid): self
    {
        return $this->state(fn (): array => ['userid' => $userid]);
    }

    public function withPassword(string $password): self
    {
        return $this->state(fn (): array => ['user_pass' => $password]);
    }

    /**
     * Store the credential as unsalted MD5, as a server with UseMD5 enabled
     * would.
     */
    public function withMd5Password(string $password): self
    {
        return $this->state(fn (): array => ['user_pass' => md5($password)]);
    }

    public function inGroup(int $groupId): self
    {
        return $this->state(fn (): array => ['group_id' => $groupId]);
    }

    public function administrator(): self
    {
        return $this->inGroup(99);
    }

    public function juniorGameMaster(): self
    {
        return $this->inGroup(2);
    }

    public function seniorGameMaster(): self
    {
        return $this->inGroup(10);
    }

    public function permanentlyBanned(): self
    {
        return $this->state(fn (): array => ['state' => AccountState::PermanentlyBanned->value]);
    }

    /**
     * A temporary ban expiring at the given Unix time. Defaults to an hour
     * from now.
     */
    public function temporarilyBanned(?int $until = null): self
    {
        return $this->state(fn (): array => [
            'state' => AccountState::Normal->value,
            'unban_time' => $until ?? (time() + 3600),
        ]);
    }

    /**
     * An rAthena inter-server account. These must never be able to sign in.
     */
    public function serverAccount(): self
    {
        return $this->state(fn (): array => ['sex' => Gender::Server]);
    }

    /**
     * A disabled account: rAthena marks these with a negative group_id.
     */
    public function disabled(): self
    {
        return $this->state(fn (): array => ['group_id' => -1]);
    }
}
