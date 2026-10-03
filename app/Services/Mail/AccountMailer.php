<?php

declare(strict_types=1);

namespace App\Services\Mail;

use App\Mail\AccountConfirmationMail;
use App\Mail\AccountMail;
use App\Mail\EmailChangeConfirmationMail;
use App\Mail\PasswordChangedMail;
use App\Mail\PasswordResetMail;
use App\Models\Account;
use App\Support\Client\ClientRoutes;
use App\Support\Rathena\ServerGroup;
use App\Support\Tokens\SecureToken;
use Illuminate\Contracts\Mail\Mailer;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Sends the account credential e-mails, and builds the links in them.
 *
 * One class so that the link format, the queueing decision and the failure
 * behaviour are decided once rather than at four call sites.
 *
 * ---------------------------------------------------------------------------
 * Queued or not
 * ---------------------------------------------------------------------------
 *
 * Sent inline by default, and queued only when PANEL_QUEUE_MAIL is on.
 *
 * That is the opposite of the usual Laravel advice, for a specific reason: a
 * queued mail on a server with no worker running is a mail that is never sent,
 * and the person affected is someone who cannot complete their registration or
 * get back into their account. An operator who has a worker can turn queueing
 * on and get the latency back.
 *
 * ---------------------------------------------------------------------------
 * Failure
 * ---------------------------------------------------------------------------
 *
 * A send failure is logged and swallowed, and the caller is told whether it
 * worked. It must not turn into a 500 after the database write has already
 * happened: the account exists, or the reset token exists, and the useful
 * answer is "we could not e-mail you, try resending" rather than an error page
 * that leaves the person unsure whether they have an account at all.
 */
final readonly class AccountMailer
{
    public function __construct(private Mailer $mailer) {}

    /**
     * @return bool Whether the message was handed off successfully.
     */
    public function sendAccountConfirmation(
        ServerGroup $group,
        Account $account,
        SecureToken $token,
    ): bool {
        $hours = (int) config('panel.registration.email_confirmation_expires_after_hours', 48);

        return $this->dispatch($account->email, new AccountConfirmationMail(
            username: $account->userid,
            confirmationUrl: ClientRoutes::url(ClientRoutes::CONFIRM_ACCOUNT, [
                'token' => $token->plaintext,
                'server' => $group->key,
            ]),
            expiresInHours: $hours,
        ));
    }

    public function sendPasswordReset(
        ServerGroup $group,
        Account $account,
        SecureToken $token,
    ): bool {
        $hours = (int) config('panel.password_reset.expires_after_hours', 2);

        return $this->dispatch($account->email, new PasswordResetMail(
            username: $account->userid,
            resetUrl: ClientRoutes::url(ClientRoutes::RESET_PASSWORD, [
                'token' => $token->plaintext,
                'server' => $group->key,
            ]),
            expiresInHours: $hours,
        ));
    }

    /**
     * Tell the account holder their password changed.
     *
     * Deliberately sent to the address on the account rather than to anything
     * supplied with the request, so that an attacker who changed the password
     * cannot also decide who hears about it.
     */
    public function sendPasswordChangedNotice(Account $account, string $fromIp): bool
    {
        return $this->dispatch($account->email, new PasswordChangedMail(
            username: $account->userid,
            changedFromIp: $fromIp === '' ? 'an unknown address' : $fromIp,
            changedAt: now()->toDayDateTimeString().' UTC',
        ));
    }

    /**
     * Sent to the proposed address, which is the whole point of the step.
     */
    public function sendEmailChangeConfirmation(
        Account $account,
        string $newEmail,
        SecureToken $token,
    ): bool {
        $hours = (int) config('panel.email_change.expires_after_hours', 24);

        return $this->dispatch($newEmail, new EmailChangeConfirmationMail(
            username: $account->userid,
            newEmail: $newEmail,
            confirmationUrl: ClientRoutes::url(ClientRoutes::CONFIRM_EMAIL_CHANGE, [
                'token' => $token->plaintext,
            ]),
            expiresInHours: $hours,
        ));
    }

    /*
    |--------------------------------------------------------------------------
    | Delivery
    |--------------------------------------------------------------------------
    */

    private function dispatch(string $recipient, AccountMail $mail): bool
    {
        if (trim($recipient) === '') {
            return false;
        }

        try {
            $pending = $this->mailer->to($recipient);

            config('panel.mail.queue') === true
                ? $pending->queue($mail)
                : $pending->send($mail);

            return true;
        } catch (Throwable $e) {
            /*
             * The exception message only, not the exception. A stack trace
             * from a mail transport tends to carry the SMTP credentials in a
             * frame argument, and this goes to a log file.
             */
            Log::error('An account e-mail could not be sent.', [
                'mailable' => $mail::class,
                'reason' => $e->getMessage(),
            ]);

            return false;
        }
    }
}
