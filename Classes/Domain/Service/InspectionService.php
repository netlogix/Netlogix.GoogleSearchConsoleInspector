<?php
declare(strict_types=1);

namespace Netlogix\GoogleSearchConsoleInspector\Domain\Service;

use Google\Client;
use Google\Service\SearchConsole;
use GuzzleHttp\Psr7\Uri;
use Neos\Cache\Frontend\VariableFrontend;
use Neos\Flow\Annotations as Flow;
use Netlogix\GoogleSearchConsoleInspector\Exception\NoSiteUrlFoundForUri;

/**
 * @Flow\Scope("singleton")
 */
final class InspectionService extends SearchConsole
{
    /**
     * @var array
     * @Flow\InjectConfiguration(path="siteUrlMapping")
     */
    protected array $siteUrlMapping = [];

    /**
     * @var VariableFrontend
     */
    protected $cache;

    public function __construct(Client $client)
    {
        parent::__construct($client);
    }

    public function inspectUri(Uri $uri, bool $force = false): SearchConsole\UrlInspectionResult
    {
        $cacheIdentifier = self::cacheIdentifier($uri);
        if ($this->cache->has($cacheIdentifier) && !$force) {
            return $this->cache->get($cacheIdentifier);
        }

        $request = new SearchConsole\InspectUrlIndexRequest();

        [$siteUrl, $inspectionUri] = $this->resolveSiteUrlAndInspectionUri($uri);

        $request->setInspectionUrl((string)$inspectionUri);
        $request->setSiteUrl($siteUrl);
        // TODO: use language of current user
        $request->setLanguageCode('en-US');

        $inspector = $this->urlInspection_index;
        assert($inspector instanceof SearchConsole\Resource\UrlInspectionIndex);
        $response = $inspector->inspect($request);
        $result = $response->getInspectionResult();
        $this->cache->set($cacheIdentifier, $result, [sha1($siteUrl)]);

        return $result;
    }

    /**
     * Search performance of a single page for the last 28 days, compared to the 28 days before.
     *
     * @return array{startDate: string, endDate: string, current: array, previous: array, queries: array}
     */
    public function pagePerformance(Uri $uri, bool $force = false): array
    {
        $cacheIdentifier = sha1('performance' . $uri);
        if ($this->cache->has($cacheIdentifier) && !$force) {
            return $this->cache->get($cacheIdentifier);
        }

        [$siteUrl, $pageUri] = $this->resolveSiteUrlAndInspectionUri($uri);

        // Search Console data lags two to three days behind
        $end = new \DateTimeImmutable('-3 days');
        $start = $end->modify('-27 days');
        $previousStart = $start->modify('-28 days');

        $sums = array_fill_keys(['current', 'previous'], ['clicks' => 0, 'impressions' => 0, 'position' => 0.0]);
        foreach ($this->queryPage($siteUrl, $pageUri, 'date', $previousStart, $end, 56) as $row) {
            $period = $row->getKeys()[0] >= $start->format('Y-m-d') ? 'current' : 'previous';
            $sums[$period]['clicks'] += (int)$row->getClicks();
            $sums[$period]['impressions'] += (int)$row->getImpressions();
            $sums[$period]['position'] += $row->getPosition() * $row->getImpressions();
        }

        $totals = array_map(static fn (array $sum) => [
            'clicks' => $sum['clicks'],
            'impressions' => $sum['impressions'],
            'ctr' => $sum['impressions'] > 0 ? $sum['clicks'] / $sum['impressions'] : null,
            'position' => $sum['impressions'] > 0 ? $sum['position'] / $sum['impressions'] : null,
        ], $sums);

        $queries = array_map(static fn (SearchConsole\ApiDataRow $row) => [
            'query' => $row->getKeys()[0],
            'clicks' => (int)$row->getClicks(),
            'impressions' => (int)$row->getImpressions(),
            'position' => $row->getPosition(),
        ], $this->queryPage($siteUrl, $pageUri, 'query', $start, $end, 10));

        // the API only sorts by clicks, which leaves the many zero-click queries unordered
        usort($queries, static fn (array $a, array $b) => [$b['clicks'], $b['impressions']] <=> [$a['clicks'], $a['impressions']]);

        $result = [
            'startDate' => $start->format('Y-m-d'),
            'endDate' => $end->format('Y-m-d'),
            'current' => $totals['current'],
            'previous' => $totals['previous'],
            'queries' => $queries,
        ];
        $this->cache->set($cacheIdentifier, $result, [sha1($siteUrl)]);

        return $result;
    }

    /**
     * @return SearchConsole\ApiDataRow[]
     */
    private function queryPage(
        string $siteUrl,
        Uri $pageUri,
        string $dimension,
        \DateTimeImmutable $start,
        \DateTimeImmutable $end,
        int $rowLimit
    ): array {
        $filter = new SearchConsole\ApiDimensionFilter();
        $filter->setDimension('page');
        $filter->setOperator('equals');
        $filter->setExpression((string)$pageUri);
        $filterGroup = new SearchConsole\ApiDimensionFilterGroup();
        $filterGroup->setFilters([$filter]);

        $request = new SearchConsole\SearchAnalyticsQueryRequest();
        $request->setStartDate($start->format('Y-m-d'));
        $request->setEndDate($end->format('Y-m-d'));
        $request->setDimensions([$dimension]);
        $request->setDimensionFilterGroups([$filterGroup]);
        $request->setRowLimit($rowLimit);

        return $this->searchanalytics->query($siteUrl, $request)->getRows() ?? [];
    }

    /**
     * Resolves the Search Console property for the given URI and rewrites the URI onto the
     * property's canonical (first-listed) URL prefix. Additional prefixes (e.g. a local dev
     * domain) are only used to recognize which property a URL belongs to - the Search Console
     * API rejects an inspectionUrl that isn't actually part of the given siteUrl property, so
     * a dev-domain URL must never be sent to Google as-is.
     *
     * @return array{0: string, 1: Uri}
     */
    private function resolveSiteUrlAndInspectionUri(Uri $uri): array
    {
        foreach ($this->siteUrlMapping as $property => $urlPrefixes) {
            foreach ($urlPrefixes as $urlPrefix) {
                if (strpos((string)$uri, $urlPrefix) === 0) {
                    $canonicalPrefix = reset($urlPrefixes);
                    $inspectionUri = $canonicalPrefix . substr((string)$uri, strlen($urlPrefix));
                    return [$property, new Uri($inspectionUri)];
                }
            }
        }

        throw new NoSiteUrlFoundForUri(
            sprintf('No siteUrl for the search console property could be found for the URL "%s"', $uri),
            1662829443
        );
    }

    private static function cacheIdentifier(Uri $uri): string
    {
        return sha1((string)$uri);
    }

}
