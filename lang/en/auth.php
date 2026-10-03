<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Authentication messages
|--------------------------------------------------------------------------
|
| The `failure` keys are produced by App\Enums\LoginFailure::translationKey().
|
| Note how little the invalid_credentials message says. It must not reveal
| whether the account exists, so it cannot name the field that was wrong. The
| ban and confirmation messages are specific, because they are only reachable
| once the password has already been verified and so disclose nothing to
| somebody guessing.
|
*/

return [

    'failure' => [
        'unexpected' => 'Something went wrong while signing you in. Please try again.',
        'unknown_server' => 'That game server is not available.',
        'invalid_credentials' => 'Those sign-in details are not correct.',
        'temporarily_banned' => 'This account is suspended. Check your e-mail for the reason and the end date.',
        'permanently_banned' => 'This account has been permanently banned.',
        'ip_banned' => 'Your connection is not permitted to sign in.',
        'invalid_security_code' => 'The security code was not correct.',
        'pending_confirmation' => 'This account still needs to be confirmed. Check your e-mail for the confirmation link.',
    ],

    /*
     * Laravel's own keys, used by the framework's throttling and by
     * Auth::attempt(). Kept so nothing falls back to a raw key.
     */
    'failed' => 'Those sign-in details are not correct.',
    'password' => 'The password is not correct.',
    'throttle' => 'Too many attempts. Please try again in :seconds seconds.',

];
