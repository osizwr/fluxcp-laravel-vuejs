<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Account credential messages — Tagalog
|--------------------------------------------------------------------------
|
| Mirrors lang/en/accounts.php, including its silences. Several of these say
| the same thing whether or not an account matched -- that is what stops the
| forms confirming which addresses are registered, and it must survive
| translation. Read the notes in the English file before changing any of them.
|
*/

return [

    'registration' => [
        'disabled' => 'Hindi muna tumatanggap ng bagong account sa ngayon.',
        'created' => 'Handa na ang iyong account. Maaari ka nang mag-sign in.',
        'confirmation_sent' => 'Nagawa na ang iyong account. Tingnan ang :email para sa link na mag-a-activate nito.',
    ],

    'confirmation' => [
        'invalid' => 'Hindi wasto ang confirmation link na iyon. Maaaring nagamit na ito, o nag-expire na.',
        'confirmed' => 'Nakumpirma na ang iyong account. Maaari ka nang mag-sign in.',
        'resent' => 'Kung may account na naghihintay ng kumpirmasyon, papadalhan ng bagong link ang e-mail address nito.',
    ],

    'reset' => [
        'requested' => 'Kung tumutugma ang mga detalyeng iyon sa isang account, papadalhan ng link para pumili ng bagong password ang e-mail address nito.',
        'invalid' => 'Hindi wasto ang reset link na iyon. Maaaring nagamit na ito, o nag-expire na.',
        'complete' => 'Napalitan na ang iyong password. Maaari ka nang mag-sign in gamit ito.',
        'not_permitted' => 'Hindi maaaring i-reset sa pamamagitan ng e-mail ang password ng account na ito. Makipag-ugnayan sa administrator.',
    ],

    'password' => [
        'changed' => 'Napalitan na ang iyong password.',
        'current_incorrect' => 'Hindi iyan ang iyong kasalukuyang password.',
        'same_as_current' => 'Pumili ng password na iba sa iyong kasalukuyang password.',
    ],

    'email' => [
        'changed' => 'Na-update na ang iyong e-mail address.',
        'confirmation_sent' => 'Tingnan ang :email para sa link na magkukumpirma ng pagbabago. Mananatili ang iyong address hangga’t hindi mo ito sinusundan.',
        'same_as_current' => 'Iyan na ang iyong e-mail address.',
        'in_use' => 'Ginagamit na ang e-mail address na iyon.',
        'invalid' => 'Hindi wasto ang confirmation link na iyon. Maaaring nagamit na ito, o nag-expire na.',
        'confirmed' => 'Na-update na ang iyong e-mail address.',
    ],

    'captcha' => [
        'incorrect' => 'Hindi tumugma sa larawan ang mga karakter na inilagay mo.',
        'expired' => 'Nag-expire na ang security image. Pakisubukan ang bago.',
        'unavailable' => 'Hindi nakumpleto ang security check. Pakisubukan muli.',
    ],

];
