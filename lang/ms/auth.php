<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Authentication messages — Malay
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
        'unexpected' => 'Sesuatu telah tidak kena semasa anda log masuk. Sila cuba lagi.',
        'unknown_server' => 'Pelayan permainan itu tidak tersedia.',
        'invalid_credentials' => 'Butiran log masuk itu tidak betul.',
        'temporarily_banned' => 'Akaun ini digantung. Semak e-mel anda untuk sebab dan tarikh tamatnya.',
        'permanently_banned' => 'Akaun ini telah diharamkan secara kekal.',
        'ip_banned' => 'Sambungan anda tidak dibenarkan untuk log masuk.',
        'invalid_security_code' => 'Kod keselamatan itu tidak betul.',
        'pending_confirmation' => 'Akaun ini masih perlu disahkan. Semak e-mel anda untuk pautan pengesahannya.',
    ],

    'failed' => 'Butiran log masuk itu tidak betul.',
    'password' => 'Kata laluan itu tidak betul.',
    'throttle' => 'Terlalu banyak percubaan. Sila cuba lagi dalam :seconds saat.',

];
