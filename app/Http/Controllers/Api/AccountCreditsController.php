<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Enums\Gender;
use App\Models\Account;
use App\Models\Character;
use App\Support\Http\ListQuery;
use App\Support\Rathena\ServerRegistry;
use Illuminate\Database\ConnectionResolverInterface;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

/**
 * Gender change, credit transfer, and the transfer history.
 *
 * Ports modules/account/changesex.php, transfer.php and xferlog.php.
 */
final class AccountCreditsController
{
    public function __construct(
        private readonly ConnectionResolverInterface $connections,
        private readonly ServerRegistry $servers,
    ) {}

    /**
     * Change the account's gender.
     *
     * rAthena stores gender on the *account*, not the character, so this moves
     * every character on it at once -- which is why the gender-linked classes
     * have to be checked first.
     *
     * @throws ValidationException
     */
    public function changeGender(Request $request): JsonResponse
    {
        $account = $this->account($request);

        $cost = max(0, (int) config('panel.characters.gender_change_cost', 0));
        $free = $cost === 0 || $account->can('AvoidSexChangeCost');

        /*
         * Bard, Dancer and everything they become exist for one gender only.
         * Changing the account's gender would leave the character as a class
         * its new gender cannot be, which the client renders as an invisible
         * or crashing character -- so it is refused rather than warned about.
         */
        $linked = array_keys((array) config('rathena_reference.gender_linked_classes', []));

        $blocking = Character::query()
            ->where('account_id', $account->account_id)
            ->whereIn('class', $linked)
            ->pluck('name')
            ->all();

        if ($blocking !== []) {
            return response()->json([
                'message' => 'This account has characters whose job exists for one gender only: '
                    .implode(', ', $blocking)
                    .'. Change or delete them first.',
                'blocking_characters' => $blocking,
            ], 422);
        }

        $connection = $this->connections->connection($this->servers->current()->loginConnection());

        if (! $free) {
            $balance = (int) ($connection->table('cp_credits')
                ->where('account_id', $account->account_id)
                ->value('balance') ?? 0);

            if ($balance < $cost) {
                return response()->json([
                    'message' => "A gender change costs {$cost} credits and you have {$balance}.",
                ], 422);
            }
        }

        $current = $account->sex;

        if (! $current->isPlayer()) {
            abort(422, 'This account cannot change gender.');
        }

        $next = $current === Gender::Male ? Gender::Female : Gender::Male;

        $connection->transaction(function () use ($connection, $account, $next, $cost, $free): void {
            $connection->table('login')
                ->where('account_id', $account->account_id)
                ->update(['sex' => $next->value]);

            if (! $free) {
                /*
                 * Conditional, for the same reason the shop's checkout is:
                 * the database decides whether the balance covers it.
                 */
                $deducted = $connection->table('cp_credits')
                    ->where('account_id', $account->account_id)
                    ->where('balance', '>=', $cost)
                    ->update(['balance' => $connection->raw("balance - {$cost}")]);

                abort_if($deducted === 0, 422, 'Your balance changed. Please try again.');
            }

            // The legacy counted these in cp_loginprefs; kept, because an
            // operator charging for the change wants to see who repeats it.
            $connection->table('cp_loginprefs')->updateOrInsert(
                ['account_id' => $account->account_id, 'name' => 'NumberOfGenderChanges'],
                ['value' => $connection->raw('COALESCE(value, 0) + 1')],
            );
        });

        $account->refresh();

        return response()->json([
            'message' => $free
                ? 'Your gender has been changed.'
                : "Your gender has been changed, for {$cost} credits.",
            'data' => ['gender' => $next->value],
        ]);
    }

