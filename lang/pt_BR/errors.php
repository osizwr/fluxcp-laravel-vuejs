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
        'one' => 'Levando você de volta para o status em :count segundo.',
        'other' => 'Levando você de volta para o status em :count segundos.',
    ],

    'stay' => 'Ficar nesta página',
    'stayed' => 'Você vai ficar nesta página.',
    'back' => 'Voltar para o status',

    'statuses' => [
        400 => [
            'title' => 'Requisição inválida',
            'body' => 'O servidor não entendeu essa requisição. Confira o endereço e tente de novo.',
        ],
        403 => [
            'title' => 'Acesso negado',
            'body' => 'Você não tem acesso a essa página.',
        ],
        404 => [
            'title' => 'Página não encontrada',
            'body' => 'A página que você procurava não existe.',
        ],
        408 => [
            'title' => 'Tempo da requisição esgotado',
            'body' => 'Essa requisição demorou demais e foi interrompida. Tente de novo.',
        ],
        429 => [
            'title' => 'Requisições demais',
            'body' => 'Requisições demais em pouco tempo. Espere um momento e tente de novo.',
        ],
    ],
];
