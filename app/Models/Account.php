<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\AccountLevel;
use App\Enums\AccountState;
use App\Enums\Gender;
use App\Models\Concerns\UsesLoginConnection;
use App\Support\Authorization\PermissionRegistry;
use App\Support\Rathena\ServerRegistry;
use Carbon\CarbonImmutable;
use Database\Factories\AccountFactory;
use Illuminate\Contracts\Auth\Access\Authorizable as AuthorizableContract;
use Illuminate\Contracts\Auth\Authenticatable as AuthenticatableContract;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\Access\Authorizable;

/**
 * An rAthena account: a row of the `login` table.
 *
 * This table is owned by the emulator, not by the panel. It has no timestamps,
 * its primary key is `account_id`, and `user_pass` holds the credential the
 * login server itself authenticates against -- which is why the panel never
 * re-hashes it. See docs/MIGRATION_DECISIONS.md (D1).
 *
 * @property int $account_id
 * @property string $userid
 * @property Gender $sex
 * @property string $email
 * @property int $group_id
 * @property int $state
 * @property int $unban_time
 * @property int $expiration_time
 * @property int $logincount
 * @property CarbonImmutable|null $lastlogin
 * @property string $last_ip
 * @property CarbonImmutable|null $birthdate
 * @property int $character_slots
 * @property int $vip_time
 */
final class Account extends Model implements AuthenticatableContract, AuthorizableContract
{
    /**
     * Authorizable gives the model can()/cannot(), which resolve through the
     * gates registered from the ability map. Without it every $user->can()
     * check in a resource or middleware would be a fatal error rather than a
     * denial, which is a failure mode worth not having in the authorisation
     * path.
     */
    use Authorizable;

    /** @use HasFactory<AccountFactory> */
    use HasFactory;

    use UsesLoginConnection;

    protected $table = 'login';

    protected $primaryKey = 'account_id';

    /**
     * rAthena's schema has no created_at/updated_at on this table.
     */
    public $timestamps = false;

    /**
     * Only ever written through explicit, intention-named services, so no
     * attribute is mass assignable. The credential column is not listed here
     * at all -- it is written solely by the account credential service.
     *
     * @var list<string>
     */
    protected $fillable = [];