    /**
     * Send credits to another player.
     *
     * @throws ValidationException
     */
    public function transfer(Request $request): JsonResponse
    {
        $account = $this->account($request);

        if (config('panel.characters.credit_transfer.enabled') !== true) {
            return response()->json(['message' => 'Credit transfers are turned off.'], 403);
        }

        $max = (int) config('panel.characters.credit_transfer.max_per_transfer', 0);

        $validated = $request->validate([
            'character' => ['required', 'string', 'max:30'],
            'credits' => array_filter([
                'required', 'integer', 'min:1',
                $max > 0 ? "max:{$max}" : null,
            ]),
        ]);

        $recipient = Character::query()
            ->where('name', $validated['character'])
            ->first();

        abort_if($recipient === null, 422, 'There is no character with that name.');

        abort_if(
            (int) $recipient->account_id === $account->account_id,
            422,
            'That character is on your own account.',
        );

        $credits = (int) $validated['credits'];
        $connection = $this->connections->connection($this->servers->current()->loginConnection());

        $target = Account::query()->find($recipient->account_id);

        abort_if($target === null, 422, 'That character has no account.');

        $connection->transaction(function () use ($connection, $account, $target, $recipient, $credits): void {
            $deducted = $connection->table('cp_credits')
                ->where('account_id', $account->account_id)
                ->where('balance', '>=', $credits)
                ->update(['balance' => $connection->raw("balance - {$credits}")]);

            abort_if($deducted === 0, 422, 'You do not have that many credits.');

            $connection->table('cp_credits')->updateOrInsert(
                ['account_id' => $target->account_id],
                [],
            );

            $connection->table('cp_credits')
                ->where('account_id', $target->account_id)
                ->update(['balance' => $connection->raw("balance + {$credits}")]);

            /*
             * Both sides of the transfer are recorded in one row, which is
             * what makes the history readable from either end.
             */
            $connection->table('cp_xferlog')->insert([
                'from_account_id' => $account->account_id,
                'target_account_id' => $target->account_id,
                'target_char_id' => $recipient->char_id,
                'amount' => $credits,
                'for_free' => 0,
                'transfer_date' => now(),
            ]);
        });

        return response()->json([
            'message' => "{$credits} credits sent to {$recipient->name}.",
        ]);
    }

    /**
     * Transfers this account sent or received.
     *
     * @throws ValidationException
     */
    public function transferHistory(Request $request): JsonResponse
    {
        $account = $this->account($request);

        $query = $this->connections
            ->connection($this->servers->current()->loginConnection())
            ->table('cp_xferlog')
            ->select('from_account_id', 'target_account_id', 'target_char_id', 'amount', 'transfer_date')
            // Either end: the history is the account's, not the sender's.
            ->where(fn ($q) => $q
                ->where('from_account_id', $account->account_id)
                ->orWhere('target_account_id', $account->account_id));

        $list = new ListQuery(
            sortable: ['date' => 'transfer_date', 'amount' => 'amount'],
            defaultSort: 'date',
        );

        $page = $list->paginate($query, $request);

        $characterNames = Character::query()
            ->whereIn('char_id', array_map(
                fn (object $row): int => (int) $row->target_char_id,
                $page->items(),
            ))
            ->pluck('name', 'char_id');

        return response()->json([
            'data' => array_map(fn (object $row): array => [
                'direction' => (int) $row->from_account_id === $account->account_id
                    ? 'sent'
                    : 'received',
                'amount' => (int) $row->amount,
                'to_character' => $characterNames[(int) $row->target_char_id] ?? null,
                'at' => $this->iso($row->transfer_date),
            ], $page->items()),
            'meta' => [
                ...$list->metadata($request),
                'total' => $page->total(),
                'per_page' => $page->perPage(),
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
            ],
        ]);
    }

    private function account(Request $request): Account
    {
        $account = $request->user();

        abort_unless($account instanceof Account, 401);

        return $account;
    }

    private function iso(mixed $value): ?string
    {
        if ($value === null || $value === '' || str_starts_with((string) $value, '0000-00-00')) {
            return null;
        }

        return Carbon::parse((string) $value)->toIso8601String();
    }
}
