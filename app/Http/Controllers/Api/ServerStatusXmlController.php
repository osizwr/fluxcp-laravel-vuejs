<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Services\Server\ServerStatusService;
use Illuminate\Http\Response;

/**
 * The legacy machine-readable status feed.
 *
 * Ports modules/server/status-xml.php, kept at its original shape so the
 * things already polling it -- server listing sites, Discord bots, status
 * widgets on a forum -- keep working after a migration. The JSON endpoint at
 * `/api/server/status` is the one to build anything new against.
 *
 * The element and attribute names are the legacy ones exactly: `ServerStatus`
 * with `Group` children, each with `Server` children carrying `name`,
 * `loginServer`, `charServer`, `mapServer` and `playersOnline`. Anything
 * consuming this parses those names, so "improving" them would be the same as
 * removing the endpoint.
 */
final class ServerStatusXmlController
{
    public function __construct(private readonly ServerStatusService $status) {}

    public function __invoke(): Response
    {
        $document = new \DOMDocument('1.0', 'utf-8');
        $document->formatOutput = true;

        $root = $document->createElement('ServerStatus');

        foreach ($this->status->all() as $group) {
            $groupElement = $document->createElement('Group');
            $groupElement->setAttribute('name', $group->name);

            foreach ($group->servers as $server) {
                $serverElement = $document->createElement('Server');

                $serverElement->setAttribute('name', $server->name);
                // Booleans as 0/1, as the legacy cast them.
                $serverElement->setAttribute('loginServer', (string) (int) $server->loginServerUp);
                $serverElement->setAttribute('charServer', (string) (int) $server->charServerUp);
                $serverElement->setAttribute('mapServer', (string) (int) $server->mapServerUp);
                $serverElement->setAttribute('playersOnline', (string) $server->playersOnline);

                $groupElement->appendChild($serverElement);
            }

            $root->appendChild($groupElement);
        }

        $document->appendChild($root);

        return response((string) $document->saveXML(), 200, [
            'Content-Type' => 'application/xml; charset=UTF-8',
            // The figures come from the same cache the JSON endpoint reads, so
            // a poller hitting this every minute costs nothing extra.
            'Cache-Control' => 'public, max-age=30',
        ]);
    }
}
