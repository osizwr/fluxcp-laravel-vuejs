<?php

declare(strict_types=1);

use App\Providers\AppServiceProvider;
use App\Providers\AuthorizationServiceProvider;
use App\Providers\RathenaServiceProvider;

return [
    AppServiceProvider::class,
    RathenaServiceProvider::class,
    AuthorizationServiceProvider::class,
];
