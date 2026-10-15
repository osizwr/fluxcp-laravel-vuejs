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
        'one' => 'Taking you back to the status page in :count second.',
        'other' => 'Taking you back to the status page in :count seconds.',
    ],

    'stay' => 'Stay on this page',
    'stayed' => 'Staying on this page.',
    'back' => 'Back to status',

    'statuses' => [
        400 => [
            'title' => 'Bad request',
            'body' => 'The server could not make sense of that request. Check the address and try again.',
        ],
        403 => [
            'title' => 'Forbidden',
            'body' => 'You do not have access to that page.',
        ],
        404 => [
            'title' => 'Page not found',
            'body' => 'The page you were looking for does not exist.',
        ],
        408 => [
            'title' => 'Request timed out',
            'body' => 'That request took too long and was given up on. Try it again.',
        ],
        429 => [
            'title' => 'Too many requests',
            'body' => 'Too many requests in too short a time. Wait a moment and try again.',
        ],
    ],
];
