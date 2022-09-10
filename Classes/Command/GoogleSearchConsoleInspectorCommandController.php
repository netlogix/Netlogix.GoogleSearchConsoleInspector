<?php
declare(strict_types=1);

namespace Netlogix\GoogleSearchConsoleInspector\Command;

use GuzzleHttp\Psr7\Uri;
use Neos\Flow\Cli\CommandController;
use Netlogix\GoogleSearchConsoleInspector\Domain\Service\InspectionService;

class GoogleSearchConsoleInspectorCommandController extends CommandController
{

    private InspectionService $inspectionService;

    public function __construct(InspectionService $inspectionService)
    {
        parent::__construct();

        $this->inspectionService = $inspectionService;
    }

    public function inspectCommand(string $uri): void
    {
        $result = $this->inspectionService->inspectUri(new Uri($uri));

        print_r(json_decode(json_encode($result->toSimpleObject()), true));
    }

}
