<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Authentication messages — Indonesian
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
        'unexpected' => 'Terjadi kesalahan saat memproses masuk Anda. Silakan coba lagi.',
        'unknown_server' => 'Server game tersebut tidak tersedia.',
        'invalid_credentials' => 'Data masuk tersebut tidak benar.',
        'temporarily_banned' => 'Akun ini sedang ditangguhkan. Periksa e-mail Anda untuk alasan dan tanggal berakhirnya.',
        'permanently_banned' => 'Akun ini telah diblokir secara permanen.',
        'ip_banned' => 'Koneksi Anda tidak diizinkan untuk masuk.',
        'invalid_security_code' => 'Kode keamanan tidak benar.',
        'pending_confirmation' => 'Akun ini masih perlu dikonfirmasi. Periksa e-mail Anda untuk tautan konfirmasinya.',
    ],

    'failed' => 'Data masuk tersebut tidak benar.',
    'password' => 'Kata sandi tidak benar.',
    'throttle' => 'Terlalu banyak percobaan. Silakan coba lagi dalam :seconds detik.',

];
