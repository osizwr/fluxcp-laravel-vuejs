<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Models\Account;
use App\Services\Donations\DonationService;
use App\Services\Donations\PaymentNotification;
use App\Support\Http\ListQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

/**
 * Donations.
 *
 * Ports modules/donate/index.php, notify.php, complete.php, history.php,
 * trusted.php and update.php.
 *
 * The notification endpoint is the only unauthenticated write in this
 * application, because the provider calls it rather than a person. Everything
 * it believes is checked first; see PaymentNotification.
 */
final class DonationController
{
    public function __construct(
        private readonly DonationService $donations,
        private readonly PaymentNotification $notifications,
    ) {}

    /**
     * What a donation is worth, and where to send it.
     *
     * Only the public half of the configuration: the business address the form
     * posts to, the currency, and the rate. Nothing here would be a problem in
     * a view-source.
     */
    public function index(Request $request): JsonResponse
    {
        $account = $request->user();

        if (config('panel.donations.enabled') !== true) {
            return response()->json([
                'data' => ['enabled' => false],
                'message' => 'This server is not accepting donations at the moment.',
            ]);
        }

        return response()->json([
            'data' => [
                'enabled' => true,
                'payment_url' => (string) config('panel.donations.payment_url'),
                'business_email' => (string) config('panel.donations.business_email'),
                'currency' => (string) config('panel.donations.currency'),
                'credits_per_unit' => (float) config('panel.donations.credits_per_unit'),
                'minimum_amount' => (float) config('panel.donations.minimum_amount'),
                /*
                 * The provider needs something to hand back identifying the
                 * payer. The account id is enough and is not a secret -- but
                 * the notification handler still looks the account up rather
                 * than trusting whatever comes back.
                 */
                'reference' => $account instanceof Account ? (string) $account->account_id : null,
                'balance' => $account instanceof Account
                    ? $this->balance($account)
                    : null,
            ],
        ]);
    }

    /**
     * Where the payer is returned to after paying.
     *
     * Deliberately does *not* credit anything. A return URL is a redirect in
     * the payer's browser and proves nothing -- anybody can visit it. Credits
     * are added only by the verified notification, which may arrive before or
     * after this.
     */
    public function complete(Request $request): JsonResponse
    {
        $account = $request->user();

        return response()->json([
            'message' => 'Thank you. Your credits appear once the payment is confirmed, '
                .'which is usually immediate but can take a few minutes.',
            'data' => [
                'balance' => $account instanceof Account ? $this->balance($account) : null,
            ],
        ]);
    }

    /**
     * The provider's payment notification.
     *
     * Unauthenticated, because the provider calls it. Always answers 200: a
     * non-2xx makes the provider retry, and a notification this refuses on
     * purpose should not be retried forever.
     */
    public function notify(Request $request): Response
    {
        $payload = $request->all();

        if (config('panel.donations.enabled') !== true) {
            $this->donations->refuse('Donations are not enabled.', $payload);

            return response('', 200);
        }

        // 1. Did the provider actually send this?
        if (! $this->notifications->isVerified($payload)) {
            $this->donations->refuse('The notification could not be verified.', $payload);

            return response('', 200);
        }

        // 2. Has it been seen before? Providers resend by design.
        $transactionId = (string) ($payload['txn_id'] ?? '');

        if ($this->donations->alreadyProcessed($transactionId)) {
            $this->donations->refuse('Already processed.', $payload);

            return response('', 200);
        }

        $account = $this->accountFor($payload);

        // 3. Did the money arrive somewhere this server owns, in the right
        //    currency, and did it complete?
        $refusal = match (true) {
            ! $this->notifications->isOurReceiver($payload['receiver_email'] ?? null) => 'The payment was made to an address this server does not own.',
            ! $this->notifications->isExpectedCurrency($payload['mc_currency'] ?? null) => 'The payment is in an unexpected currency.',
            ! $this->notifications->isCompleted($payload['payment_status'] ?? null) => 'The payment has not completed.',
            $account === null => 'The payment does not name an account on this server.',
            default => null,
        };

        if ($refusal !== null) {
            /*
             * Recorded without crediting. A payment that arrived but could not
             * be matched is money somebody sent, and an administrator needs to
             * see it to sort it out by hand.
             */
            $this->donations->refuse($refusal, $payload);
            $this->donations->record($account, $payload, credit: false);

            return response('', 200);
        }

        $this->donations->record($account, $payload, credit: true);

        return response('', 200);
    }

