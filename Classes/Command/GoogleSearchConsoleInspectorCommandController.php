<?php
declare(strict_types=1);

namespace Netlogix\GoogleSearchConsoleInspector\Command;

use GuzzleHttp\Psr7\Uri;
use Neos\Flow\Cli\CommandController;
use Netlogix\GoogleSearchConsoleInspector\Domain\Service\InspectionService;
use Netlogix\GoogleSearchConsoleInspector\Domain\Service\SiteReportService;

class GoogleSearchConsoleInspectorCommandController extends CommandController
{

    private InspectionService $inspectionService;

    private SiteReportService $siteReportService;

    public function __construct(InspectionService $inspectionService, SiteReportService $siteReportService)
    {
        parent::__construct();

        $this->inspectionService = $inspectionService;
        $this->siteReportService = $siteReportService;
    }

    public function inspectCommand(string $uri): void
    {
        $result = $this->inspectionService->inspectUri(new Uri($uri));

        print_r(json_decode(json_encode($result->toSimpleObject()), true));
    }

    public function performanceCommand(string $uri): void
    {
        print_r($this->inspectionService->pagePerformance(new Uri($uri), true));
    }

    /**
     * Check that the service account can access every configured Search Console property
     */
    public function checkSetupCommand(): void
    {
        $failed = false;
        $rows = [];
        foreach ($this->siteReportService->permissionLevels() as $property => $permissionLevel) {
            $hasAccess = $permissionLevel !== null && $permissionLevel !== 'siteUnverifiedUser';
            $failed = $failed || !$hasAccess;
            $rows[] = [$property, $permissionLevel ?? 'not available', $hasAccess ? 'ok' : 'NO ACCESS'];
        }

        $this->output->outputTable($rows, ['Property', 'Permission level', 'Status']);
        if ($failed) {
            $this->outputLine('Add the service account as a user to the failing properties in Google Search Console.');
            $this->quit(1);
        }
    }

    /**
     * Inspect all URLs of the submitted sitemaps and store the ones that are not indexed
     *
     * The result is shown in the Search Console backend module. Results of single URLs
     * are cached, so the daily quota of 2000 inspections per property is only hit once.
     *
     * @param string|null $property Search Console property to inspect, all configured ones by default
     * @param int $limit Maximum number of URLs to inspect per property
     */
    public function inspectSitemapsCommand(?string $property = null, int $limit = 1000): void
    {
        $properties = $property === null ? $this->siteReportService->properties() : [$property];
        foreach ($properties as $property) {
            $this->outputLine('<b>%s</b>', [$property]);
            $report = $this->siteReportService->inspectSitemaps(
                $property,
                $limit,
                fn (string $url, ?string $verdict, ?\Throwable $error) => $this->outputLine(
                    '  %-8s %s %s',
                    [$error ? 'ERROR' : $verdict, $url, $error ? $error->getMessage() : '']
                )
            );
            $this->outputLine(
                '%d inspected, %d not indexed, %d errors',
                [$report['inspected'], count($report['notIndexed']), $report['errors']]
            );
        }
    }

}
