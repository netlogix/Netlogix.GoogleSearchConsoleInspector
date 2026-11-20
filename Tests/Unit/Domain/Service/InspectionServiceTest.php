<?php
declare(strict_types=1);

namespace Netlogix\GoogleSearchConsoleInspector\Tests\Unit\Domain\Service;

use Google\Client;
use Google\Service\SearchConsole\ApiDataRow;
use Google\Service\SearchConsole\InspectUrlIndexRequest;
use Google\Service\SearchConsole\InspectUrlIndexResponse;
use Google\Service\SearchConsole\Resource\UrlInspectionIndex;
use Google\Service\SearchConsole\UrlInspectionResult;
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

    /**
     * @test
     */
    public function inspectUriSendsTheLanguageAndCachesPerLanguage(): void
    {
        $languages = [];
        $inspector = $this->createMock(UrlInspectionIndex::class);
        $inspector->method('inspect')->willReturnCallback(
            function (InspectUrlIndexRequest $request) use (&$languages) {
                $languages[] = $request->getLanguageCode();
                $response = new InspectUrlIndexResponse();
                $response->setInspectionResult(new UrlInspectionResult());
                return $response;
            }
        );
        $this->service->urlInspection_index = $inspector;
        $uri = new Uri('https://www.example.org/foo');

        $this->service->inspectUri($uri, false, 'de');
        $this->service->inspectUri($uri, false, 'de');
        $this->service->inspectUri($uri);

        self::assertSame(['de', 'en-US'], $languages);
    }

    /**
     * @test
     */
    public function contentHintsFindQueriesCloseToTheFirstPage(): void
    {
        $hints = $this->service->contentHints([
            self::query('top', 100, 2.0),
            self::query('close', 50, 8.0),
            self::query('closer', 80, 20.0),
            self::query('far', 90, 20.1),
            self::query('rare', 9, 12.0),
        ], '');

        self::assertSame(['closer', 'close'], array_column($hints['strikingDistance'], 'query'));
    }

    /**
     * @test
     */
    public function contentHintsFindQueriesMissingInThePageText(): void
    {
        $hints = $this->service->contentHints([
            self::query('skiclub spies', 40, 1.0),
            self::query('Skilift Spies', 30, 5.0),
            self::query('langlauf', 20, 5.0),
            self::query('rare words', 9, 5.0),
        ], 'Skiclub SPIES e.V. - Langlauf und mehr');

        self::assertSame(['Skilift Spies'], array_column($hints['missingInText'], 'query'));
    }

    /**
     * @test
     */
    public function contentHintsAreLimitedToTheQueriesWithTheMostImpressions(): void
    {
        $queries = array_map(static fn (int $i) => self::query('q' . $i, 10 + $i, 10.0), range(1, 8));

        $hints = $this->service->contentHints($queries, '');

        self::assertSame(['q8', 'q7', 'q6', 'q5', 'q4'], array_column($hints['strikingDistance'], 'query'));
        self::assertCount(5, $hints['missingInText']);
    }

    private static function query(string $query, int $impressions, float $position): array
    {
        return ['query' => $query, 'clicks' => 0, 'impressions' => $impressions, 'position' => $position];
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
