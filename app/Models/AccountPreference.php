<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\UsesLoginConnection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A single per-account preference: a row of `cp_loginprefs`.
 *
 * The legacy schema is an open key/value store rather than a column per
 * setting, which is why preferences are read and written as a set.
 *
 * @property int $id
 * @property int $account_id
 * @property string $name
 * @property string $value
 */
final class AccountPreference extends Model
{
    use UsesLoginConnection;

    protected $table = 'cp_loginprefs';

    public $timestamps = false;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'account_id',
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
            'create_date' => 'immutable_datetime',
        ];
    }

    /**
     * @return BelongsTo<Account, $this>
     */
    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'account_id', 'account_id');
    }
}