    /**
     * The signed-in account's own donations.
     *
     * @throws ValidationException
     */
    public function history(Request $request): JsonResponse
    {
        $account = $this->account($request);

        $list = new ListQuery(
            sortable: ['date' => 'process_date', 'amount' => 'mc_gross'],
            defaultSort: 'date',
        );

        $page = $list->paginate($this->donations->historyFor($account), $request);

        return response()->json([
            'data' => array_map(fn (object $row): array => [
                'transaction_id' => (string) ($row->txn_id ?? ''),
                'amount' => (string) ($row->mc_gross ?? ''),
                'currency' => (string) ($row->mc_currency ?? ''),
                'credits' => (int) ($row->credits ?? 0),
                'status' => (string) ($row->payment_status ?? ''),
                'at' => $this->iso($row->process_date),
            ], $page->items()),
            'meta' => [
                ...$list->metadata($request),
                'total' => $page->total(),
                'per_page' => $page->perPage(),
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                'balance' => $this->balance($account),
            ],
        ]);
    }

    /**
     * The account's own trusted payer addresses, and anything on hold.
     *
     * The legacy `donate/trusted` action was the player's own list, scoped to
     * their session rather than an administrator's screen. The list is
     * populated automatically when a held payment clears, so it is a record of
     * which of their addresses have been accepted rather than something
     * anybody edits.
     *
     * @throws ValidationException
     */
    public function trusted(Request $request): JsonResponse
    {
        $account = $this->account($request);

        return response()->json([
            'data' => $this->donations->trustedFor($account)
                ->orderByDesc('create_date')
                ->get()
                ->map(fn (object $row): array => [
                    'email' => (string) $row->email,
                    'since' => $this->iso($row->create_date),
                ])
                ->all(),
            'meta' => [
                /*
                 * Payments still inside their hold window, so somebody who has
                 * donated and is wondering where their credits are can see
                 * that it is pending rather than lost.
                 */
                'held' => $this->donations->heldFor($account)->get()->map(
                    fn (object $row): array => [
                        'credits' => (int) $row->credits,
                        'amount' => (string) ($row->mc_gross ?? ''),
                        'currency' => (string) ($row->mc_currency ?? ''),
                        'available_from' => $this->iso($row->hold_until),
                    ],
                )->all(),
                'hold_hours' => (int) config('panel.donations.hold_hours', 0),
            ],
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | Helpers
    |--------------------------------------------------------------------------
    */

    /**
     * The account a notification names.
     *
     * Looked up rather than trusted: the reference is whatever came back from
     * the provider, and an account that does not exist means the payment
     * cannot be matched.
     *
     * @param  array<string, mixed>  $payload
     */
    private function accountFor(array $payload): ?Account
    {
        foreach (['custom', 'item_number', 'option_selection1'] as $field) {
            $reference = trim((string) ($payload[$field] ?? ''));

            if ($reference !== '' && ctype_digit($reference)) {
                $account = Account::query()->find((int) $reference);

                if ($account instanceof Account) {
                    return $account;
                }
            }
        }

        return null;
    }

    private function account(Request $request): Account
    {
        $account = $request->user();

        abort_unless($account instanceof Account, 401);

        return $account;
    }

    private function balance(Account $account): int
    {
        $account->unsetRelation('credit');

        return (int) ($account->credit?->balance ?? 0);
    }

    private function iso(mixed $value): ?string
    {
        if ($value === null || $value === '' || str_starts_with((string) $value, '0000-00-00')) {
            return null;
        }

        return Carbon::parse((string) $value)->toIso8601String();
    }
}
