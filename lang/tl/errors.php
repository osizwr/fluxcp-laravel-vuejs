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
        'one' => 'Ibabalik ka sa pahina ng status sa loob ng :count segundo.',
        'other' => 'Ibabalik ka sa pahina ng status sa loob ng :count segundo.',
    ],

    'stay' => 'Manatili sa pahinang ito',
    'stayed' => 'Mananatili ka sa pahinang ito.',
    'back' => 'Bumalik sa status',

    'statuses' => [
        400 => [
            'title' => 'Maling kahilingan',
            'body' => 'Hindi naintindihan ng server ang kahilingang iyon. Suriin ang address at subukan muli.',
        ],
        403 => [
            'title' => 'Ipinagbabawal',
            'body' => 'Wala kang akses sa pahinang iyon.',
        ],
        404 => [
            'title' => 'Hindi natagpuan ang pahina',
            'body' => 'Wala ang pahinang hinahanap mo.',
        ],
        408 => [
            'title' => 'Natagalan ang kahilingan',
            'body' => 'Masyadong natagalan ang kahilingang iyon kaya itinigil na. Subukan muli.',
        ],
        429 => [
            'title' => 'Masyadong maraming kahilingan',
            'body' => 'Masyadong maraming kahilingan sa maikling panahon. Maghintay sandali at subukan muli.',
        ],
    ],
];
