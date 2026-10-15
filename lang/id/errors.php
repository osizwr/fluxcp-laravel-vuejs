<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| HTTP error pages
|--------------------------------------------------------------------------
|
| Read by resources/views/errors/layout.blade.php, which every status view
| includes. `countdown` keeps its two forms as an array rather than using
| Laravel's pluralisation, because the sentence is rendered by the script on
| that page once a second and so has to reach the browser as a template.
|
*/

return [
    /* The two forms the countdown takes, substituted on :count. */
    'countdown' => [
        'one' => 'Anda akan dibawa kembali ke halaman status dalam :count detik.',
        'other' => 'Anda akan dibawa kembali ke halaman status dalam :count detik.',
    ],

    'stay' => 'Tetap di halaman ini',
    'stayed' => 'Anda tetap di halaman ini.',
    'back' => 'Kembali ke status',

    'statuses' => [
        400 => [
            'title' => 'Permintaan tidak valid',
            'body' => 'Server tidak memahami permintaan itu. Periksa alamatnya lalu coba lagi.',
        ],
        403 => [
            'title' => 'Akses ditolak',
            'body' => 'Anda tidak memiliki akses ke halaman itu.',
        ],
        404 => [
            'title' => 'Halaman tidak ditemukan',
            'body' => 'Halaman yang Anda cari tidak ada.',
        ],
        408 => [
            'title' => 'Permintaan kehabisan waktu',
            'body' => 'Permintaan itu terlalu lama sehingga dihentikan. Coba lagi.',
        ],
        429 => [
            'title' => 'Terlalu banyak permintaan',
            'body' => 'Terlalu banyak permintaan dalam waktu singkat. Tunggu sebentar lalu coba lagi.',
        ],
    ],
];
