<?php

declare(strict_types=1);

namespace App\Models\Concerns;

/**
 * Pins a model to the application's own database.
 *
 * Needed because Eloquent's newRelatedInstance() copies the parent's
 * connection onto any related model that does not name one of its own. A
 * panel-owned model related to an rAthena model would therefore be queried
 * against rAthena's database, where its table does not exist.
 *
 * The default connection is resolved at call time rather than hard-coded,
 * since it differs between environments.
 */
trait UsesPanelConnection
{
    public function getConnectionName(): ?string
    {
        return $this->connection ?? config('database.default');
    }
}
