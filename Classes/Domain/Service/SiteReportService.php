<?php
declare(strict_types=1);

namespace Netlogix\GoogleSearchConsoleInspector\Domain\Service;

use Google\Service\SearchConsole\ApiDataRow;
use Google\Service\SearchConsole\WmxSitemap;
use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Psr7\Uri;
use Neos\Cache\Frontend\VariableFrontend;
use Neos\Flow\Annotations as Flow;

/**
 * Site wide data per Search Console property, as opposed to the per page data of the InspectionService
 *
 * @Flow\Scope("singleton")
 */
class SiteReportService
{
    /**
     * @var array
     * @Flow\InjectConfiguration(path="siteUrlMapping")
     */
    protected $siteUrlMapping = [];

    /**
     * @var InspectionService
     * @Flow\Inject
     */
    protected $inspectionService;

    /**
     * @var VariableFrontend
     */
    protected $responseCache;

    /**
     * @var VariableFrontend
     */
    protected $reportCache;

    /**
     * @var ClientInterface|null
     */
    protected $httpClient;

    /**
     * @return string[]
     */
    public function properties(): array
    {
        return array_keys($this->siteUrlMapping ?? []);
    }

    /**
     * @return array<string, string|null> permission level of the service account per configured property,
     *                                    null if the property is not available to it at all
     */
    public function permissionLevels(): array
    {
        $levels = [];
        foreach ($this->inspectionService->sites->listSites()->getSiteEntry() ?? [] as $site) {
            $levels[$site->getSiteUrl()] = $site->getPermissionLevel();
        }

        $result = [];
        foreach ($this->properties() as $property) {
            $result[$property] = $levels[$property] ?? null;
        }

        return $result;
    }

    /**
     * Top pages, top queries and sitemap status of a property for the last 28 days
     */
    public function overview(string $property): array
    {
        $cacheIdentifier = sha1('overview' . $property);
        if ($this->responseCache->has($cacheIdentifier)) {
            return $this->responseCache->get($cacheIdentifier);
        }

        [$start, $end] = InspectionService::period();
        $row = static fn (ApiDataRow $row) => [
            'key' => $row->getKeys()[0],
            'clicks' => (int)$row->getClicks(),
            'impressions' => (int)$row->getImpressions(),
            'ctr' => $row->getCtr() * 100,
            'position' => $row->getPosition(),
        ];

        $rows = function (string $dimension) use ($property, $start, $end, $row): array {
            $rows = array_map($row, $this->inspectionService->query($property, $dimension, $start, $end, 25));
            // the API only sorts by clicks, which leaves the many zero-click rows unordered
            usort($rows, static fn (array $a, array $b) => [$b['clicks'], $b['impressions']] <=> [$a['clicks'], $a['impressions']]);

            return $rows;
        };

        $result = [
            'startDate' => $start->format('Y-m-d'),
            'endDate' => $end->format('Y-m-d'),
            'pages' => $rows('page'),
            'queries' => $rows('query'),
            'sitemaps' => array_map(static fn (WmxSitemap $sitemap) => [
                'path' => $sitemap->getPath(),
                'lastDownloaded' => $sitemap->getLastDownloaded(),
                'isPending' => (bool)$sitemap->getIsPending(),
                'errors' => (int)$sitemap->getErrors(),
                'warnings' => (int)$sitemap->getWarnings(),
                'submitted' => array_sum(array_map(
                    static fn ($content) => (int)$content->getSubmitted(),
                    $sitemap->getContents() ?? []
                )),
            ], $this->sitemaps($property)),
        ];
        $this->responseCache->set($cacheIdentifier, $result, [sha1($property)]);

        return $result;
    }

    /**
     * Inspects every URL of the sitemaps submitted for the property and stores the ones Google did not index.
     *
     * @param \Closure(string $url, string|null $verdict, \Throwable|null $error): void|null $onProgress
     * @return array{generatedAt: int, inspected: int, errors: int, notIndexed: array}
     */
    public function inspectSitemaps(string $property, int $limit, ?\Closure $onProgress = null): array
    {
        $report = ['generatedAt' => time(), 'inspected' => 0, 'errors' => 0, 'notIndexed' => []];

        foreach ($this->sitemapUrls($property, $limit) as $url) {
            try {
                $result = $this->inspectionService->inspectUri(new Uri($url));
            } catch (\Throwable $t) {
                $report['errors']++;
                $onProgress && $onProgress($url, null, $t);
                if ($t->getCode() === 429) {
                    // quota exceeded, every further request would fail as well
                    break;
                }
                continue;
            }

            $status = $result->getIndexStatusResult();
            $verdict = $status ? $status->getVerdict() : null;
            $report['inspected']++;
            if ($verdict !== 'PASS') {
                $report['notIndexed'][] = [
                    'url' => $url,
                    'verdict' => $verdict,
                    'coverageState' => $status ? $status->getCoverageState() : null,
                    'lastCrawlTime' => $status ? $status->getLastCrawlTime() : null,
                    'inspectionResultLink' => $result->getInspectionResultLink(),
                ];
            }
            $onProgress && $onProgress($url, $verdict, null);
        }

        $this->reportCache->set(sha1($property), $report, [], 0);

        return $report;
    }

    /**
     * @return array{generatedAt: int, inspected: int, errors: int, notIndexed: array}|null
     */
    public function report(string $property): ?array
    {
        return $this->reportCache->get(sha1($property)) ?: null;
    }

    /**
     * @return string[]
     */
    public function sitemapUrls(string $property, int $limit): array
    {
        $httpClient = $this->httpClient ?? new Client(['timeout' => 30]);
        $queue = array_map(static fn (WmxSitemap $sitemap) => $sitemap->getPath(), $this->sitemaps($property));
        $seen = [];
        $urls = [];

        // shortcut: gzipped sitemaps are skipped, add decoding once a site actually submits one
        while ($queue !== [] && count($urls) < $limit) {
            $path = array_shift($queue);
            if (isset($seen[$path])) {
                continue;
            }
            $seen[$path] = true;

            $xml = @simplexml_load_string((string)$httpClient->request('GET', $path)->getBody(), options: LIBXML_NONET);
            if ($xml === false) {
                continue;
            }
            foreach ($xml->sitemap as $child) {
                $queue[] = trim((string)$child->loc);
            }
            foreach ($xml->url as $url) {
                $urls[trim((string)$url->loc)] = true;
            }
        }

        return array_slice(array_keys($urls), 0, $limit);
    }

    /**
     * @return WmxSitemap[]
     */
    private function sitemaps(string $property): array
    {
        return $this->inspectionService->sitemaps->listSitemaps($property)->getSitemap() ?? [];
    }
}
