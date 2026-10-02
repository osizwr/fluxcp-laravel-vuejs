<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\UsesLoginConnection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An account's item-shop credit balance: a row of `cp_credits`.
 *
 * Panel-owned, but deliberately kept in the login database where the legacy
 * installer put it, because it is joined against `login` directly. See
 * docs/MIGRATION_DECISIONS.md (D4).
 *
 * The table has no surrogate key -- account_id is the primary key -- and no
 * timestamps.
 *
 * @property int $account_id
 * @property int $balance
 */
final class AccountCredit extends Model
{
    use UsesLoginConnection;

    protected $table = 'cp_credits';

    protected $primaryKey = 'account_id';

    public $incrementing = false;

    public $timestamps = false;

    /**
     * Balances are only ever moved by the credit service, which does so in a
     * transaction with an audit row, so nothing here is mass assignable.
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
            'account_id' => 'integer',
            'balance' => 'integer',
            'last_donation_date' => 'immutable_datetime',
            'last_donation_amount' => 'float',
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
