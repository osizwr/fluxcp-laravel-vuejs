<?php

declare(strict_types=1);

namespace App\Models\Concerns;

use App\Support\Rathena\ServerRegistry;

/**
 * Binds a model to the character database of the char/map pair the current
 * request is working against.
 *
 * One login server may front several char/map pairs with separate character
 * databases, so this follows the visitor's selected pair rather than a fixed
 * connection.
 */
trait UsesCharMapConnection
{
    public function getConnectionName(): ?string
    {
        return app(ServerRegistry::class)->currentCharMapServer()->connectionName();
    }
}
