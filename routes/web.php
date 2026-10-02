<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Web routes
|--------------------------------------------------------------------------
|
| The panel is a single-page application, so every path that is not an API
| endpoint returns the same shell and lets the client router decide what to
| render. The excluded prefixes are the ones Laravel itself serves.
|
*/

Route::view('/{any?}', 'app')
    ->where('any', '^(?!api|up|storage|build).*$')
    ->name('spa');
