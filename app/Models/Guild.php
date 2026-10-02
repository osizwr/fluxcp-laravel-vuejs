<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\UsesCharMapConnection;
use Database\Factories\GuildFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A guild: a row of rAthena's `guild` table.
 *
 * `emblem_data` holds a raw BMP blob uploaded from the game client, and
 * `emblem_len` its byte length. It is never exposed directly -- the emblem
 * endpoint converts it to a web image format, because serving a
 * client-supplied blob straight back to a browser is how image decoders get
 * attacked.
 *
 * @property int $guild_id
 * @property string $name
 * @property int $char_id Guild master's character id.
 * @property string $master Guild master's character name, denormalised.
 * @property int $guild_lv
 * @property int $connect_member
 * @property int $max_member
 * @property int $average_lv
 * @property int $exp
 * @property string $mes1
 * @property string $mes2
 * @property int $emblem_len
 * @property int $emblem_id
 */
final class Guild extends Model
{
    /** @use HasFactory<GuildFactory> */
    use HasFactory;

    use UsesCharMapConnection;

    protected $table = 'guild';

    protected $primaryKey = 'guild_id';

    public $timestamps = false;

    /**
     * @var list<string>
     */
    protected $fillable = [];

    /**
     * The emblem blob is excluded from serialisation: it is binary, often
     * tens of kilobytes, and would corrupt a JSON response.
     *
     * @var list<string>
     */
    protected $hidden = [
        'emblem_data',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'guild_id' => 'integer',
            'char_id' => 'integer',
            'guild_lv' => 'integer',
            'connect_member' => 'integer',
            'max_member' => 'integer',
            'average_lv' => 'integer',
            'exp' => 'integer',
            'next_exp' => 'integer',
            'skill_point' => 'integer',
            'emblem_len' => 'integer',
            'emblem_id' => 'integer',
            'last_master_change' => 'immutable_datetime',
        ];
    }

    /**
     * @return BelongsTo<Character, $this>
     */
    public function leader(): BelongsTo
    {
        return $this->belongsTo(Character::class, 'char_id', 'char_id');
    }

    /**
     * @return HasMany<GuildMember, $this>
     */
    public function members(): HasMany
    {
        return $this->hasMany(GuildMember::class, 'guild_id', 'guild_id');
    }

    /**
     * @return HasMany<Character, $this>
     */
    public function characters(): HasMany
    {
        return $this->hasMany(Character::class, 'guild_id', 'guild_id');
    }

    public function hasEmblem(): bool
    {
        return $this->emblem_len > 0;
    }

    /**
     * @param  Builder<$this>  $query
     */
    public function scopeWithEmblem(Builder $query): void
    {
        $query->where('emblem_len', '>', 0);
    }
}
