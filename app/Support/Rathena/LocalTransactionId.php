<?php

declare(strict_types=1);

namespace App\Support\Rathena;

use InvalidArgumentException;

/**
 * Transaction ids for credit movements the panel makes itself.
 *
 * `cp_txnlog.txn_id` is `varchar(20)`, because FluxCP sized it for a PayPal
 * transaction id and PayPal's are 17 characters. Anything the panel writes
 * there has to fit in the same twenty.
 *
 * ---------------------------------------------------------------------------
 * Why this is a class and not a string concatenation
 * ---------------------------------------------------------------------------
 *
 * The rAthena connections run with `strict` off, which config/rathena.php
 * explains: the emulator's schema predates strict mode and uses zero dates
 * and out-of-range defaults that strict MySQL rejects. So an over-length id
 * is not an error -- MySQL truncates it and the insert succeeds. That is the
 * hazard, because what gets truncated away is the random part.
 *
 * It had already happened: the admin balance adjustment wrote `manual-` plus
 * sixteen hex characters, which is twenty-three, so three characters of
 * randomness were being silently discarded. Harmless at that length, and not
 * harmless at the next one -- a `servicedesk-` prefix would have left eight
 * hex characters, which is 32 bits and few enough to collide on a busy
 * server. Asking for the id here means the budget is checked rather than
 * assumed, and a prefix that leaves too little room fails loudly instead of
 * quietly handing out short ids.
 */
final readonly class LocalTransactionId
{
    /** The width of `cp_txnlog.txn_id`. */
    public const MAX_LENGTH = 20;

    /**
     * A unique id for a locally generated transaction.
     *
     * @param  string  $prefix  A short tag identifying what made the movement,
     *                          which appears in the id so a row is
     *                          recognisable without joining anything.
     */
    public static function for(string $prefix): string
    {
        $prefix = rtrim($prefix, '-').'-';
        $budget = self::MAX_LENGTH - strlen($prefix);

        if ($budget < 8) {
            throw new InvalidArgumentException(
                "A transaction prefix of '{$prefix}' leaves only {$budget} characters for "
                .'uniqueness, which is not enough to avoid collisions. Use a shorter prefix.'
            );
        }

        /*
         * Random rather than sequential: the column has no unique constraint
         * in FluxCP's schema, so a counter would need a read to continue, and
         * two panels pointed at one database would hand out the same numbers.
         */
        return $prefix.substr(bin2hex(random_bytes((int) ceil($budget / 2))), 0, $budget);
    }
}
