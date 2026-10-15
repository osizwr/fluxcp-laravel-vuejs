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
        'one' => 'Anda akan dibawa kembali ke halaman status dalam :count saat.',
        'other' => 'Anda akan dibawa kembali ke halaman status dalam :count saat.',
    ],

    'stay' => 'Kekal di halaman ini',
    'stayed' => 'Anda kekal di halaman ini.',
    'back' => 'Kembali ke status',

    'statuses' => [
        400 => [
            'title' => 'Permintaan tidak sah',
            'body' => 'Pelayan tidak memahami permintaan itu. Semak alamat dan cuba lagi.',
        ],
        403 => [
            'title' => 'Dilarang',
            'body' => 'Anda tiada akses ke halaman itu.',
        ],
        404 => [
            'title' => 'Halaman tidak dijumpai',
            'body' => 'Halaman yang anda cari tidak wujud.',
        ],
        408 => [
            'title' => 'Permintaan tamat masa',
            'body' => 'Permintaan itu mengambil masa terlalu lama dan dihentikan. Cuba lagi.',
        ],
        429 => [
            'title' => 'Terlalu banyak permintaan',
            'body' => 'Terlalu banyak permintaan dalam masa yang singkat. Tunggu sebentar dan cuba lagi.',
        ],
    ],
];
