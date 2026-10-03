<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Account credential messages
|--------------------------------------------------------------------------
|
| Registration, e-mail confirmation, password reset, password change and
| e-mail change.
|
| Several of these are deliberately the same sentence for a success and for a
| "no such account" outcome. That is not laziness: a form that answers
| differently for an address it knows is an account enumeration oracle, and
| the legacy panel had exactly that -- `ResetPassFailed` was shown only when
| the username and e-mail did not match a real account, so the form confirmed
| which addresses were registered.
|
*/

return [

    'registration' => [
        'disabled' => 'New accounts are not being accepted at the moment.',
        'created' => 'Your account is ready. You can sign in now.',
        'confirmation_sent' => 'Your account has been created. Check :email for the link that activates it.',
    ],

    'confirmation' => [
        /*
         * One message for an unknown token, an expired token, an
         * already-confirmed account and a token belonging to another account.
         * Distinguishing them would let somebody holding a stale link learn
         * which case it is, and none of the four is actionable differently.
         */
        'invalid' => 'That confirmation link is not valid. It may have already been used, or it may have expired.',
        'confirmed' => 'Your account is confirmed. You can sign in now.',

        /*
         * Shown whether or not an unconfirmed account matched, for the same
         * reason as the reset messages below.
         */
        'resent' => 'If that account is waiting to be confirmed, a new link is on its way to its e-mail address.',
    ],

    'reset' => [
        'requested' => 'If those details match an account, a link to choose a new password is on its way to its e-mail address.',
        'invalid' => 'That reset link is not valid. It may have already been used, or it may have expired.',
        'complete' => 'Your password has been changed. You can sign in with it now.',

        /*
         * Staff accounts above the configured level cannot be reset by e-mail,
         * because an e-mail account is a weaker thing to hold than a game
         * master account. This is the legacy NoResetPassGroupLevel setting.
         *
         * Note it is never shown: the endpoint answers with 'requested'
         * regardless, so that the form does not identify which accounts are
         * staff. It is here for the audit log and the console.
         */
        'not_permitted' => 'Passwords for this account cannot be reset by e-mail. Contact an administrator.',
    ],

    'password' => [
        'changed' => 'Your password has been changed.',
        'current_incorrect' => 'That is not your current password.',
        'same_as_current' => 'Choose a password that is different from your current one.',
    ],

    'email' => [
        'changed' => 'Your e-mail address has been updated.',
        'confirmation_sent' => 'Check :email for the link that confirms the change. Your address stays as it is until you follow it.',
        'same_as_current' => 'That is already your e-mail address.',
        'in_use' => 'That e-mail address is already in use.',
        'invalid' => 'That confirmation link is not valid. It may have already been used, or it may have expired.',
        'confirmed' => 'Your e-mail address has been updated.',
    ],

    'captcha' => [
        'incorrect' => 'The characters you entered did not match the image.',
        'expired' => 'The security image expired. Please try the new one.',
        'unavailable' => 'The security check could not be completed. Please try again.',
    ],

];
