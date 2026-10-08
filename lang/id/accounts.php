<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Account credential messages — Indonesian
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
        'disabled' => 'Akun baru sedang tidak diterima untuk saat ini.',
        'created' => 'Akun Anda sudah siap. Anda bisa masuk sekarang.',
        'confirmation_sent' => 'Akun Anda sudah dibuat. Periksa :email untuk tautan yang mengaktifkannya.',
    ],

    'confirmation' => [
        'invalid' => 'Tautan konfirmasi tersebut tidak valid. Mungkin sudah pernah digunakan, atau sudah kedaluwarsa.',
        'confirmed' => 'Akun Anda sudah dikonfirmasi. Anda bisa masuk sekarang.',
        'resent' => 'Jika ada akun yang menunggu konfirmasi, tautan baru sedang dikirim ke alamat e-mailnya.',
    ],

    'reset' => [
        'requested' => 'Jika data tersebut cocok dengan sebuah akun, tautan untuk memilih kata sandi baru sedang dikirim ke alamat e-mailnya.',
        'invalid' => 'Tautan atur ulang tersebut tidak valid. Mungkin sudah pernah digunakan, atau sudah kedaluwarsa.',
        'complete' => 'Kata sandi Anda sudah diubah. Anda bisa masuk dengan kata sandi itu sekarang.',
        'not_permitted' => 'Kata sandi akun ini tidak dapat diatur ulang melalui e-mail. Hubungi administrator.',
    ],

    'password' => [
        'changed' => 'Kata sandi Anda sudah diubah.',
        'current_incorrect' => 'Itu bukan kata sandi Anda saat ini.',
        'same_as_current' => 'Pilih kata sandi yang berbeda dari kata sandi Anda saat ini.',
    ],

    'email' => [
        'changed' => 'Alamat e-mail Anda sudah diperbarui.',
        'confirmation_sent' => 'Periksa :email untuk tautan yang mengonfirmasi perubahan itu. Alamat Anda tetap seperti semula sampai Anda membukanya.',
        'same_as_current' => 'Itu sudah menjadi alamat e-mail Anda.',
        'in_use' => 'Alamat e-mail tersebut sudah digunakan.',
        'invalid' => 'Tautan konfirmasi tersebut tidak valid. Mungkin sudah pernah digunakan, atau sudah kedaluwarsa.',
        'confirmed' => 'Alamat e-mail Anda sudah diperbarui.',
    ],

    'captcha' => [
        'incorrect' => 'Karakter yang Anda masukkan tidak cocok dengan gambarnya.',
        'expired' => 'Gambar keamanan sudah kedaluwarsa. Silakan coba yang baru.',
        'unavailable' => 'Pemeriksaan keamanan tidak dapat diselesaikan. Silakan coba lagi.',
    ],

];
