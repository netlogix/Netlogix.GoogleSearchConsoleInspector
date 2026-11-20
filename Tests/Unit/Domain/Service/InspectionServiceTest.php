<?php
declare(strict_types=1);

namespace Netlogix\GoogleSearchConsoleInspector\Tests\Unit\Domain\Service;

use Google\Client;
use Google\Service\SearchConsole\ApiDataRow;
use Google\Service\SearchConsole\Resource\Searchanalytics;
use Google\Service\SearchConsole\SearchAnalyticsQueryRequest;
use Google\Service\SearchConsole\SearchAnalyticsQueryResponse;
use GuzzleHttp\Psr7\Uri;
use Neos\Cache\Backend\TransientMemoryBackend;
use Neos\Cache\EnvironmentConfiguration;
use Neos\Cache\Frontend\VariableFrontend;
use Neos\Flow\Tests\UnitTestCase;
use Netlogix\GoogleSearchConsoleInspector\Domain\Service\InspectionService;

class InspectionServiceTest extends UnitTestCase
{
    private InspectionService $service;

    /** @var array<int, array{0: string, 1: SearchAnalyticsQueryRequest}> */
    private array $queries = [];

    private array $rowsByDimension = [];

    protected function setUp(): void
    {
        parent::setUp();

        $searchanalytics = $this->createMock(Searchanalytics::class);
        $searchanalytics->method('query')->willReturnCallback(
            function (string $siteUrl, SearchAnalyticsQueryRequest $request) {
                $this->queries[] = [$siteUrl, $request];
                $response = new SearchAnalyticsQueryResponse();
                $response->setRows($this->rowsByDimension[$request->getDimensions()[0]] ?? []);
                return $response;
            }
        );

        $this->service = new InspectionService(new Client());
        $this->service->searchanalytics = $searchanalytics;
        $this->inject($this->service, 'siteUrlMapping', [
            'sc-domain:example.org' => ['https://www.example.org', 'https://example.dev.local'],
        ]);
        $backend = new TransientMemoryBackend(new EnvironmentConfiguration('test', sys_get_temp_dir()));
        $cache = new VariableFrontend('test', $backend);
        $backend->setCache($cache);
        $this->inject($this->service, 'cache', $cache);
    }

    /**
     * @test
     */
    public function pagePerformanceSplitsRowsIntoCurrentAndPreviousPeriod(): void
    {
        $this->rowsByDimension = [
            'date' => [
                self::row(date('Y-m-d', strtotime('-40 days')), 5, 100, 10.0),
                self::row(date('Y-m-d', strtotime('-10 days')), 2, 100, 4.0),
                self::row(date('Y-m-d', strtotime('-5 days')), 8, 300, 8.0),
            ],
            'query' => [
                self::row('skiclub', 7, 200, 3.5),
            ],
        ];

        $result = $this->service->pagePerformance(new Uri('https://www.example.org/foo'));

        self::assertSame(['clicks' => 10, 'impressions' => 400, 'ctr' => 0.025, 'position' => 7.0], $result['current']);
        self::assertSame(['clicks' => 5, 'impressions' => 100, 'ctr' => 0.05, 'position' => 10.0], $result['previous']);
        self::assertSame(
            [['query' => 'skiclub', 'clicks' => 7, 'impressions' => 200, 'position' => 3.5]],
            $result['queries']
        );
    }

    /**
     * @test
     */
    public function pagePerformanceWithoutRowsHasNoCtrAndPosition(): void
    {
        $result = $this->service->pagePerformance(new Uri('https://www.example.org/foo'));

        self::assertSame(['clicks' => 0, 'impressions' => 0, 'ctr' => null, 'position' => null], $result['current']);
        self::assertSame([], $result['queries']);
    }

    /**
     * @test
     */
    public function pagePerformanceQueriesThePropertyWithTheCanonicalUrl(): void
    {
        $this->service->pagePerformance(new Uri('https://example.dev.local/foo'));

        self::assertCount(2, $this->queries);
        foreach ($this->queries as [$siteUrl, $request]) {
            self::assertSame('sc-domain:example.org', $siteUrl);
            $filter = $request->getDimensionFilterGroups()[0]->getFilters()[0];
            self::assertSame('page', $filter->getDimension());
            self::assertSame('https://www.example.org/foo', $filter->getExpression());
        }
    }

    /**
     * @test
     */
    public function pagePerformanceIsCachedUnlessForced(): void
    {
        $uri = new Uri('https://www.example.org/foo');

        $this->service->pagePerformance($uri);
        $this->service->pagePerformance($uri);
        self::assertCount(2, $this->queries);

        $this->service->pagePerformance($uri, true);
        self::assertCount(4, $this->queries);
    }

    private static function row(string $key, int $clicks, int $impressions, float $position): ApiDataRow
    {
        $row = new ApiDataRow();
        $row->setKeys([$key]);
        $row->setClicks($clicks);
        $row->setImpressions($impressions);
        $row->setPosition($position);
        return $row;
    }
}
