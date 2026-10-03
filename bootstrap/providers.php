<?php

declare(strict_types=1);

use App\Providers\AppServiceProvider;
use App\Providers\AuthorizationServiceProvider;
use App\Providers\RathenaServiceProvider;
use App\Providers\ThemeServiceProvider;

return [
    AppServiceProvider::class,
    RathenaServiceProvider::class,
    AuthorizationServiceProvider::class,
    ThemeServiceProvider::class,
];
