<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\UsesCharMapConnection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A single per-character preference: a row of `cp_charprefs`.
 *
 * Panel-owned, kept in the char/map database where the legacy installer put
 * it so it can be joined against `char`. Like the account equivalent it is an
 * open key/value store.
 *
 * @property int $id
 * @property int $account_id
 * @property int $char_id
 * @property string $name
 * @property string $value
 */
final class CharacterPreference extends Model
{
    use UsesCharMapConnection;

    protected $table = 'cp_charprefs';

    public $timestamps = false;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'account_id',
        'char_id',
        'name',
        'value',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'account_id' => 'integer',
            'char_id' => 'integer',
            'create_date' => 'immutable_datetime',
        ];
    }

    /**
     * @return BelongsTo<Character, $this>
     */
    public function character(): BelongsTo
    {
        return $this->belongsTo(Character::class, 'char_id', 'char_id');
    }
}