    /**
     * @var list<string>
     */
    protected $hidden = [
        'user_pass',
        'pincode',
        'web_auth_token',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'account_id' => 'integer',
            'sex' => Gender::class,
            'group_id' => 'integer',
            'state' => 'integer',
            'unban_time' => 'integer',
            'expiration_time' => 'integer',
            'logincount' => 'integer',
            'character_slots' => 'integer',
            'vip_time' => 'integer',
            'old_group' => 'integer',
            'pincode_change' => 'integer',
            'web_auth_token_enabled' => 'boolean',
            'lastlogin' => 'immutable_datetime',
            'birthdate' => 'immutable_date',
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | Relations
    |--------------------------------------------------------------------------
    */

    /**
     * @return HasMany<Character, $this>
     */
    public function characters(): HasMany
    {
        return $this->hasMany(Character::class, 'account_id', 'account_id');
    }

    /**
     * @return HasOne<AccountCredit, $this>
     */
    public function credit(): HasOne
    {
        return $this->hasOne(AccountCredit::class, 'account_id', 'account_id');
    }

    /**
     * @return HasMany<AccountBanLog, $this>
     */
    public function banLogs(): HasMany
    {
        return $this->hasMany(AccountBanLog::class, 'account_id', 'account_id');
    }

    /**
     * @return HasMany<AccountPreference, $this>
     */
    public function preferences(): HasMany
    {
        return $this->hasMany(AccountPreference::class, 'account_id', 'account_id');
    }

    /*
    |--------------------------------------------------------------------------
    | Scopes
    |--------------------------------------------------------------------------
    */

    /**
     * Restrict to rows that represent an actual player.
     *
     * rAthena marks its own inter-server accounts with sex = 'S', and uses a
     * negative group_id to disable an account outright. The legacy panel
     * applied exactly this pair of conditions everywhere it touched the login
     * table, and omitting either would expose server accounts through the
     * panel.
     *
     * @param  Builder<$this>  $query
     */
    public function scopePlayers(Builder $query): void
    {
        $query->where('sex', '!=', Gender::Server->value)
            ->where('group_id', '>=', 0);
    }

    /**
     * Match an account name, honouring the server's case sensitivity.
     *
     * rAthena may be configured either way. When it is case-sensitive the
     * comparison must be forced to a binary one, because the column's own
     * collation is usually case-insensitive and would otherwise let someone
     * sign in as "Admin" when the account is "admin".
     *
     * @param  Builder<$this>  $query
     */
    public function scopeMatchingUserid(Builder $query, string $userid): void
    {
        $caseSensitive = app(ServerRegistry::class)->current()->loginServer->caseSensitive;

        if ($caseSensitive) {
            $query->whereRaw('CAST(userid AS BINARY) = ?', [$userid]);

            return;
        }

        $query->whereRaw('LOWER(userid) = LOWER(?)', [$userid]);
    }

    /*
    |--------------------------------------------------------------------------
    | Privilege
    |--------------------------------------------------------------------------
    */

    /**
     * The panel privilege level this account holds, derived from its rAthena
     * group_id through the configured mapping.
     */
    public function accountLevel(): AccountLevel
    {
        return app(PermissionRegistry::class)->levelForGroupId($this->group_id);
    }

    public function groupName(): string
    {
        return app(PermissionRegistry::class)->nameForGroupId($this->group_id);
    }

    public function isStaff(): bool
    {
        return $this->accountLevel()->isStaff();
    }

    /**
     * Whether this account may act on $other.
     *
     * Staff of equal or greater seniority are protected from each other
     * unless the acting account holds the explicit override. This reproduces
     * the legacy EditHigherPower / BanHigherPower checks, which both default
     * to Noone, meaning nobody may touch a peer.
     */
    public function outranks(self $other): bool
    {
        return $this->accountLevel()->value > $other->accountLevel()->value;
    }

    /*
    |--------------------------------------------------------------------------
    | State
    |--------------------------------------------------------------------------
    */

    public function state(): ?AccountState
    {
        return AccountState::tryFrom($this->state);
    }

    public function isPermanentlyBanned(): bool
    {
        return $this->state === AccountState::PermanentlyBanned->value;
    }

    /**
     * Whether a temporary ban is currently in force.
     *
     * `unban_time` is a Unix timestamp, and 0 means "not banned". A value in
     * the past means the ban has lapsed but nothing has cleared it yet, which
     * the authentication flow does on the account's next sign-in attempt.
     */
    public function isTemporarilyBanned(?CarbonImmutable $at = null): bool
    {
        if ($this->unban_time <= 0) {
            return false;
        }

        return ($at ?? CarbonImmutable::now())->getTimestamp() < $this->unban_time;
    }

    public function temporaryBanExpiresAt(): ?CarbonImmutable
    {
        return $this->unban_time > 0
            ? CarbonImmutable::createFromTimestamp($this->unban_time)
            : null;
    }

    /**
     * Whether the account has an expiry date that has passed. rAthena uses
     * this for subscription-style servers; 0 means no expiry.
     */
    public function hasExpired(?CarbonImmutable $at = null): bool
    {
        if ($this->expiration_time <= 0) {
            return false;
        }

        return ($at ?? CarbonImmutable::now())->getTimestamp() >= $this->expiration_time;
    }

    public function isVip(?CarbonImmutable $at = null): bool
    {
        return $this->vip_time > 0
            && ($at ?? CarbonImmutable::now())->getTimestamp() < $this->vip_time;
    }

    /*
    |--------------------------------------------------------------------------
    | Authenticatable
    |--------------------------------------------------------------------------
    |
    | The panel's credential of record is not login.user_pass but a hash in
    | the application's own panel_credentials table, so getAuthPassword()
    | returns that. See App\Services\Auth\AccountUserProvider for how the two
    | are reconciled, and D1 for why.
    |
    */

    public function getAuthIdentifierName(): string
    {
        return 'account_id';
    }

    public function getAuthIdentifier(): int
    {
        return $this->account_id;
    }

    public function getAuthPassword(): string
    {
        return $this->panelCredential?->password_hash ?? '';
    }

    public function getAuthPasswordName(): string
    {
        return 'password_hash';
    }

    public function getRememberToken(): ?string
    {
        return $this->panelCredential?->remember_token;
    }

    public function setRememberToken($value): void
    {
        PanelCredential::query()->updateOrCreate(
            $this->panelCredentialKey(),
            ['remember_token' => $value],
        );

        $this->unsetRelation('panelCredential');
    }

    public function getRememberTokenName(): string
    {
        return 'remember_token';
    }

    /*
    |--------------------------------------------------------------------------
    | Panel-owned credential
    |--------------------------------------------------------------------------
    */

    /**
     * The panel's own hashed credential for this account.
     *
     * This row lives in the application database rather than rAthena's, and
     * is keyed by server group as well as account id, because the same
     * account_id may exist in several unrelated server groups. The relation
     * resolves across connections because Eloquent runs it as a separate
     * query on the related model's own connection.
     *
     * @return HasOne<PanelCredential, $this>
     */
    public function panelCredential(): HasOne
    {
        return $this->hasOne(PanelCredential::class, 'account_id', 'account_id')
            ->where('server_group', app(ServerRegistry::class)->current()->key);
    }

    /**
     * @return array{server_group: string, account_id: int}
     */
    public function panelCredentialKey(): array
    {
        return [
            'server_group' => app(ServerRegistry::class)->current()->key,
            'account_id' => $this->account_id,
        ];
    }
}
