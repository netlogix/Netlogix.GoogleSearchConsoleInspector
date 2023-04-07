<?php

declare(strict_types=1);

namespace Netlogix\GoogleSearchConsoleInspector\Factory;

use Neos\Flow\Annotations as Flow;
use Google\Client;

/**
 * @Flow\Scope("singleton")
 */
final class ClientFactory
{
    public function create(array $config): Client
    {
        $client = new Client($config);

        return $client;
    }
}
