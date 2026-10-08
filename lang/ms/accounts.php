<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Account credential messages — Malay
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
        'disabled' => 'Akaun baharu tidak diterima buat masa ini.',
        'created' => 'Akaun anda sudah sedia. Anda boleh log masuk sekarang.',
        'confirmation_sent' => 'Akaun anda telah dibuka. Semak :email untuk pautan yang mengaktifkannya.',
    ],

    'confirmation' => [
        'invalid' => 'Pautan pengesahan itu tidak sah. Ia mungkin telah digunakan, atau telah luput.',
        'confirmed' => 'Akaun anda telah disahkan. Anda boleh log masuk sekarang.',
        'resent' => 'Jika ada akaun yang menunggu pengesahan, pautan baharu sedang dihantar ke alamat e-melnya.',
    ],

    'reset' => [
        'requested' => 'Jika butiran itu sepadan dengan sesebuah akaun, pautan untuk memilih kata laluan baharu sedang dihantar ke alamat e-melnya.',
        'invalid' => 'Pautan tetapan semula itu tidak sah. Ia mungkin telah digunakan, atau telah luput.',
        'complete' => 'Kata laluan anda telah ditukar. Anda boleh log masuk dengannya sekarang.',
        'not_permitted' => 'Kata laluan akaun ini tidak boleh ditetapkan semula melalui e-mel. Hubungi pentadbir.',
    ],

    'password' => [
        'changed' => 'Kata laluan anda telah ditukar.',
        'current_incorrect' => 'Itu bukan kata laluan semasa anda.',
        'same_as_current' => 'Pilih kata laluan yang berbeza daripada kata laluan semasa anda.',
    ],

    'email' => [
        'changed' => 'Alamat e-mel anda telah dikemas kini.',
        'confirmation_sent' => 'Semak :email untuk pautan yang mengesahkan pertukaran itu. Alamat anda kekal seperti sedia ada sehingga anda mengikutinya.',
        'same_as_current' => 'Itu memang alamat e-mel anda.',
        'in_use' => 'Alamat e-mel itu sudah digunakan.',
        'invalid' => 'Pautan pengesahan itu tidak sah. Ia mungkin telah digunakan, atau telah luput.',
        'confirmed' => 'Alamat e-mel anda telah dikemas kini.',
    ],

    'captcha' => [
        'incorrect' => 'Aksara yang anda masukkan tidak sepadan dengan imej itu.',
        'expired' => 'Imej keselamatan itu telah luput. Sila cuba yang baharu.',
        'unavailable' => 'Semakan keselamatan tidak dapat diselesaikan. Sila cuba lagi.',
    ],

];
