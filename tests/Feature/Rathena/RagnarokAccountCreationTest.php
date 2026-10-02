<?php

declare(strict_types=1);

namespace Tests\Feature\Rathena;

use App\Enums\Gender;
use App\Models\Account;
use App\Services\Rathena\RathenaAccountService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\InteractsWithRathena;
use Tests\TestCase;

/**
 * Every route by which an rAthena account can come into existence.
 *
 * The point is coverage of paths, not of validation rules. A second code path
 * that writes `login.user_pass` itself is exactly how the two credential
 * systems get mixed back together, so each path is checked to store the same
 * thing.
 */
final class RagnarokAccountCreationTest extends TestCase
{
    use InteractsWithRathena;
    use RefreshDatabase;

    private const PASSWORD = 'TestPassword123!';

    private function service(): RathenaAccountService
    {
        return $this->app->make(RathenaAccountService::class);
    }

    private function storedPassword(string $username): string
    {
        return (string) DB::connection($this->serverGroup()->loginConnection())
            ->table('login')
            ->whereRaw('LOWER(userid) = LOWER(?)', [$username])
            ->value('user_pass');
    }

    /*
    |--------------------------------------------------------------------------
    | Creation paths
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function the_service_creates_an_account_with_the_expected_defaults(): void
    {
        $account = $this->service()->create(
            username: 'fluxcp_test',
            password: self::PASSWORD,
            email: 'fluxcp_test@example.com',
            gender: Gender::Male,
            birthdate: '1995-04-12',
            registeredFromIp: '198.51.100.10',
        );

        $this->assertSame('fluxcp_test', $account->userid);
        $this->assertSame(Gender::Male, $account->sex);
        $this->assertSame('fluxcp_test@example.com', $account->email);
        // The group id new accounts get comes from the server group config,
        // mirroring FluxCP's LoginServer.GroupID.
        $this->assertSame(0, $account->group_id);
        $this->assertSame(9, $account->character_slots);
        $this->assertSame('1995-04-12', $account->birthdate?->toDateString());

        // rAthena's own id range, not 1.
        $this->assertGreaterThanOrEqual(2_000_000, $account->account_id);
    }

    #[Test]
    public function the_console_command_stores_the_password_in_the_same_format(): void
    {
        $this->artisan('panel:create-account', [
            'username' => 'fluxcp_cli',
            '--email' => 'cli@example.com',
            '--gender' => 'M',
            '--password' => self::PASSWORD,
        ])->assertSuccessful();

        $stored = $this->storedPassword('fluxcp_cli');

        $this->assertSame(self::PASSWORD, $stored);
        $this->assertFalse(Hash::isHashed($stored));
    }

    #[Test]
    public function the_console_command_does_not_echo_the_password(): void
    {
        // A credential printed to a terminal may end up in shell history, a
        // CI log or a screen share.
        $this->artisan('panel:create-account', [
            'username' => 'fluxcp_quiet',
            '--email' => 'quiet@example.com',
            '--password' => self::PASSWORD,
        ])
            ->doesntExpectOutputToContain(self::PASSWORD)
            ->assertSuccessful();
    }

    #[Test]
    public function the_factory_does_not_hash_the_password_either(): void
    {
        // Factories are a creation path too. One that hashed would make every
        // authentication test assert the wrong thing.
        $account = Account::factory()->withPassword(self::PASSWORD)->create();

        $this->assertSame(self::PASSWORD, $this->storedPassword($account->userid));
    }

    #[Test]
    public function the_factory_can_produce_an_md5_credential_for_a_server_that_uses_it(): void
    {
        $account = Account::factory()->withMd5Password(self::PASSWORD)->create();

        $this->assertSame(md5(self::PASSWORD), $this->storedPassword($account->userid));
    }

    #[Test]
    public function two_accounts_created_in_a_row_behave_identically(): void
    {
        $first = $this->service()->create(
            username: 'fluxcp_one',
            password: self::PASSWORD,
            email: 'one@example.com',
            gender: Gender::Male,
        );

        $second = $this->service()->create(
            username: 'fluxcp_two',
            password: self::PASSWORD,
            email: 'two@example.com',
            gender: Gender::Female,
        );

        $this->assertSame(
            $this->storedPassword($first->userid),
            $this->storedPassword($second->userid),
            'The same password must encode identically. rAthena has no per-account salt.',
        );

        $this->assertNotSame($first->account_id, $second->account_id);
    }

    /*
    |--------------------------------------------------------------------------
    | The audit trail (D2)
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function registration_is_audited_without_recording_the_password(): void
    {
        $account = $this->service()->create(
            username: 'fluxcp_audit',
            password: self::PASSWORD,
            email: 'audit@example.com',
            gender: Gender::Male,
            registeredFromIp: '198.51.100.10',
        );

        $row = DB::connection($this->serverGroup()->loginConnection())
            ->table('cp_createlog')
            ->where('account_id', $account->account_id)
            ->first();

        $this->assertNotNull($row, 'The registration should be audited.');
        $this->assertSame('fluxcp_audit', $row->userid);
        $this->assertSame('198.51.100.10', $row->reg_ip);
        // FluxCP wrote the password here. This port does not.
        $this->assertSame('', $row->user_pass);
    }

    #[Test]
    public function a_password_change_is_audited_without_recording_either_password(): void
    {
        $account = $this->service()->create(
            username: 'fluxcp_change',
            password: self::PASSWORD,
            email: 'change@example.com',
            gender: Gender::Male,
        );

        $this->service()->changePassword($account, 'Replacement456!', '198.51.100.11');

        $row = DB::connection($this->serverGroup()->loginConnection())
            ->table('cp_pwchange')
            ->where('account_id', $account->account_id)
            ->first();

        $this->assertNotNull($row);
        $this->assertSame('', $row->old_password);
        $this->assertSame('', $row->new_password);
        $this->assertSame('198.51.100.11', $row->change_ip);
    }

    /*
    |--------------------------------------------------------------------------
    | Validation that protects storage correctness
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function it_rejects_a_username_longer_than_the_column(): void
    {
        // login.userid is varchar(23), matching NAME_LENGTH (23 + 1).
        $this->expectException(ValidationException::class);

        $this->service()->create(
            username: str_repeat('a', 24),
            password: self::PASSWORD,
            email: 'long@example.com',
            gender: Gender::Male,
        );
    }

    #[Test]
    public function it_rejects_a_duplicate_username_case_insensitively_by_default(): void
    {
        // rAthena ships case-insensitive, so allowing both would create two
        // accounts the emulator treats as one.
        $this->service()->create(
            username: 'fluxcp_dupe',
            password: self::PASSWORD,
            email: 'dupe@example.com',
            gender: Gender::Male,
        );

        $this->expectException(ValidationException::class);

        $this->service()->create(
            username: 'FLUXCP_DUPE',
            password: self::PASSWORD,
            email: 'other@example.com',
            gender: Gender::Male,
        );
    }

    #[Test]
    public function it_rejects_a_duplicate_email_unless_the_operator_allows_them(): void
    {
        $this->service()->create(
            username: 'fluxcp_mail1',
            password: self::PASSWORD,
            email: 'shared@example.com',
            gender: Gender::Male,
        );

        try {
            $this->service()->create(
                username: 'fluxcp_mail2',
                password: self::PASSWORD,
                email: 'shared@example.com',
                gender: Gender::Male,
            );
            $this->fail('A duplicate e-mail should have been rejected.');
        } catch (ValidationException) {
            // Expected.
        }

        config(['panel.registration.allow_duplicate_emails' => true]);

        $allowed = $this->service()->create(
            username: 'fluxcp_mail3',
            password: self::PASSWORD,
            email: 'shared@example.com',
            gender: Gender::Male,
        );

        $this->assertSame('fluxcp_mail3', $allowed->userid);
    }

    #[Test]
    public function it_applies_the_configured_password_complexity_policy(): void
    {
        config([
            'panel.registration.password.min_uppercase' => 1,
            'panel.registration.password.min_numbers' => 1,
            'panel.registration.password.min_symbols' => 1,
        ]);

        $this->expectException(ValidationException::class);

        $this->service()->create(
            username: 'fluxcp_weak',
            password: 'alllowercase',
            email: 'weak@example.com',
            gender: Gender::Male,
        );
    }

    #[Test]
    public function it_rejects_a_password_containing_the_account_name(): void
    {
        $this->expectException(ValidationException::class);

        $this->service()->create(
            username: 'merchant',
            password: 'Merchant123!',
            email: 'merchant@example.com',
            gender: Gender::Male,
        );
    }

    #[Test]
    public function nothing_is_written_when_validation_fails(): void
    {
        $before = DB::connection($this->serverGroup()->loginConnection())->table('login')->count();

        try {
            $this->service()->create(
                username: 'x',
                password: 'short',
                email: 'not-an-email',
                gender: Gender::Male,
            );
        } catch (ValidationException) {
            // Expected.
        }

        $this->assertSame(
            $before,
            DB::connection($this->serverGroup()->loginConnection())->table('login')->count(),
            'A rejected registration must not leave a partial account behind.',
        );
    }
}
