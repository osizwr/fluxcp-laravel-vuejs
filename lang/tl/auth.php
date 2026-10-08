<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Authentication messages — Tagalog
|--------------------------------------------------------------------------
|
| Mirrors lang/en/auth.php. Read the notes there before changing any of these:
| the vagueness of `invalid_credentials` is deliberate and must survive
| translation, because a message that names the field that was wrong tells
| somebody guessing whether the account exists.
|
*/

return [

    'failure' => [
        'unexpected' => 'May naganap na problema habang sinusubukan kang i-sign in. Pakisubukan muli.',
        'unknown_server' => 'Hindi available ang game server na iyon.',
        'invalid_credentials' => 'Hindi tama ang mga detalye ng pag-sign in.',
        'temporarily_banned' => 'Suspendido ang account na ito. Tingnan ang iyong e-mail para sa dahilan at sa petsa ng pagtatapos.',
        'permanently_banned' => 'Permanenteng naka-ban ang account na ito.',
        'ip_banned' => 'Hindi pinapayagang mag-sign in ang iyong koneksyon.',
        'invalid_security_code' => 'Hindi tama ang security code.',
        'pending_confirmation' => 'Kailangan pang kumpirmahin ang account na ito. Tingnan ang iyong e-mail para sa confirmation link.',
    ],

    'failed' => 'Hindi tama ang mga detalye ng pag-sign in.',
    'password' => 'Hindi tama ang password.',
    'throttle' => 'Masyadong maraming pagsubok. Pakisubukan muli pagkalipas ng :seconds na segundo.',

];
