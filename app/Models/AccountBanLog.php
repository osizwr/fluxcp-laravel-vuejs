<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\BanType;
use App\Models\Concerns\UsesLoginConnection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An entry in the account ban history: a row of `cp_banlog`.
 *
 * Append-only. A lift is a new row with ban_type Lifted, so the history shows
 * who banned an account, who lifted it, and why, in order.
 *
 * @property int $id
 * @property int $account_id
 * @property int $banned_by
 * @property BanType $ban_type
 * @property string|null $ban_reason
 */
final class AccountBanLog extends Model
{
    use UsesLoginConnection;

    protected $table = 'cp_banlog';

    public $timestamps = false;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'account_id',
        'banned_by',
        'ban_type',
        'ban_until',
        'ban_date',
        'ban_reason',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'account_id' => 'integer',
            'banned_by' => 'integer',
            'ban_type' => BanType::class,
            'ban_until' => 'immutable_datetime',
            'ban_date' => 'immutable_datetime',
        ];
    }

    /**
     * @return BelongsTo<Account, $this>
     */
    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'account_id', 'account_id');
    }

    /**
     * The staff account that recorded this entry.
     *
     * @return BelongsTo<Account, $this>
     */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'banned_by', 'account_id');
    }
}
