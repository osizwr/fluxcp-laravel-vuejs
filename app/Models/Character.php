<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\Gender;
use App\Models\Concerns\UsesCharMapConnection;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A character: a row of rAthena's `char` table.
 *
 * Owned by the emulator. 80 columns wide, no timestamps, and several column
 * names collide with SQL or PHP keywords (`class`, `option`, `int`, `rename`),
 * so they are addressed through accessors where that would otherwise read
 * badly.
 *
 * Note that `char` is a reserved word in MySQL and must stay quoted; Eloquent's
 * grammar does that automatically, but raw SQL elsewhere must not forget it.
 *
 * @property int $char_id
 * @property int $account_id
 * @property int $char_num
 * @property string $name
 * @property int $class
 * @property int $base_level
 * @property int $job_level
 * @property int $base_exp
 * @property int $job_exp
 * @property int $zeny
 * @property int $party_id
 * @property int $guild_id
 * @property int $partner_id
 * @property bool $online
 * @property string $last_map
 * @property int $delete_date
 * @property CarbonImmutable|null $last_login
 */
final class Character extends Model
{
    /** @use HasFactory<\Database\Factories\CharacterFactory> */
    use HasFactory;
    use UsesCharMapConnection;

    protected $table = 'char';

    protected $primaryKey = 'char_id';

    public $timestamps = false;

    /**
     * Character rows are the game's state, not the panel's. Every write the
     * panel performs is a specific, audited operation (slot change, look
     * reset, position reset, divorce), so nothing is mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'char_id' => 'integer',
            'account_id' => 'integer',
            'char_num' => 'integer',
            'class' => 'integer',
            'base_level' => 'integer',
            'job_level' => 'integer',
            'base_exp' => 'integer',
            'job_exp' => 'integer',
            'zeny' => 'integer',
            'party_id' => 'integer',
            'guild_id' => 'integer',
            'pet_id' => 'integer',
            'homun_id' => 'integer',
            'clan_id' => 'integer',
            'partner_id' => 'integer',
            'father' => 'integer',
            'mother' => 'integer',
            'child' => 'integer',
            'fame' => 'integer',
            'online' => 'boolean',
            'sex' => Gender::class,
            'delete_date' => 'integer',
            'unban_time' => 'integer',
            'last_login' => 'immutable_datetime',
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | Relations
    |--------------------------------------------------------------------------
    */

    /**
     * @return BelongsTo<Account, $this>
     */
    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'account_id', 'account_id');
    }

    /**
     * The character's guild, if any.
     *
     * guild_id is 0 rather than null when the character is guildless, so the
     * relation is constrained to avoid a pointless lookup for id 0.
     *
     * @return BelongsTo<Guild, $this>
     */
    public function guild(): BelongsTo
    {
        return $this->belongsTo(Guild::class, 'guild_id', 'guild_id');
    }

    /**
     * @return BelongsTo<self, $this>
     */
    public function partner(): BelongsTo
    {
        return $this->belongsTo(self::class, 'partner_id', 'char_id');
    }

    /**
     * @return HasMany<CharacterPreference, $this>
     */
    public function preferences(): HasMany
    {
        return $this->hasMany(CharacterPreference::class, 'char_id', 'char_id');
    }

    /*
    |--------------------------------------------------------------------------
    | Scopes
    |--------------------------------------------------------------------------
    */

    /**
     * Exclude characters queued for deletion.
     *
     * rAthena does not remove a character immediately: `delete_date` is set to
     * the Unix time at which the deletion becomes final, and remains 0 for a
     * live character. A listing that ignores this shows characters the player
     * believes they have deleted.
     *
     * @param  Builder<$this>  $query
     */
    public function scopeNotDeleted(Builder $query): void
    {
        $query->where(function (Builder $query): void {
            $query->where('delete_date', 0)->orWhereNull('delete_date');
        });
    }

    /**
     * @param  Builder<$this>  $query
     */
    public function scopeOnline(Builder $query): void
    {
        $query->where('online', 1);
    }

    /**
     * @param  Builder<$this>  $query
     */
    public function scopeInGuild(Builder $query): void
    {
        $query->where('guild_id', '>', 0);
    }

    /*
    |--------------------------------------------------------------------------
    | Derived state
    |--------------------------------------------------------------------------
    */

    public function isMarried(): bool
    {
        return $this->partner_id > 0;
    }

    public function isInGuild(): bool
    {
        return $this->guild_id > 0;
    }

    public function isInParty(): bool
    {
        return $this->party_id > 0;
    }

    public function isPendingDeletion(): bool
    {
        return $this->delete_date > 0;
    }

    public function deletionBecomesFinalAt(): ?CarbonImmutable
    {
        return $this->delete_date > 0
            ? CarbonImmutable::createFromTimestamp($this->delete_date)
            : null;
    }

    /**
     * rAthena's job/class identifier. Exposed under a clearer name because
     * `$character->class` reads like a PHP class and is easy to misread.
     */
    public function jobId(): int
    {
        return $this->class;
    }
}
