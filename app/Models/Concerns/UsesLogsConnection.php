<?php

declare(strict_types=1);

namespace App\Models\Concerns;

use App\Support\Rathena\ServerRegistry;

/**
 * Binds a model to the logs database of the current server group.
 *
 * Operators commonly put the logs database on a separate host, which is why
 * rAthena and FluxCP both configure it independently. Keeping it behind its
 * own connection means that remains possible.
 */
trait UsesLogsConnection
{
    public function getConnectionName(): ?string
    {
        return app(ServerRegistry::class)->current()->logsConnection();
    }
}
