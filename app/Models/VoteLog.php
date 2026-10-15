<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * One vote: a row of `vote_log`.
 *
 * Written when a player presses Vote and completed when the listing site
 * calls back. See the migration and config/vote.php.
 *
 * @property int $id
 * @property int|null $account_id
 * @property string $site
 * @property string $reference
 * @property int $credits
 * @property CarbonImmutable|null $claimed_at
 * @property string|null $started_ip
 * @property string|null $voter_ip
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
final class VoteLog extends Model
{
    protected $table = 'vote_log';

    /** @var list<string> */
    protected $fillable = [
        'account_id',
        'site',
        'reference',
        'credits',
        'claimed_at',
        'started_ip',
        'voter_ip',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'credits' => 'integer',
            'claimed_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
