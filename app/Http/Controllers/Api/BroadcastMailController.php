<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Mail\BroadcastMessage;
use App\Models\Account;
use App\Services\Content\ContentRenderer;
use Illuminate\Contracts\Mail\Mailer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Mailing the player base.
 *
 * Ports modules/mail/index.php, which let an administrator pick a template and
 * send it to an address or to everyone.
 *
 * ---------------------------------------------------------------------------
 * Why this one asks for confirmation
 * ---------------------------------------------------------------------------
 *
 * Sending to every account is the one action in this panel that cannot be
 * stopped once it starts and is visible to every player at once. A mistake is
 * not a bad row in a table; it is a mail in thousands of inboxes, from your
 * domain, which is also how a sending domain gets blacklisted.
 *
 * So: a recipient count is returned first, and the send only happens when the
 * request repeats it back. A caller that has not looked at the count cannot
 * accidentally send.
 */
final class BroadcastMailController
{
    public function __construct(
        private readonly Mailer $mailer,
        private readonly ContentRenderer $renderer,
    ) {}

    /**
     * Who a message would reach, and send it when confirmed.
     *
     * @throws ValidationException
     */
    public function send(Request $request): JsonResponse
    {
        $actor = $request->user();

        abort_unless($actor instanceof Account, 401);

        $validated = $request->validate([
            'subject' => ['required', 'string', 'max:200'],
            'body' => ['required', 'string', 'max:65535'],
            /*
             * Either one address, or every account. There is deliberately no
             * arbitrary filter: a half-understood query that selects the wrong
             * group is the mistake this endpoint is most likely to make.
             */
            'audience' => ['required', 'in:address,everyone'],
            'address' => ['required_if:audience,address', 'nullable', 'email', 'max:39'],
            /*
             * The recipient count, echoed back from a previous call. Absent
             * means "tell me who this would reach" rather than "send it".
             */
            'confirm_recipients' => ['nullable', 'integer', 'min:0'],
        ]);

        $recipients = $validated['audience'] === 'address'
            ? [$validated['address']]
            : $this->everyAddress();

        $count = count($recipients);

        if (($validated['confirm_recipients'] ?? null) === null) {
            return response()->json([
                'message' => $count === 1
                    ? 'This will send one message. Repeat the request with confirm_recipients to send it.'
                    : "This will send {$count} messages. Repeat the request with confirm_recipients to send them.",
                'data' => ['recipients' => $count, 'sent' => false],
            ]);
        }

        if ((int) $validated['confirm_recipients'] !== $count) {
            /*
             * The set changed between looking and sending -- somebody
             * registered, or an address was corrected. Refusing makes the
             * sender look again rather than send to a list they have not seen.
             */
            return response()->json([
                'message' => "The number of recipients changed from {$validated['confirm_recipients']} to {$count}. Check again before sending.",
                'data' => ['recipients' => $count, 'sent' => false],
            ], 409);
        }

        $html = $this->renderer->toHtml($validated['body']);
        $sent = 0;
        $failed = 0;

        foreach ($recipients as $address) {
            try {
                $this->mailer->to($address)->queue(new BroadcastMessage(
                    $validated['subject'],
                    $validated['body'],
                    $html,
                ));

                $sent++;
            } catch (Throwable $e) {
                $failed++;

                Log::error('A broadcast message could not be queued.', [
                    'reason' => $e->getMessage(),
                    // The recipient is not logged: this runs over the whole
                    // player base, and a failure should not write thousands of
                    // addresses into a log file.
                ]);
            }
        }

        Log::info('A broadcast was sent.', [
            'by' => $actor->userid,
            'subject' => $validated['subject'],
            'recipients' => $count,
            'queued' => $sent,
            'failed' => $failed,
        ]);

        return response()->json([
            'message' => "{$sent} message(s) queued.",
            'data' => ['recipients' => $count, 'sent' => true, 'queued' => $sent, 'failed' => $failed],
        ]);
    }

    /**
     * Every usable address on the server.
     *
     * Excludes rAthena's own server accounts, which have no human owner, and
     * anything that is not an address -- an old install has rows with empty or
     * placeholder e-mail columns, and trying to send to them is how a run
     * fails halfway.
     *
     * @return list<string>
     */
    private function everyAddress(): array
    {
        return Account::query()
            ->players()
            ->pluck('email')
            ->map(fn (?string $email): string => trim((string) $email))
            ->filter(fn (string $email): bool => filter_var($email, FILTER_VALIDATE_EMAIL) !== false)
            ->unique()
            ->values()
            ->all();
    }
}
