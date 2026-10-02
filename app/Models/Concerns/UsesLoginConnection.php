<?php

declare(strict_types=1);

namespace App\Models\Concerns;

use App\Support\Rathena\ServerRegistry;

/**
 * Binds a model to the login database of the server group the current request
 * is working against.
 *
 * The connection cannot be a static property: a panel may front several
 * server groups, and which one is in play is decided per request (D5). Models
 * therefore resolve their connection name on demand.
 */
trait UsesLoginConnection
{
    public function getConnectionName(): ?string
    {
        return app(ServerRegistry::class)->current()->loginConnection();
    }
}
