<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\UsesCharMapConnection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Guild membership: a row of `guild_member`.
 *
 * The table has a composite natural key (guild_id, char_id) and no surrogate
 * id, so it is addressed through its relations rather than by key.
 *
 * `position` is an index into `guild_position` for the same guild, not a
 * global role id.
 *
 * @property int $guild_id
 * @property int $char_id
 * @property int $exp
 * @property int $position
 */
final class GuildMember extends Model
{
    use UsesCharMapConnection;

    protected $table = 'guild_member';

    public $timestamps = false;

    public $incrementing = false;

    /**
     * @var list<string>
     */
    protected $fillable = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'guild_id' => 'integer',
            'char_id' => 'integer',
            'exp' => 'integer',
            'position' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<Guild, $this>
     */
    public function guild(): BelongsTo
    {
        return $this->belongsTo(Guild::class, 'guild_id', 'guild_id');
    }

    /**
     * @return BelongsTo<Character, $this>
     */
    public function character(): BelongsTo
    {
        return $this->belongsTo(Character::class, 'char_id', 'char_id');
    }
}
